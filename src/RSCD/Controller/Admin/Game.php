<?php

namespace RSCD\Controller\Admin;

use Illuminate\Database\Capsule\Manager as Capsule;
use RSCD\Model\GameData;
use RSCD\Model\Object\Session;
use RSCD\Model\Object\User;
use RSCD\Util\Dates;
use RSCD\Util\GameUsername;
use RSCD\Util\Strings;
use RSCD\Util\WorldControl;
use RSCD\View\AdminView;

/**
 * Game administration — every capability the legacy rscd-www admin had,
 * rebuilt on the game tables this application already owns.
 *
 * Pages (action = third URL segment, per the admin routing convention):
 *   GET  /admin/game/players/            character search and listing
 *   GET  /admin/game/player/id=N/        one character: profile, stats,
 *                                        inventory, bank, moderation panel
 *   GET  /admin/game/ip/user=N/          login history for a character
 *   GET  /admin/game/ip/?addr=A.B.C.D    characters seen from an address
 *   GET  /admin/game/reports/            in-game abuse report queue
 *   GET  /admin/game/report/user=H/      one character's open reports
 *   GET  /admin/game/traps/              cheat-trap log (read-only)
 *   GET  /admin/game/server/             world control and online players
 *
 * Moderation POSTs: ban unban mute unmute promote demote logout teleport
 * stats password delete account zap global alert update shutdown.
 *
 * Live actions (force logout, global message, alert, update, shutdown) go
 * through the login server's frontend socket (see Util\WorldControl); when
 * that socket is unreachable the database-backed capabilities keep working
 * and the live ones report the failure instead of pretending.
 *
 * Legacy parity notes: character bans, player-mod promotion, teleport
 * (Jail = 78, 1642), the 18-skill stat editor, IP statistics, report
 * zapping, trap review, and world control are all from the old punBB
 * admin; mute (the schema always had the flag) and owner-account
 * deactivation (the modern equivalent of the punBB ban table) are new.
 * Staff characters (game groups 1 and 2) are protected from moderation
 * here, as they were on the old site.
 */
class Game extends \RSCD\Controller\Admin\Common\ObjectController {

    /** Characters per listing page. */
    const PER_PAGE = 25;

    /** Game groups: 1 = admin, 2 = mod (Player.isAdmin/isMod). */
    const GROUP_STAFF = [1, 2];

    /** The jail tile, straight from the legacy admin panel. */
    const JAIL_X = 78;
    const JAIL_Y = 1642;

    /** Game password rules, matching the Account manager. */
    const PASSWORD_MIN   = \RSCD\Controller\Account::PASSWORD_MIN;
    const PASSWORD_MAX   = \RSCD\Controller\Account::PASSWORD_MAX;
    const PASSWORD_CHARS = \RSCD\Controller\Account::PASSWORD_CHARS;

    /**
     * The client's report-abuse reason codes, from the legacy report queue.
     */
    const REPORT_REASONS = [
        1  => 'Offensive language',
        2  => 'Item scamming',
        3  => 'Password scamming',
        4  => 'Bug abuse',
        5  => 'Staff impersonation',
        6  => 'Account sharing/trading',
        7  => 'Macroing',
        8  => 'Multiple logging in',
        9  => 'Encouraging others to break rules',
        10 => 'Misuse of customer support',
        11 => 'Advertising / website',
        12 => 'Real world item trading',
    ];

    /**
     * Access condition slugs this module consumes.
     *
     * @return string[]
     */
    public static function __conditions() {
        return [
            '_AdminConsole_Game_View',
            '_AdminConsole_Game_Moderate',
            '_AdminConsole_Game_Control',
        ];
    }

    /**
     * Bootstraps the controller and requires authentication.
     *
     * @return void
     */
    public function initialize() {
        $this->set('view', new AdminView($this->get('app')));
        $this->authorizeOrRedirect();
    }

    /**
     * Default action: the character listing.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    public function processDefaultAction($state) {
        $state->request->action = 'players';
        $state->request->method = 'GET';
        return $this->httpGetPlayers($state);
    }

    /* helpers */

    /**
     * Render content into the shared single-page admin template.
     *
     * @param mixed  $state   Application state object.
     * @param string $title   Page heading (escaped here).
     * @param string $content Safe HTML.
     * @return void
     */
    protected function renderPage($state, $title, $content) {
        $view = $this->get('view');
        $page = $view->getViewLayout('admin' . DIRECTORY_SEPARATOR . 'single-page.html');
        $page->populateHtmlFromFile();
        $page->injectHtml('page_title', Strings::displayText($title));
        $page->injectHtml('page_content', $content);
        $view->setPage($page->get('html'), [], $title);
    }

    /**
     * Flash alerts from ?msg= / ?err=.
     *
     * @param mixed $state Application state object.
     * @return string Safe HTML.
     */
    protected function buildAlertsHtml($state) {
        $html = '';
        if(($msg = $state->url->getVariable('msg')) !== null) {
            $html .= '<div class="alert alert-success">' . Strings::displayText((string)$msg) . '</div>';
        }
        if(($err = $state->url->getVariable('err')) !== null) {
            $html .= '<div class="alert alert-danger">' . Strings::displayText((string)$err) . '</div>';
        }
        return $html;
    }

    /**
     * Redirect to an admin game page with a flash message.
     *
     * @param mixed  $state Application state object.
     * @param string $path  Path under the base URL.
     * @param string $key   'msg' or 'err'.
     * @param string $text  Message text.
     * @return void
     */
    protected function flash($state, $path, $key, $text) {
        return $state->app->redirect($state->url->getBaseUrl() . $path . '?' . http_build_query([$key => $text]));
    }

    /**
     * Load a character row by id.
     *
     * @param mixed $playerId Character row id.
     * @return object The rscd_players row.
     * @throws \Exception When no such character exists.
     */
    protected function loadPlayer($playerId) {
        $player = (int)$playerId > 0
            ? Capsule::connection('game')->table('rscd_players')->where('id', (int)$playerId)->first()
            : null;
        if(empty($player->id)) {
            throw new \Exception('No such character.');
        }
        return $player;
    }

    /**
     * Load a character for a moderation action, refusing staff characters —
     * the same protection the legacy admin enforced.
     *
     * @param mixed $playerId Character row id.
     * @return object The rscd_players row.
     * @throws \Exception When missing or a staff character.
     */
    protected function loadPlayerForModeration($playerId) {
        $player = $this->loadPlayer($playerId);
        if(in_array((int)$player->group_id, static::GROUP_STAFF, true)) {
            throw new \Exception('Staff characters cannot be moderated from here.');
        }
        return $player;
    }

    /**
     * Best-effort force logout over the control socket, with an honest
     * suffix for the flash message when it does not work.
     *
     * @param mixed  $state  Application state object.
     * @param object $player rscd_players row.
     * @return string '' on success or when offline; a warning suffix otherwise.
     */
    protected function tryLogout($state, $player) {
        if(!$player->online && !$player->loggedin) {
            return '';
        }
        if(WorldControl::fromConfig($state)->logout($player->user)) {
            return ' The character was logged out.';
        }
        return ' Warning: the character is logged in but the world control'
            . ' socket is unreachable, so the game server may overwrite this'
            . ' change when it saves. Log the character out and repeat if needed.';
    }

    /**
     * One-word status for a character row.
     *
     * @param object $player rscd_players row.
     * @return string
     */
    protected function statusLabel($player) {
        if($player->banned) {
            return 'Banned';
        }
        if(in_array((int)$player->group_id, static::GROUP_STAFF, true)) {
            return 'Staff';
        }
        if($player->playermod) {
            return 'Player Mod';
        }
        if($player->muted) {
            return 'Muted';
        }
        return ($player->online || $player->loggedin) ? 'Online' : 'OK';
    }

    /**
     * Escaped link to a character's admin page.
     *
     * @param string $base   Escaped base URL.
     * @param object $player Row with id and username.
     * @return string Safe HTML.
     */
    protected function playerLink($base, $player) {
        return '<a href="' . $base . 'admin/game/player/id=' . (int)$player->id . '/">'
            . Strings::displayText((string)$player->username) . '</a>';
    }

    /* GET: characters */

    /**
     * Character search and listing.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpGetPlayers($state) {
        $this->isAllowedOrRedirect('_AdminConsole_Game_View');
        $state = $this->getState();
        $base = Strings::displayText($state->url->getBaseUrl());
        $query = trim((string)$state->url->getVariable('query'));
        $page = max(1, (int)$state->url->getVariable('page'));

        $builder = Capsule::connection('game')->table('rscd_players');
        if($query !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';
            $builder->where(function($where) use ($like) {
                $where->where('username', 'like', $like)
                      ->orWhere('owner_username', 'like', $like);
            });
        }
        $total = (clone $builder)->count();
        $pages = max(1, (int)ceil($total / static::PER_PAGE));
        $page = min($page, $pages);
        $players = $builder->orderBy('username')
            ->offset(($page - 1) * static::PER_PAGE)
            ->limit(static::PER_PAGE)
            ->get();

        $rows = '';
        foreach($players as $player) {
            $rows .= '<tr>'
                . '<td>' . $this->playerLink($base, $player) . '</td>'
                . '<td>' . Strings::displayText((string)$player->owner_username) . '</td>'
                . '<td>' . (int)$player->combat . '</td>'
                . '<td>' . (int)$player->skill_total . '</td>'
                . '<td>' . $this->statusLabel($player) . '</td>'
                . '<td>' . Dates::display($player->login_date, 'j M Y H:i', 'Never') . '</td>'
                . '</tr>';
        }
        if($rows === '') {
            $rows = '<tr><td colspan="6" class="listing-empty">No characters match.</td></tr>';
        }

        $pager = 'Page ' . $page . ' of ' . $pages . ' (' . $total . ' characters)';
        if($page > 1) {
            $pager = '<a href="' . $base . 'admin/game/players/?' . http_build_query(['query' => $query, 'page' => $page - 1]) . '">&laquo; Newer</a> ' . $pager;
        }
        if($page < $pages) {
            $pager .= ' <a href="' . $base . 'admin/game/players/?' . http_build_query(['query' => $query, 'page' => $page + 1]) . '">Older &raquo;</a>';
        }

        $content = $this->buildAlertsHtml($state)
            . '<p><a href="' . $base . 'admin/game/reports/">Reports</a>'
            . ' &middot; <a href="' . $base . 'admin/game/traps/">Traps</a>'
            . ' &middot; <a href="' . $base . 'admin/game/server/">Server control</a></p>'
            . '<form class="listing-search" method="GET" action="' . $base . 'admin/game/players/">'
            . '<input type="text" name="query" value="' . Strings::displayText($query) . '" placeholder="Character or owner name" />'
            . '<button type="submit">Search</button>'
            . ($query !== '' ? ' <a href="' . $base . 'admin/game/players/">Clear</a>' : '')
            . '</form>'
            . '<table class="listing-table"><thead><tr>'
            . '<th>Character</th><th>Owner</th><th>Combat</th><th>Skill total</th><th>Status</th><th>Last login</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<p>' . $pager . '</p>';
        $this->renderPage($state, 'Game characters', $content);
    }

    /**
     * One character: profile, editable stats, inventory, bank, moderation.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpGetPlayer($state) {
        $this->isAllowedOrRedirect('_AdminConsole_Game_View');
        $state = $this->getState();
        $base = Strings::displayText($state->url->getBaseUrl());
        try {
            $player = $this->loadPlayer($state->url->getVariable('id'));
        }
        catch(\Exception $e) {
            return $this->flash($state, 'admin/game/players/', 'err', $this->getError($e));
        }

        $content = $this->buildAlertsHtml($state)
            . '<p><a href="' . $base . 'admin/game/players/">&laquo; All characters</a></p>'
            . $this->buildProfileHtml($state, $base, $player)
            . '<h2>Skills</h2>' . $this->buildStatEditorHtml($base, $player)
            . '<h2>Quests</h2>' . $this->buildQuestsHtml($player)
            . '<h2>Inventory</h2>' . $this->buildItemsHtml($base, 'rscd_invitems', 'user', (string)$player->user, true)
            . '<h2>Bank (shared by the owner\'s characters)</h2>'
            . $this->buildItemsHtml($base, 'rscd_bank', 'owner', (string)$player->owner, false);
        if($this->isAllowed('_AdminConsole_Game_Moderate')) {
            $content .= '<h2>Moderation</h2>' . $this->buildModerationHtml($state, $base, $player);
        }
        $this->renderPage($state, 'Character: ' . $player->username, $content);
    }

    /**
     * The profile summary table.
     *
     * @param mixed  $state  Application state object.
     * @param string $base   Escaped base URL.
     * @param object $player rscd_players row.
     * @return string Safe HTML.
     */
    protected function buildProfileHtml($state, $base, $player) {
        $kills = Capsule::connection('game')->table('rscd_kills')->where('user', (string)$player->user)->distinct()->count('killed');
        $owner = User::where('id', (int)$player->owner)->first();
        $ipLink = function($ip) use ($base) {
            $safe = Strings::displayText((string)$ip);
            return '<a href="' . $base . 'admin/game/ip/?addr=' . rawurlencode((string)$ip) . '">' . $safe . '</a>';
        };
        $rows = [
            'Status'      => $this->statusLabel($player),
            'Owner'       => Strings::displayText((string)$player->owner_username)
                . (!empty($owner->email_address) ? ' &lt;' . Strings::displayText((string)$owner->email_address) . '&gt;' : ''),
            'Combat'      => (int)$player->combat,
            'Skill total' => (int)$player->skill_total,
            'Position'    => '(' . (int)$player->x . ', ' . (int)$player->y . ') on world ' . (int)$player->world,
            'Fatigue'     => (int)$player->fatigue . '%',
            'Kills (unique)' => (int)$kills . ', deaths: ' . (int)$player->deaths,
            'Created'     => Dates::display($player->creation_date, 'j M Y H:i', 'Unknown')
                . ' from ' . $ipLink($player->creation_ip),
            'Last login'  => Dates::display($player->login_date, 'j M Y H:i', 'Never')
                . ($player->login_ip !== '0.0.0.0' ? ' from ' . $ipLink($player->login_ip) : ''),
        ];
        $html = '<table class="listing-table">';
        foreach($rows as $label => $value) {
            $html .= '<tr><th>' . $label . '</th><td>' . $value . '</td></tr>';
        }
        return $html . '</table>'
            . '<p><a href="' . $base . 'admin/game/ip/user=' . (int)$player->id . '/">Login history (IP statistics)</a></p>';
    }

    /**
     * The 18-skill table with per-skill level inputs — the stat editor.
     *
     * @param string $base   Escaped base URL.
     * @param object $player rscd_players row.
     * @return string Safe HTML.
     */
    protected function buildStatEditorHtml($base, $player) {
        $stats = Capsule::connection('game')->table('rscd_curstats')->where('user', (string)$player->user)->first();
        $exps  = Capsule::connection('game')->table('rscd_experience')->where('user', (string)$player->user)->first();
        $canEdit = $this->isAllowed('_AdminConsole_Game_Moderate')
            && !in_array((int)$player->group_id, static::GROUP_STAFF, true);

        $rows = '';
        foreach(GameData::SKILLS as $key => $label) {
            $experience = (int)($exps->{'exp_' . $key} ?? 0);
            $current    = (int)($stats->{'cur_' . $key} ?? 0);
            $level      = GameData::experienceToLevel($experience);
            $rows .= '<tr><td><img class="skill-icon" src="' . $base . 'ui/img/skills/' . $key . '.png" alt="" /> ' . $label . '</td>'
                . '<td>' . ($canEdit
                    ? '<input type="number" name="level_' . $key . '" value="' . $level . '" min="1" max="99" style="width:5em" />'
                    : $level) . '</td>'
                . '<td>' . $current . '</td>'
                . '<td>' . number_format(GameData::displayExperience($experience)) . '</td></tr>';
        }
        $table = '<table class="listing-table"><thead><tr>'
            . '<th>Skill</th><th>Level</th><th>Current</th><th>Experience</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
        if(!$canEdit) {
            return $table;
        }
        return '<form method="POST" action="' . $base . 'admin/game/stats/">'
            . '<input type="hidden" name="id" value="' . (int)$player->id . '" />'
            . $table
            . '<p>Changed levels are written with the matching experience floor,'
            . ' and the character is logged out so the game reloads them.</p>'
            . '<button class="btn" type="submit">Save changed levels</button>'
            . '</form>';
    }

    /**
     * Quest progress summary.
     *
     * @param object $player rscd_players row.
     * @return string Safe HTML.
     */
    protected function buildQuestsHtml($player) {
        $stages = [];
        foreach(Capsule::connection('game')->table('rscd_quests')->where('user', (string)$player->user)->get() as $row) {
            $stages[(int)$row->quest] = (int)$row->stage;
        }
        $completed = 0;
        $started = 0;
        foreach(GameData::questData() as $questId => $quest) {
            $stage = $stages[$questId] ?? -1;
            if(in_array($stage, $quest['final'], true)) {
                $completed++;
            }
            else if($stage > -1) {
                $started++;
            }
        }
        return '<p>Completed <b>' . $completed . '</b> of ' . count(GameData::questData())
            . ' quests, ' . $started . ' in progress.</p>';
    }

    /**
     * Inventory or bank listing.
     *
     * @param string $base        Escaped base URL.
     * @param string $table       rscd_invitems or rscd_bank.
     * @param string $keyColumn   'user' or 'owner'.
     * @param string $keyValue    Hash or owner id.
     * @param bool   $showWielded Whether to mark wielded items.
     * @return string Safe HTML.
     */
    protected function buildItemsHtml($base, $table, $keyColumn, $keyValue, $showWielded) {
        $items = Capsule::connection('game')->table($table)->where($keyColumn, $keyValue)->orderBy('slot')->get();
        if(count($items) === 0) {
            return '<p>Empty.</p>';
        }
        $rows = '';
        foreach($items as $item) {
            $sprite = is_file(__ROOTS__ . 'ui' . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'items' . DIRECTORY_SEPARATOR . (int)$item->id . '.png')
                ? '<img class="item-sprite" src="' . $base . 'ui/img/items/' . (int)$item->id . '.png" alt="" />'
                : '';
            $rows .= '<tr><td class="item-sprite-cell">' . $sprite . '</td>'
                . '<td>' . Strings::displayText(GameData::itemName((int)$item->id))
                . ($showWielded && !empty($item->wielded) ? ' <i>(wielded)</i>' : '')
                . '</td><td>' . number_format((int)$item->amount) . '</td></tr>';
        }
        return '<table class="listing-table"><thead><tr><th></th><th>Item</th><th>Amount</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>';
    }

    /**
     * The moderation panel: every action the legacy admin offered.
     *
     * @param mixed  $state  Application state object.
     * @param string $base   Escaped base URL.
     * @param object $player rscd_players row.
     * @return string Safe HTML.
     */
    protected function buildModerationHtml($state, $base, $player) {
        if(in_array((int)$player->group_id, static::GROUP_STAFF, true)) {
            return '<p>This is a staff character; it cannot be moderated from here.</p>';
        }
        $id = (int)$player->id;
        $button = function($action, $label, $extra = '') use ($base, $id) {
            return '<form method="POST" action="' . $base . 'admin/game/' . $action . '/" style="display:inline">'
                . '<input type="hidden" name="id" value="' . $id . '" />' . $extra
                . '<button class="btn" type="submit">' . $label . '</button></form> ';
        };

        $html = '<p>'
            . $button($player->banned ? 'unban' : 'ban', $player->banned ? 'Unban character' : 'Ban character')
            . $button($player->muted ? 'unmute' : 'mute', $player->muted ? 'Unmute' : 'Mute')
            . $button($player->playermod ? 'demote' : 'promote', $player->playermod ? 'Demote from Player Mod' : 'Promote to Player Mod')
            . $button('logout', 'Force logout')
            . '</p>'
            . '<p>Bans apply to this character only; the owner\'s site account and other characters are unaffected.</p>';

        $html .= '<h3>Teleport</h3>'
            . '<form method="POST" action="' . $base . 'admin/game/teleport/">'
            . '<input type="hidden" name="id" value="' . $id . '" />'
            . 'X: <input type="number" name="x" value="' . (int)$player->x . '" min="0" max="4000" style="width:6em" /> '
            . 'Y: <input type="number" name="y" value="' . (int)$player->y . '" min="0" max="4000" style="width:6em" /> '
            . '<button class="btn" type="submit">Move character</button>'
            . ' <span>(Jail = ' . static::JAIL_X . ', ' . static::JAIL_Y . ')</span>'
            . '</form>';

        $html .= '<h3>Game password</h3>'
            . '<form method="POST" action="' . $base . 'admin/game/password/">'
            . '<input type="hidden" name="id" value="' . $id . '" />'
            . '<input type="password" name="password" placeholder="New password" autocomplete="new-password" /> '
            . '<input type="password" name="confirm_password" placeholder="Confirm" autocomplete="new-password" /> '
            . '<button class="btn" type="submit">Set password</button>'
            . '</form>';

        $owner = User::where('id', (int)$player->owner)->first();
        $html .= '<h3>Owner site account</h3>';
        if(!empty($owner->id)) {
            $isInactive = (int)$owner->status === User::STATUS_INACTIVE;
            $html .= '<form method="POST" action="' . $base . 'admin/game/account/">'
                . '<input type="hidden" name="id" value="' . $id . '" />'
                . '<input type="hidden" name="to" value="' . ($isInactive ? 'reactivate' : 'deactivate') . '" />'
                . '<button class="btn" type="submit">' . ($isInactive ? 'Reactivate' : 'Deactivate') . ' the owner\'s site account</button>'
                . '</form>'
                . '<p>Deactivating blocks sign-in to the website (and so to every character'
                . ' the account owns) and ends the owner\'s active sessions — the modern'
                . ' equivalent of the old forum-account ban.</p>';
        }
        else {
            $html .= '<p>No site account found for this owner id.</p>';
        }

        $html .= '<h3 class="danger-zone">Delete character</h3>'
            . '<form method="POST" action="' . $base . 'admin/game/delete/">'
            . '<input type="hidden" name="id" value="' . $id . '" />'
            . '<input type="text" name="confirm_name" placeholder="Type the character name" /> '
            . '<button class="btn-danger" type="submit">Delete this character</button>'
            . '</form>';
        return $html;
    }

    /* GET: IP statistics */

    /**
     * IP statistics: a character's login history, or every character seen
     * from one address.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpGetIp($state) {
        $this->isAllowedOrRedirect('_AdminConsole_Game_View');
        $state = $this->getState();
        $base = Strings::displayText($state->url->getBaseUrl());

        $addr = trim((string)$state->url->getVariable('addr'));
        if($addr !== '') {
            $hashes = Capsule::connection('game')->table('rscd_logins')->where('ip', $addr)->distinct()->pluck('user')->all();
            $players = Capsule::connection('game')->table('rscd_players')
                ->where(function($where) use ($hashes, $addr) {
                    $where->whereIn('user', !empty($hashes) ? $hashes : ['-'])
                          ->orWhere('creation_ip', $addr)
                          ->orWhere('login_ip', $addr);
                })
                ->orderBy('username')->get();
            $rows = '';
            foreach($players as $player) {
                $rows .= '<tr>'
                    . '<td>' . $this->playerLink($base, $player) . '</td>'
                    . '<td>' . Strings::displayText((string)$player->owner_username) . '</td>'
                    . '<td>' . $this->statusLabel($player) . '</td>'
                    . '</tr>';
            }
            if($rows === '') {
                $rows = '<tr><td colspan="3" class="listing-empty">No characters seen from this address.</td></tr>';
            }
            $content = '<p><a href="' . $base . 'admin/game/players/">&laquo; All characters</a></p>'
                . '<table class="listing-table"><thead><tr><th>Character</th><th>Owner</th><th>Status</th></tr></thead>'
                . '<tbody>' . $rows . '</tbody></table>';
            return $this->renderPage($state, 'Characters seen from ' . $addr, $content);
        }

        try {
            $player = $this->loadPlayer($state->url->getVariable('user'));
        }
        catch(\Exception $e) {
            return $this->flash($state, 'admin/game/players/', 'err', $this->getError($e));
        }
        $logins = Capsule::connection('game')->table('rscd_logins')
            ->selectRaw('ip, MAX(time) AS last_used, COUNT(*) AS used_times')
            ->where('user', (string)$player->user)
            ->groupBy('ip')->orderByDesc('last_used')->get();
        $rows = '';
        foreach($logins as $login) {
            $safe = Strings::displayText((string)$login->ip);
            $rows .= '<tr>'
                . '<td><a href="' . $base . 'admin/game/ip/?addr=' . rawurlencode((string)$login->ip) . '">' . $safe . '</a></td>'
                . '<td>' . Dates::display($login->last_used, 'j M Y H:i') . '</td>'
                . '<td>' . (int)$login->used_times . '</td>'
                . '</tr>';
        }
        if($rows === '') {
            $rows = '<tr><td colspan="3" class="listing-empty">No logins recorded for this character.</td></tr>';
        }
        $content = '<p><a href="' . $base . 'admin/game/player/id=' . (int)$player->id . '/">&laquo; Back to '
            . Strings::displayText((string)$player->username) . '</a></p>'
            . '<table class="listing-table"><thead><tr><th>IP address</th><th>Last used</th><th>Logins</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>';
        $this->renderPage($state, 'Login history: ' . $player->username, $content);
    }

    /* GET: reports and traps */

    /**
     * The abuse report queue, grouped by reported character.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpGetReports($state) {
        $this->isAllowedOrRedirect('_AdminConsole_Game_View');
        $state = $this->getState();
        $base = Strings::displayText($state->url->getBaseUrl());

        $reports = Capsule::connection('game')->table('rscd_reports')
            ->selectRaw('about, COUNT(*) AS offences, MAX(time) AS last_time')
            ->where('zapped', 0)
            ->groupBy('about')->orderByDesc('last_time')->get();
        $rows = '';
        foreach($reports as $report) {
            $player = Capsule::connection('game')->table('rscd_players')->where('user', (string)$report->about)->first();
            $name = !empty($player->id)
                ? $this->playerLink($base, $player)
                : Strings::displayText(GameUsername::decode($report->about)) . ' <i>(deleted)</i>';
            $rows .= '<tr>'
                . '<td>' . $name . '</td>'
                . '<td>' . (!empty($player->id) ? Strings::displayText((string)$player->owner_username) : '-') . '</td>'
                . '<td>' . (int)$report->offences . '</td>'
                . '<td>' . (!empty($player->id) ? $this->statusLabel($player) : '-') . '</td>'
                . '<td>' . Dates::display($report->last_time, 'j M Y H:i') . '</td>'
                . '<td><a href="' . $base . 'admin/game/report/user=' . rawurlencode((string)$report->about) . '/">Details</a></td>'
                . '</tr>';
        }
        if($rows === '') {
            $rows = '<tr><td colspan="6" class="listing-empty">There are no new reports.</td></tr>';
        }
        $content = $this->buildAlertsHtml($state)
            . '<p><a href="' . $base . 'admin/game/players/">&laquo; Characters</a></p>'
            . '<table class="listing-table"><thead><tr>'
            . '<th>Character</th><th>Owner</th><th>Open reports</th><th>Status</th><th>Last reported</th><th></th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
        $this->renderPage($state, 'Abuse reports', $content);
    }

    /**
     * One character's open reports, with zap controls.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpGetReport($state) {
        $this->isAllowedOrRedirect('_AdminConsole_Game_View');
        $state = $this->getState();
        $base = Strings::displayText($state->url->getBaseUrl());
        $about = trim((string)$state->url->getVariable('user'));
        if($about === '' || !ctype_digit($about)) {
            return $this->flash($state, 'admin/game/reports/', 'err', 'Bad request.');
        }
        $canZap = $this->isAllowed('_AdminConsole_Game_Moderate');

        $reports = Capsule::connection('game')->table('rscd_reports')
            ->where('zapped', 0)->where('about', $about)
            ->orderByDesc('time')->get();
        $blocks = '';
        foreach($reports as $report) {
            $from = Capsule::connection('game')->table('rscd_players')->where('user', (string)$report->from)->first();
            $fromName = !empty($from->id)
                ? $this->playerLink($base, $from)
                : Strings::displayText(GameUsername::decode($report->from));
            $reason = static::REPORT_REASONS[(int)$report->reason] ?? ('Unknown (ID: ' . (int)$report->reason . ')');
            $blocks .= '<table class="listing-table"><tbody>'
                . '<tr><th>Reported</th><td>' . Dates::display($report->time, 'j M Y H:i') . ' by ' . $fromName . '</td></tr>'
                . '<tr><th>Reason</th><td>' . Strings::displayText($reason) . '</td></tr>'
                . '<tr><th>Where</th><td>(' . (int)$report->x . ', ' . (int)$report->y . ')'
                . ($report->status !== '' ? ' while: ' . Strings::displayText((string)$report->status) : '') . '</td></tr>'
                . ($canZap
                    ? '<tr><th></th><td><form method="POST" action="' . $base . 'admin/game/zap/">'
                        . '<input type="hidden" name="report_id" value="' . (int)$report->id . '" />'
                        . '<button class="btn" type="submit">Zap this report</button></form></td></tr>'
                    : '')
                . '</tbody></table><br />';
        }
        if($blocks === '') {
            $blocks = '<p>No open reports for this character.</p>';
        }

        $player = Capsule::connection('game')->table('rscd_players')->where('user', $about)->first();
        $title = !empty($player->id) ? (string)$player->username : GameUsername::decode($about);
        $content = $this->buildAlertsHtml($state)
            . '<p><a href="' . $base . 'admin/game/reports/">&laquo; Report queue</a>'
            . (!empty($player->id) ? ' &middot; ' . $this->playerLink($base, $player) : '') . '</p>'
            . $blocks;
        if($canZap && count($reports) > 0) {
            $content .= '<form method="POST" action="' . $base . 'admin/game/zap/">'
                . '<input type="hidden" name="about" value="' . Strings::displayText($about) . '" />'
                . '<label><input type="checkbox" name="confirm" value="1" /> I want to clear every report above</label> '
                . '<button class="btn" type="submit">Clear all</button>'
                . '</form>';
        }
        $this->renderPage($state, 'Reports about ' . $title, $content);
    }

    /**
     * The cheat-trap log. The current schema keeps one row per trapped
     * character with no zapped flag, so this page is read-only.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpGetTraps($state) {
        $this->isAllowedOrRedirect('_AdminConsole_Game_View');
        $state = $this->getState();
        $base = Strings::displayText($state->url->getBaseUrl());

        $traps = Capsule::connection('game')->table('rscd_traps')->orderByDesc('time')->get();
        $rows = '';
        foreach($traps as $trap) {
            $player = Capsule::connection('game')->table('rscd_players')->where('user', (string)$trap->user)->first();
            $name = !empty($player->id)
                ? $this->playerLink($base, $player)
                : Strings::displayText(GameUsername::decode($trap->user)) . ' <i>(deleted)</i>';
            $rows .= '<tr>'
                . '<td>' . $name . '</td>'
                . '<td>' . (!empty($player->id) ? $this->statusLabel($player) : '-') . '</td>'
                . '<td>' . Dates::display($trap->time, 'j M Y H:i') . '</td>'
                . '<td><a href="' . $base . 'admin/game/ip/?addr=' . rawurlencode((string)$trap->ip) . '">'
                    . Strings::displayText((string)$trap->ip) . '</a></td>'
                . '<td>' . Strings::displayText((string)$trap->details) . '</td>'
                . '</tr>';
        }
        if($rows === '') {
            $rows = '<tr><td colspan="5" class="listing-empty">No characters have hit a trap.</td></tr>';
        }
        $content = '<p><a href="' . $base . 'admin/game/players/">&laquo; Characters</a></p>'
            . '<table class="listing-table"><thead><tr>'
            . '<th>Character</th><th>Status</th><th>Caught</th><th>IP</th><th>Details</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
        $this->renderPage($state, 'Cheat traps', $content);
    }

    /* GET: server control */

    /**
     * World control: online players, global message, per-player alert,
     * update shutdown, graceful shutdown, and the server logs when their
     * directory is configured and readable.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpGetServer($state) {
        $this->isAllowedOrRedirect('_AdminConsole_Game_Control');
        $state = $this->getState();
        $base = Strings::displayText($state->url->getBaseUrl());
        $control = WorldControl::fromConfig($state);
        $reachable = $control->reachable();

        $content = $this->buildAlertsHtml($state)
            . '<p><a href="' . $base . 'admin/game/players/">&laquo; Characters</a></p>'
            . '<p>World control socket: ' . ($reachable
                ? '<b>connected</b>'
                : '<b>unreachable</b> — live actions below will fail until the'
                    . ' login server is running and the "gameServer" host/port'
                    . ' in the application config points at it') . '.</p>';

        // Online players, from the presence table the login server maintains.
        $online = Capsule::connection('game')->table('rscd_online')->orderBy('world')->orderBy('username')->get();
        $rows = '';
        foreach($online as $entry) {
            $player = Capsule::connection('game')->table('rscd_players')->where('user', (string)$entry->user)->first();
            $rows .= '<tr>'
                . '<td>' . (!empty($player->id)
                    ? $this->playerLink($base, $player)
                    : Strings::displayText((string)$entry->username)) . '</td>'
                . '<td>(' . (int)$entry->x . ', ' . (int)$entry->y . ')</td>'
                . '<td>' . (int)$entry->world . '</td>'
                . '</tr>';
        }
        if($rows === '') {
            $rows = '<tr><td colspan="3" class="listing-empty">Nobody is online.</td></tr>';
        }
        $content .= '<h2>Online players (' . count($online) . ')</h2>'
            . '<table class="listing-table"><thead><tr><th>Character</th><th>Position</th><th>World</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>';

        $content .= '<h2>Messages</h2>'
            . '<form method="POST" action="' . $base . 'admin/game/global/">'
            . '<input type="text" name="message" maxlength="200" style="width:24em" placeholder="Message for everyone online" /> '
            . '<button class="btn" type="submit">Send global message</button>'
            . '</form><br />'
            . '<form method="POST" action="' . $base . 'admin/game/alert/">'
            . '<input type="text" name="username" maxlength="12" placeholder="Character" /> '
            . '<input type="text" name="message" maxlength="200" style="width:18em" placeholder="Alert message" /> '
            . '<button class="btn" type="submit">Send alert</button>'
            . '</form>';

        $content .= '<h2>Shutdown</h2>'
            . '<form method="POST" action="' . $base . 'admin/game/update/">'
            . '<input type="text" name="message" maxlength="200" style="width:24em" placeholder="Update message (optional)" /> '
            . '<button class="btn" type="submit">Update</button>'
            . '<p>Warns everyone, then shuts the server down after 60 seconds.'
            . ' This is the right way to stop the server.</p>'
            . '</form>'
            . '<form method="POST" action="' . $base . 'admin/game/shutdown/">'
            . '<label><input type="checkbox" name="confirm" value="1" /> I want to stop the server now</label> '
            . '<button class="btn-danger" type="submit">Graceful shutdown</button>'
            . '<p>Saves all accounts and stops as soon as possible, with no warning to players.</p>'
            . '</form>';

        $content .= $this->buildLogsHtml($state);
        $this->renderPage($state, 'Server control', $content);
    }

    /**
     * The server log panels, shown only when the "gameLogDir" config
     * property names a readable directory (i.e. the site shares a host
     * with the game server, as the legacy admin assumed).
     *
     * @param mixed $state Application state object.
     * @return string Safe HTML, or '' when no logs are available.
     */
    protected function buildLogsHtml($state) {
        $dir = (string)$state->config->getProperty('gameLogDir');
        if($dir === '' || !is_dir($dir)) {
            return '';
        }
        $html = '';
        foreach(['event', 'error', 'mod'] as $name) {
            $path = rtrim($dir, '/') . '/' . $name . '.log';
            if(!is_readable($path)) {
                continue;
            }
            $lines = @file($path);
            $tail = $lines === false ? '' : implode('', array_slice($lines, -200));
            $html .= '<h3>' . ucfirst($name) . ' log (last 200 lines)</h3>'
                . '<textarea readonly style="width:100%;height:12em">'
                . Strings::displayText(trim($tail)) . '</textarea>';
        }
        return $html === '' ? '' : '<h2>Server logs</h2>' . $html;
    }

    /* POST: moderation */

    /**
     * Shared wrapper for the simple flag actions: load, guard, mutate,
     * best-effort logout, flash back to the character page.
     *
     * @param mixed    $state  Application state object.
     * @param callable $action function($player): string — mutates and
     *                         returns the success message.
     * @return void
     */
    protected function moderate($state, $action) {
        $this->isAllowedOrRedirect('_AdminConsole_Game_Moderate');
        $state = $this->getState();
        $playerId = (int)filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        try {
            $player = $this->loadPlayerForModeration($playerId);
            $message = $action($player);
        }
        catch(\Exception $e) {
            return $this->flash($state, $playerId > 0 ? 'admin/game/player/id=' . $playerId . '/' : 'admin/game/players/', 'err', $this->getError($e));
        }
        return $this->flash($state, 'admin/game/player/id=' . $playerId . '/', 'msg', $message);
    }

    /**
     * Ban a character (this character only, not the owner's account).
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpPostBan($state) {
        return $this->moderate($state, function($player) use ($state) {
            if($player->banned) {
                throw new \Exception('Already banned.');
            }
            Capsule::connection('game')->table('rscd_players')->where('id', (int)$player->id)->update(['banned' => 1]);
            return '"' . $player->username . '" has been banned.' . $this->tryLogout($state, $player);
        });
    }

    /**
     * Lift a character ban.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpPostUnban($state) {
        return $this->moderate($state, function($player) {
            if(!$player->banned) {
                throw new \Exception('Not banned.');
            }
            Capsule::connection('game')->table('rscd_players')->where('id', (int)$player->id)->update(['banned' => 0]);
            return '"' . $player->username . '" has been unbanned.';
        });
    }

    /**
     * Mute a character.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpPostMute($state) {
        return $this->moderate($state, function($player) use ($state) {
            if($player->muted) {
                throw new \Exception('Already muted.');
            }
            Capsule::connection('game')->table('rscd_players')->where('id', (int)$player->id)->update(['muted' => 1]);
            return '"' . $player->username . '" has been muted.' . $this->tryLogout($state, $player);
        });
    }

    /**
     * Unmute a character.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpPostUnmute($state) {
        return $this->moderate($state, function($player) use ($state) {
            if(!$player->muted) {
                throw new \Exception('Not muted.');
            }
            Capsule::connection('game')->table('rscd_players')->where('id', (int)$player->id)->update(['muted' => 0]);
            return '"' . $player->username . '" has been unmuted.' . $this->tryLogout($state, $player);
        });
    }

    /**
     * Promote a character to player moderator.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpPostPromote($state) {
        return $this->moderate($state, function($player) use ($state) {
            if($player->playermod) {
                throw new \Exception('Already a player mod.');
            }
            Capsule::connection('game')->table('rscd_players')->where('id', (int)$player->id)->update(['playermod' => 1]);
            return '"' . $player->username . '" is now a player mod.' . $this->tryLogout($state, $player);
        });
    }

    /**
     * Demote a character from player moderator.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpPostDemote($state) {
        return $this->moderate($state, function($player) use ($state) {
            if(!$player->playermod) {
                throw new \Exception('Not a player mod.');
            }
            Capsule::connection('game')->table('rscd_players')->where('id', (int)$player->id)->update(['playermod' => 0]);
            return '"' . $player->username . '" is no longer a player mod.' . $this->tryLogout($state, $player);
        });
    }

    /**
     * Force a character to log out, over the world control socket.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpPostLogout($state) {
        return $this->moderate($state, function($player) use ($state) {
            if(!$player->online && !$player->loggedin) {
                throw new \Exception('"' . $player->username . '" is not logged in.');
            }
            if(!WorldControl::fromConfig($state)->logout($player->user)) {
                throw new \Exception('The world control socket did not confirm the logout — is the login server running?');
            }
            return '"' . $player->username . '" has been logged out.';
        });
    }

    /**
     * Move a character to a tile (Jail = 78, 1642).
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpPostTeleport($state) {
        return $this->moderate($state, function($player) use ($state) {
            $x = (int)filter_input(INPUT_POST, 'x', FILTER_VALIDATE_INT);
            $y = (int)filter_input(INPUT_POST, 'y', FILTER_VALIDATE_INT);
            if($x < 0 || $x > 4000 || $y < 0 || $y > 4000) {
                throw new \Exception('Coordinates must be between 0 and 4000.');
            }
            Capsule::connection('game')->table('rscd_players')->where('id', (int)$player->id)->update(['x' => $x, 'y' => $y]);
            return '"' . $player->username . '" has been moved to (' . $x . ', ' . $y . ').' . $this->tryLogout($state, $player);
        });
    }

    /**
     * The stat editor: write changed levels with their experience floors.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpPostStats($state) {
        return $this->moderate($state, function($player) use ($state) {
            $exps = Capsule::connection('game')->table('rscd_experience')->where('user', (string)$player->user)->first();
            if(empty($exps)) {
                throw new \Exception('No experience row for this character.');
            }
            $statUpdates = [];
            $expUpdates = [];
            $changed = [];
            foreach(GameData::SKILLS as $key => $label) {
                $raw = filter_input(INPUT_POST, 'level_' . $key, FILTER_VALIDATE_INT);
                if($raw === null || $raw === false) {
                    continue;
                }
                $level = (int)$raw;
                if($level < 1 || $level > 99) {
                    throw new \Exception($label . ' must be between 1 and 99.');
                }
                if($level === GameData::experienceToLevel((int)$exps->{'exp_' . $key})) {
                    continue;
                }
                $statUpdates['cur_' . $key] = $level;
                $expUpdates['exp_' . $key] = GameData::experienceForLevel($level);
                $changed[] = $label . ' to ' . $level;
            }
            if(empty($changed)) {
                throw new \Exception('No levels were changed.');
            }
            Capsule::connection()->transaction(function() use ($player, $statUpdates, $expUpdates) {
                Capsule::connection('game')->table('rscd_curstats')->where('user', (string)$player->user)->update($statUpdates);
                Capsule::connection('game')->table('rscd_experience')->where('user', (string)$player->user)->update($expUpdates);
            });
            return 'Set ' . implode(', ', $changed) . ' for "' . $player->username . '".' . $this->tryLogout($state, $player);
        });
    }

    /**
     * Set a character's game password (admin variant of the owner's own
     * reset in the Account manager — MD5 hex is the RSC wire format).
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpPostPassword($state) {
        return $this->moderate($state, function($player) {
            $password = (string)filter_input(INPUT_POST, 'password',         FILTER_UNSAFE_RAW);
            $confirm  = (string)filter_input(INPUT_POST, 'confirm_password', FILTER_UNSAFE_RAW);
            if(strlen($password) < static::PASSWORD_MIN || strlen($password) > static::PASSWORD_MAX) {
                throw new \Exception('Game passwords must be ' . static::PASSWORD_MIN . ' to ' . static::PASSWORD_MAX . ' characters.');
            }
            if($password !== $confirm) {
                throw new \Exception('The password and confirmation must match.');
            }
            /* Same reason as Account::assertUsableGamePassword: a character the
               client cannot send back byte-for-byte, or edge whitespace that
               the game server trims off after the site has hashed it in, makes
               a password that is accepted here and rejected at the login
               screen. This path had no character check at all. */
            if(strspn($password, static::PASSWORD_CHARS) !== strlen($password)) {
                throw new \Exception('Game passwords may only contain letters, numbers, and the punctuation you can type on the game\'s login screen.');
            }
            if(trim($password) !== $password) {
                throw new \Exception('Game passwords may not begin or end with a space.');
            }
            Capsule::connection('game')->table('rscd_players')->where('id', (int)$player->id)->update(['pass' => md5($password)]);
            return 'The game password for "' . $player->username . '" has been changed.';
        });
    }

    /**
     * Delete a character and every row keyed on it — the same recipe the
     * Account manager uses, without the ownership scope.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpPostDelete($state) {
        $this->isAllowedOrRedirect('_AdminConsole_Game_Moderate');
        $state = $this->getState();
        $playerId = (int)filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        try {
            $player = $this->loadPlayerForModeration($playerId);
            $confirm = trim((string)filter_input(INPUT_POST, 'confirm_name', FILTER_UNSAFE_RAW));
            if(GameUsername::encode($confirm) !== (int)$player->user) {
                throw new \Exception('The confirmation name did not match. Nothing was deleted.');
            }
            if($player->online || $player->loggedin) {
                throw new \Exception('That character is logged in. Force a logout first, then delete.');
            }
            $hash = (string)$player->user;
            Capsule::connection()->transaction(function() use ($player, $hash) {
                Capsule::connection('game')->table('rscd_players')->where('id', (int)$player->id)->delete();
                foreach(['rscd_curstats', 'rscd_experience', 'rscd_invitems', 'rscd_quests',
                         'rscd_kills', 'rscd_logins', 'rscd_friends', 'rscd_ignores', 'rscd_online'] as $table) {
                    Capsule::connection('game')->table($table)->where('user', $hash)->delete();
                }
                $remaining = Capsule::connection('game')->table('rscd_players')->where('owner', (int)$player->owner)->count();
                if($remaining === 0) {
                    Capsule::connection('game')->table('rscd_bank')->where('owner', (string)$player->owner)->delete();
                }
                Capsule::connection('game')->table('rscd_friends')->where('friend', $hash)->delete();
                Capsule::connection('game')->table('rscd_ignores')->where('ignore', $hash)->delete();
            });
        }
        catch(\Exception $e) {
            return $this->flash($state, $playerId > 0 ? 'admin/game/player/id=' . $playerId . '/' : 'admin/game/players/', 'err', $this->getError($e));
        }
        return $this->flash($state, 'admin/game/players/', 'msg', 'Character "' . $player->username . '" has been deleted.');
    }

    /**
     * Deactivate or reactivate the owner's site account. Deactivation
     * blocks sign-in entirely (findWithCredentials excludes inactive
     * users) and terminates the owner's sessions.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpPostAccount($state) {
        $this->isAllowedOrRedirect('_AdminConsole_Game_Moderate');
        $state = $this->getState();
        $playerId = (int)filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        try {
            $player = $this->loadPlayer($playerId);
            $to = (string)filter_input(INPUT_POST, 'to', FILTER_UNSAFE_RAW);
            $owner = User::where('id', (int)$player->owner)->first();
            if(empty($owner->id)) {
                throw new \Exception('No site account found for this owner.');
            }
            if((int)$owner->id === (int)$state->activeUser->id) {
                throw new \Exception('You cannot deactivate your own account.');
            }
            if(Capsule::table('user_role')->where('user_id', (int)$owner->id)->exists()) {
                throw new \Exception('That account holds a staff role; manage it through Roles instead.');
            }
            if($to === 'deactivate') {
                if((int)$owner->status === User::STATUS_INACTIVE) {
                    throw new \Exception('That account is already deactivated.');
                }
                $owner->status = User::STATUS_INACTIVE;
                $owner->save();
                Session::where('user_id', (int)$owner->id)
                    ->where('status', Session::STATUS_ACTIVE)
                    ->update(['status' => Session::STATUS_TERMINATED]);
                $message = 'The owner\'s site account has been deactivated and their sessions ended.';
            }
            else if($to === 'reactivate') {
                if((int)$owner->status !== User::STATUS_INACTIVE) {
                    throw new \Exception('That account is not deactivated.');
                }
                $owner->status = User::STATUS_ACTIVE;
                $owner->save();
                $message = 'The owner\'s site account has been reactivated.';
            }
            else {
                throw new \Exception('Bad request.');
            }
        }
        catch(\Exception $e) {
            return $this->flash($state, $playerId > 0 ? 'admin/game/player/id=' . $playerId . '/' : 'admin/game/players/', 'err', $this->getError($e));
        }
        return $this->flash($state, 'admin/game/player/id=' . $playerId . '/', 'msg', $message);
    }

    /**
     * Zap one report (report_id) or, with explicit confirmation, every
     * open report about one character (about).
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpPostZap($state) {
        $this->isAllowedOrRedirect('_AdminConsole_Game_Moderate');
        $state = $this->getState();
        // The game server hands out report ids from 0, so absence must be
        // told apart from id 0 — filter_input returns null/false for those.
        $reportId = filter_input(INPUT_POST, 'report_id', FILTER_VALIDATE_INT);
        $about = trim((string)filter_input(INPUT_POST, 'about', FILTER_UNSAFE_RAW));
        try {
            if($reportId !== null && $reportId !== false && (int)$reportId >= 0) {
                $reportId = (int)$reportId;
                $report = Capsule::connection('game')->table('rscd_reports')->where('id', $reportId)->where('zapped', 0)->first();
                if(empty($report) || !isset($report->id)) {
                    throw new \Exception('No such open report.');
                }
                Capsule::connection('game')->table('rscd_reports')->where('id', $reportId)->update(['zapped' => time()]);
                return $this->flash($state, 'admin/game/report/user=' . rawurlencode((string)$report->about) . '/', 'msg', 'Report zapped.');
            }
            if($about !== '' && ctype_digit($about)) {
                if((int)filter_input(INPUT_POST, 'confirm', FILTER_VALIDATE_INT) !== 1) {
                    throw new \Exception('Tick the confirmation box to clear every report.');
                }
                $count = Capsule::connection('game')->table('rscd_reports')->where('about', $about)->where('zapped', 0)->update(['zapped' => time()]);
                return $this->flash($state, 'admin/game/reports/', 'msg', 'Cleared ' . $count . ' report' . ($count === 1 ? '' : 's') . '.');
            }
            throw new \Exception('Bad request.');
        }
        catch(\Exception $e) {
            $back = $about !== '' && ctype_digit($about) ? 'admin/game/report/user=' . rawurlencode($about) . '/' : 'admin/game/reports/';
            return $this->flash($state, $back, 'err', $this->getError($e));
        }
    }

    /* POST: world control */

    /**
     * Send a global message to everyone online.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpPostGlobal($state) {
        $this->isAllowedOrRedirect('_AdminConsole_Game_Control');
        $state = $this->getState();
        $message = trim((string)filter_input(INPUT_POST, 'message', FILTER_UNSAFE_RAW));
        if($message === '') {
            return $this->flash($state, 'admin/game/server/', 'err', 'You didn\'t enter a message.');
        }
        if(!WorldControl::fromConfig($state)->globalMessage($message)) {
            return $this->flash($state, 'admin/game/server/', 'err', 'The world control socket did not confirm the message.');
        }
        return $this->flash($state, 'admin/game/server/', 'msg', 'Global message sent.');
    }

    /**
     * Send an alert to one character.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpPostAlert($state) {
        $this->isAllowedOrRedirect('_AdminConsole_Game_Control');
        $state = $this->getState();
        $username = trim((string)filter_input(INPUT_POST, 'username', FILTER_UNSAFE_RAW));
        $message = trim((string)filter_input(INPUT_POST, 'message', FILTER_UNSAFE_RAW));
        $hash = GameUsername::encode($username);
        if($hash === 0 || $message === '') {
            return $this->flash($state, 'admin/game/server/', 'err', 'Invalid character name, or no message supplied.');
        }
        if(!WorldControl::fromConfig($state)->alert($hash, $message)) {
            return $this->flash($state, 'admin/game/server/', 'err', 'The world control socket did not confirm the alert — is that character online?');
        }
        return $this->flash($state, 'admin/game/server/', 'msg', 'Alert sent to "' . $username . '".');
    }

    /**
     * Update: warn everyone, then shut down after 60 seconds.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpPostUpdate($state) {
        $this->isAllowedOrRedirect('_AdminConsole_Game_Control');
        $state = $this->getState();
        $message = trim((string)filter_input(INPUT_POST, 'message', FILTER_UNSAFE_RAW));
        if(!WorldControl::fromConfig($state)->update($message)) {
            return $this->flash($state, 'admin/game/server/', 'err', 'The world control socket did not confirm the update.');
        }
        return $this->flash($state, 'admin/game/server/', 'msg', 'The server will shut down in 60 seconds.');
    }

    /**
     * Graceful shutdown, immediately, with explicit confirmation.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpPostShutdown($state) {
        $this->isAllowedOrRedirect('_AdminConsole_Game_Control');
        $state = $this->getState();
        if((int)filter_input(INPUT_POST, 'confirm', FILTER_VALIDATE_INT) !== 1) {
            return $this->flash($state, 'admin/game/server/', 'err', 'Tick the confirmation box to shut the server down.');
        }
        if(!WorldControl::fromConfig($state)->shutdown()) {
            return $this->flash($state, 'admin/game/server/', 'err', 'The world control socket did not confirm the shutdown.');
        }
        return $this->flash($state, 'admin/game/server/', 'msg', 'Server shutting down.');
    }

}
