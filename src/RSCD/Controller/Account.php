<?php

namespace RSCD\Controller;

use Illuminate\Database\Capsule\Manager as Capsule;
use RSCD\Model\GameData;
use RSCD\Util\Dates;
use RSCD\Util\GameUsername;
use RSCD\Util\Strings;
use RSCD\View\ShopView;

/**
 * The Account Manager — game character management for signed-in users.
 *
 * A website account owns an unlimited number of game characters
 * (rscd_players.owner). This controller lets the owner:
 *   - list their characters                      GET  /account/
 *   - create a character                         GET/POST /account/create/
 *   - view one (stats, quests, inventory, bank)  GET  /account/player/id%3D<n>/
 *   - set a character's game password            POST /account/password/
 *   - delete a character                         POST /account/delete/
 *
 * Every action requires a signed-in site user (authorize(true) redirects
 * guests to the sign-in page) and every query is scoped to
 * owner = activeUser->id, so no character can be read or changed through
 * another account.
 *
 * The game tables live in this application's own database by default (one
 * schema carries both the site's tables and the full rscd_* schema; see
 * App.php for the split setup). Character passwords are MD5 hex because that is
 * the wire format the RSC protocol sends and the login server compares
 * (PlayerLoginHandler reads a 32-char hex digest) — site accounts use the
 * framework's strong hashing and are unaffected. Creation follows the legacy
 * recipe: a players row plus blank curstats/experience rows, empty inventory
 * (Tutorial Island equips new characters in game).
 */
class Account extends \RSCD\Controller\ObjectController {

    /**
     * Game password bounds. The minimum is the original site's; the maximum is
     * the width of the field in the login block, which is also the width of the
     * login screen's password box (Menu.makeTextBox(..., 20, ...)). Anything
     * longer is truncated on its way out of the client and would not match.
     */
    const PASSWORD_MIN = 4;
    const PASSWORD_MAX = 20;

    /**
     * The only characters a game password may contain.
     *
     * The client's own login-box filter (Menu.java, validCharSet) with one
     * character removed: the client accepts £, but DataEncryption.addString
     * writes text with the deprecated String.getBytes, which keeps only the low
     * byte of each char, so £ leaves the client as one byte while PHP hashes it
     * as the two bytes of its UTF-8 form. The digests then disagree and the
     * password is unusable. The same argument rules out every other non-ASCII
     * character, so what is left is printable ASCII minus the backtick, which
     * the client will not let you type at all.
     *
     * Punctuation is deliberately *in* this set. The client used to pad the
     * password with DataOperations.addCharacters, Jagex's login formatter,
     * which rewrites everything outside [A-Za-z0-9] to an underscore — so a
     * password with punctuation in it hashed to something the player never
     * typed. The right answer to that was to stop the client mangling it
     * (DataOperations.padCharacters), not to make every password weaker.
     */
    const PASSWORD_CHARS = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!\"\$%^&*()-_=+[{]};:'@#~,<.>/?\\| ";

    /** Character name length bounds (canonical decoded form). */
    const NAME_MIN = 2;
    const NAME_MAX = 12;

    /** group_id for a regular (non-staff) character. */
    const GROUP_PLAYER = 4;

    /**
     * Initialise with the public-facing view.
     */
    public function initialize() {
        $this->set('view', new ShopView($this->get('app')));
    }

    /**
     * Default action — the character list.
     *
     * @param object $state Application state.
     */
    public function processDefaultAction($state) {
        $state->request->action = 'account';
        $state->request->method = 'GET';
        return $this->httpGetAccount($state);
    }

    /**
     * Require a signed-in user and return the refreshed state.
     *
     * authorize(true) terminates with a redirect to sign-in (carrying a ref
     * back here) when the request is unauthenticated, so callers can rely on
     * activeUser being populated afterwards.
     *
     * @return object Application state with activeUser set.
     */
    protected function requireUser() {
        $this->authorize(true);
        return $this->getState();
    }

    /**
     * Render ?msg= / ?err= flash parameters as alert HTML, plus any response
     * object accumulated by the calling action.
     *
     * @param  object      $state    Application state.
     * @param  object|null $response Optional response with errors/messages.
     * @return string                Alert markup, or an empty string.
     */
    protected function buildAlertsHtml($state, $response = null) {
        $alerts = '';
        $err = $state->url->getVariable('err');
        $msg = $state->url->getVariable('msg');
        $errors   = array_merge(!empty($err) ? [rawurldecode($err)] : [], !empty($response->errors) ? $response->errors : []);
        $messages = array_merge(!empty($msg) ? [rawurldecode($msg)] : [], !empty($response->messages) ? $response->messages : []);
        if(!empty($errors)) {
            $alerts .= '<div class="alert alert-danger" role="alert">' . Strings::displayText(implode(', ', $errors)) . '</div>';
        }
        if(!empty($messages)) {
            $alerts .= '<div class="alert alert-success" role="alert">' . Strings::displayText(implode(', ', $messages)) . '</div>';
        }
        return $alerts;
    }

    /**
     * Redirect back to an account page with a flash message.
     *
     * @param object $state Application state.
     * @param string $path  Path under the base URL (e.g. 'account/').
     * @param string $key   'msg' for success, 'err' for failure.
     * @param string $text  Flash message text.
     */
    protected function redirectWithFlash($state, $path, $key, $text) {
        return $state->app->redirect($state->url->getBaseUrl() . $path . '?' . http_build_query([$key => $text]));
    }

    /**
     * Load a character by id, enforcing ownership by the active user.
     *
     * @param  object $state    Application state.
     * @param  mixed  $playerId Character row id.
     * @return object           The rscd_players row.
     * @throws \Exception       When the character doesn't exist on this account.
     */
    protected function loadOwnedPlayer($state, $playerId) {
        $player = null;
        if((int)$playerId > 0) {
            $player = Capsule::connection('game')->table('rscd_players')
                ->where('id', (int)$playerId)
                ->where('owner', (int)$state->activeUser->id)
                ->first();
        }
        if(empty($player->id)) {
            throw new \Exception('That character does not exist on your account.');
        }
        return $player;
    }

    /**
     * Character list page.
     *
     * @param object $state Application state.
     */
    protected function httpGetAccount($state) {
        $state = $this->requireUser();
        $players = Capsule::connection('game')->table('rscd_players')
            ->where('owner', (int)$state->activeUser->id)
            ->orderBy('creation_date')
            ->get();

        if(count($players) > 0) {
            $rows = '';
            foreach($players as $player) {
                $rows .= '<tr>'
                    . '<td><a href="' . $state->url->getBaseUrl() . 'account/player/id%3D' . (int)$player->id . '/">'
                    . Strings::displayText($player->username) . '</a></td>'
                    . '<td>' . (int)$player->combat . '</td>'
                    . '<td>' . (int)$player->skill_total . '</td>'
                    . '<td>' . ($player->online || $player->loggedin ? '<b>Online</b>' : 'Offline') . '</td>'
                    . '<td>' . Dates::display($player->login_date, 'j M Y', 'Never') . '</td>'
                    . '</tr>';
            }
            $listing = '<table class="data-table">'
                . '<tr><th>Character</th><th>Combat</th><th>Skill total</th><th>Status</th><th>Last login</th></tr>'
                . $rows . '</table>';
        }
        else {
            $listing = '<p>You have no characters yet.  Create one below to start playing.</p>';
        }

        $page = $state->view->getViewLayout('account' . DIRECTORY_SEPARATOR . 'index.html');
        $page->populateHtmlFromFile();
        $page->injectHtml('alerts', $this->buildAlertsHtml($state));
        $page->injectHtml('listing', $listing);
        $state->view->setPage($page->get('html'), [], 'Account manager');
    }

    /**
     * Character creation form.
     *
     * @param object      $state    Application state.
     * @param object|null $response Optional response with errors from a failed POST.
     */
    protected function httpGetCreate($state, $response = null) {
        $state = $this->requireUser();
        $page = $state->view->getViewLayout('account' . DIRECTORY_SEPARATOR . 'create.html');
        $page->populateHtmlFromFile();
        $page->injectHtml('alerts', $this->buildAlertsHtml($state, $response));
        $state->view->setPage($page->get('html'), [], 'Create a character');
    }

    /**
     * Create a character — the legacy recipe against the current schema.
     *
     * Validates the name through the base-37 round trip (so what's stored is
     * exactly what the game will display), enforces the original password and
     * reserved-prefix rules, refuses duplicate name hashes, then inserts the
     * players row plus blank curstats/experience rows in one transaction.
     *
     * @param object $state Application state.
     */
    protected function httpPostCreate($state) {
        $state = $this->requireUser();
        $response = $this->getBlankResponse();
        try {
            $name     = trim((string)filter_input(INPUT_POST, 'username', FILTER_UNSAFE_RAW));
            $password = (string)filter_input(INPUT_POST, 'password',         FILTER_UNSAFE_RAW);
            $confirm  = (string)filter_input(INPUT_POST, 'confirm_password', FILTER_UNSAFE_RAW);

            $hash = GameUsername::encode($name);
            $canonical = GameUsername::decode($hash);
            if($hash === 0 || strlen($canonical) < static::NAME_MIN || strlen($canonical) > static::NAME_MAX) {
                throw new \Exception('Character names must be ' . static::NAME_MIN . ' to ' . static::NAME_MAX . ' letters, numbers, or spaces.');
            }
            $lower = strtolower($canonical);
            if(strpos($lower, 'mod ') === 0 || strpos($lower, 'admin ') === 0) {
                throw new \Exception('Character names may not begin with "Mod" or "Admin".');
            }
            $this->assertUsableGamePassword($password, $confirm);
            if(Capsule::connection('game')->table('rscd_players')->where('user', (string)$hash)->exists()) {
                throw new \Exception('That character name is already taken.  Please choose another.');
            }

            $userId    = (int)$state->activeUser->id;
            $ownerName = (string)$state->activeUser->name;
            $ip        = substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 15);
            Capsule::connection('game')->transaction(function() use($hash, $canonical, $userId, $ownerName, $password, $ip) {
                Capsule::connection('game')->table('rscd_players')->insert([
                    'user'           => (string)$hash,
                    'username'       => $canonical,
                    'owner'          => $userId,
                    'owner_username' => $ownerName,
                    'group_id'       => static::GROUP_PLAYER,
                    'pass'           => md5($password),
                    'creation_date'  => time(),
                    'creation_ip'    => $ip,
                ]);
                // Blank stat rows — every skill column carries the game's
                // level-1 defaults (hits at level 10) in the schema itself.
                Capsule::connection('game')->table('rscd_curstats')->insert(['user' => (string)$hash]);
                Capsule::connection('game')->table('rscd_experience')->insert(['user' => (string)$hash]);
            });
        }
        catch(\Exception $e) {
            $response->errors[] = $this->getError($e);
            return $this->httpGetCreate($state, $response);
        }
        return $this->redirectWithFlash($state, 'account/', 'msg', 'Character "' . $canonical . '" has been created.  You can now sign in to the game with it.');
    }

    /**
     * Character detail page — profile, skills, quest progress, inventory,
     * bank, password change, and deletion.
     *
     * @param object $state Application state.
     */
    protected function httpGetPlayer($state) {
        $state = $this->requireUser();
        try {
            $player = $this->loadOwnedPlayer($state, $state->url->getVariable('id'));
        }
        catch(\Exception $e) {
            return $this->redirectWithFlash($state, 'account/', 'err', $this->getError($e));
        }

        $page = $state->view->getViewLayout('account' . DIRECTORY_SEPARATOR . 'player.html');
        $page->populateHtmlFromFile();
        $page->injectHtml('alerts', $this->buildAlertsHtml($state));
        $page->injectHtml('player.id', (int)$player->id);
        $page->injectHtml('player.name', Strings::displayText($player->username));
        $page->injectHtml('summary', $this->buildSummaryHtml($player));
        $page->injectHtml('skills', $this->buildSkillsHtml($player));
        $page->injectHtml('quests', $this->buildQuestsHtml($player));
        // The bank is keyed on the owning site account, not the character:
        // the login server loads and saves rscd_bank by rscd_players.owner
        // (PlayerSave.loadPlayer), so every character on an account shares
        // one bank.
        $page->injectHtml('inventory', $this->buildItemsHtml('rscd_invitems', 'user', $player->user, true));
        $page->injectHtml('bank', $this->buildItemsHtml('rscd_bank', 'owner', (string)$player->owner, false));
        $state->view->setPage($page->get('html'), [], $player->username);
    }

    /**
     * Profile summary table for the character header.
     *
     * @param  object $player rscd_players row.
     * @return string         HTML.
     */
    protected function buildSummaryHtml($player) {
        $rows = [
            'Combat level' => (int)$player->combat,
            'Skill total'  => (int)$player->skill_total,
            'Status'       => ($player->online || $player->loggedin) ? '<b>Online</b>' : 'Offline',
            'Fatigue'      => (int)$player->fatigue . '%',
            'Created'      => Dates::display($player->creation_date, 'j M Y', 'Unknown'),
            'Last login'   => Dates::display($player->login_date, 'j M Y, H:i', 'Never'),
        ];
        $html = '<table class="data-table">';
        foreach($rows as $label => $value) {
            $html .= '<tr><th>' . $label . '</th><td>' . $value . '</td></tr>';
        }
        return $html . '</table>';
    }

    /**
     * The 18-skill table: level from raw experience, current (possibly
     * boosted or drained) stat, and the experience itself.
     *
     * @param  object $player rscd_players row.
     * @return string         HTML.
     */
    protected function buildSkillsHtml($player) {
        $stats = Capsule::connection('game')->table('rscd_curstats')->where('user', $player->user)->first();
        $exps  = Capsule::connection('game')->table('rscd_experience')->where('user', $player->user)->first();
        $html = '<table class="data-table">'
            . '<tr><th>Skill</th><th>Level</th><th>Current</th><th>Experience</th></tr>';
        $base = $this->getState()->url->getBaseUrl();
        foreach(GameData::SKILLS as $key => $label) {
            $experience = (int)($exps->{'exp_' . $key} ?? 0);
            $current    = (int)($stats->{'cur_' . $key} ?? 0);
            // skill icons are named by the same keys as the stat columns
            $html .= '<tr><td><img class="skill-icon" src="' . $base . 'ui/img/skills/' . $key . '.png" alt="" /> ' . $label . '</td>'
                . '<td>' . GameData::experienceToLevel($experience) . '</td>'
                . '<td>' . $current . '</td>'
                . '<td>' . number_format(GameData::displayExperience($experience)) . '</td></tr>';
        }
        return $html . '</table>';
    }

    /**
     * Quest progress over the vanilla quest list.
     *
     * A quest with no rscd_quests row (or stage -1) has not been started; a
     * stage in the quest's generated final-stage set means completed;
     * anything else is in progress.
     *
     * @param  object $player rscd_players row.
     * @return string         HTML.
     */
    protected function buildQuestsHtml($player) {
        $stages = [];
        foreach(Capsule::connection('game')->table('rscd_quests')->where('user', $player->user)->get() as $row) {
            $stages[(int)$row->quest] = (int)$row->stage;
        }
        $completed = 0;
        $rows = '';
        $base = $this->getState()->url->getBaseUrl();
        foreach(GameData::questData() as $questId => $quest) {
            $stage = $stages[$questId] ?? -1;
            if(in_array($stage, $quest['final'], true)) {
                $status = '<b>Completed</b>';
                $completed++;
            }
            else {
                $status = $stage <= -1 ? 'Not started' : 'In progress';
            }
            $name = Strings::displayText($quest['name']);
            $slug = GameData::questSlugByUid($questId);
            if($slug !== null) {
                $name = '<a href="' . $base . 'manual/quest/name%3D' . $slug . '/">' . $name . '</a>';
            }
            $rows .= '<tr><td>' . $name . '</td><td>' . $status . '</td></tr>';
        }
        return '<p>Quests completed: <b>' . $completed . '</b> of ' . count(GameData::questData()) . '</p>'
            . '<table class="data-table"><tr><th>Quest</th><th>Status</th></tr>' . $rows . '</table>';
    }

    /**
     * Item listing for the inventory or bank.
     *
     * @param  string $table       rscd_invitems or rscd_bank.
     * @param  string $keyColumn   'user' (inventory) or 'owner' (bank).
     * @param  string $userHash    The character's base-37 hash.
     * @param  bool   $showWielded Whether to mark wielded items.
     * @return string              HTML.
     */
    protected function buildItemsHtml($table, $keyColumn, $userHash, $showWielded) {
        $items = Capsule::connection('game')->table($table)->where($keyColumn, $userHash)->orderBy('slot')->get();
        if(count($items) === 0) {
            return '<p>Empty.</p>';
        }
        $base = $this->getState()->url->getBaseUrl();
        $rows = '';
        foreach($items as $item) {
            // sprites are the game's own, pre-rendered one per item id; the
            // odd id without one just shows no picture
            $sprite = is_file(__ROOTS__ . 'ui' . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'items' . DIRECTORY_SEPARATOR . (int)$item->id . '.png')
                ? '<img class="item-sprite" src="' . $base . 'ui/img/items/' . (int)$item->id . '.png" alt="" />'
                : '';
            $rows .= '<tr><td class="item-sprite-cell">' . $sprite . '</td>'
                . '<td>' . Strings::displayText(GameData::itemName((int)$item->id))
                . ($showWielded && !empty($item->wielded) ? ' <i>(wielded)</i>' : '')
                . '</td><td>' . number_format((int)$item->amount) . '</td></tr>';
        }
        return '<table class="data-table"><tr><th></th><th>Item</th><th>Amount</th></tr>' . $rows . '</table>';
    }

    /**
     * Reject a game password the client could not send back unchanged.
     *
     * A game password is only ever compared as an MD5 digest, so the site and
     * the client have to agree on the exact bytes before either one hashes
     * them. Two ways they can silently disagree:
     *
     *  - a character outside PASSWORD_CHARS, which the client either cannot
     *    type or transmits as different bytes than PHP hashed;
     *  - a leading or trailing space, which the game server strips —
     *    PlayerLogin does readString(20).trim() and the login server does
     *    readString(32).trim() — after the site has already hashed it in.
     *
     * Either one produces a password that the account manager accepts and
     * reports as changed, and that then fails at the game's login screen as
     * "Invalid username or password", because the login server returns the
     * same code 2 for a wrong password as for a character that does not exist.
     * Catching it here is the only place the mismatch is still visible.
     *
     * @param string $password The submitted password.
     * @param string $confirm  The submitted confirmation.
     * @return void
     * @throws \Exception If the password is unusable or does not match.
     */
    protected function assertUsableGamePassword($password, $confirm) {
        if(strlen($password) < static::PASSWORD_MIN || strlen($password) > static::PASSWORD_MAX) {
            throw new \Exception('Game passwords must be ' . static::PASSWORD_MIN . ' to ' . static::PASSWORD_MAX . ' characters.');
        }
        if($password !== $confirm) {
            throw new \Exception('The password and password confirmation must match.');
        }
        if(strspn($password, static::PASSWORD_CHARS) !== strlen($password)) {
            throw new \Exception('Game passwords may only contain letters, numbers, and the punctuation you can type on the game\'s login screen.');
        }
        if(trim($password) !== $password) {
            throw new \Exception('Game passwords may not begin or end with a space.');
        }
    }

    /**
     * Set a character's game password.
     *
     * This is the "reset everything" path: the owner can always recover a
     * character from the website, because a site password reset restores
     * access to this page and this page rewrites the game password.
     *
     * @param object $state Application state.
     */
    protected function httpPostPassword($state) {
        $state = $this->requireUser();
        $playerId = (int)filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        try {
            $player   = $this->loadOwnedPlayer($state, $playerId);
            $password = (string)filter_input(INPUT_POST, 'password',         FILTER_UNSAFE_RAW);
            $confirm  = (string)filter_input(INPUT_POST, 'confirm_password', FILTER_UNSAFE_RAW);
            $this->assertUsableGamePassword($password, $confirm);
            Capsule::connection('game')->table('rscd_players')->where('id', (int)$player->id)->update(['pass' => md5($password)]);
        }
        catch(\Exception $e) {
            return $this->redirectWithFlash($state, $playerId > 0 ? 'account/player/id%3D' . $playerId . '/' : 'account/', 'err', $this->getError($e));
        }
        return $this->redirectWithFlash($state, 'account/player/id%3D' . (int)$player->id . '/', 'msg', 'The game password for "' . $player->username . '" has been changed.');
    }

    /**
     * Delete a character and every game row keyed on it.
     *
     * Requires the character's name to be typed back (compared canonically,
     * through the same base-37 hash the game uses) and refuses while the
     * character is logged in, so an active session can't be yanked out from
     * under the game server.
     *
     * @param object $state Application state.
     */
    protected function httpPostDelete($state) {
        $state = $this->requireUser();
        $playerId = (int)filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        try {
            $player  = $this->loadOwnedPlayer($state, $playerId);
            $confirm = trim((string)filter_input(INPUT_POST, 'confirm_name', FILTER_UNSAFE_RAW));
            if(GameUsername::encode($confirm) !== (int)$player->user) {
                throw new \Exception('The confirmation name did not match the character name.  Nothing was deleted.');
            }
            if($player->online || $player->loggedin) {
                throw new \Exception('That character is currently logged in to the game.  Log out first, then delete.');
            }
            $hash = (string)$player->user;
            Capsule::connection('game')->transaction(function() use($player, $hash) {
                Capsule::connection('game')->table('rscd_players')->where('id', (int)$player->id)->delete();
                foreach(['rscd_curstats', 'rscd_experience', 'rscd_invitems', 'rscd_quests',
                         'rscd_kills', 'rscd_logins', 'rscd_friends', 'rscd_ignores', 'rscd_online'] as $table) {
                    Capsule::connection('game')->table($table)->where('user', $hash)->delete();
                }
                // The bank belongs to the site account (shared by all its
                // characters — see httpGetPlayer), so it only goes when the
                // account's last character does.
                $remaining = Capsule::connection('game')->table('rscd_players')->where('owner', (int)$player->owner)->count();
                if($remaining === 0) {
                    Capsule::connection('game')->table('rscd_bank')->where('owner', (string)$player->owner)->delete();
                }
                // Other characters' lists pointing at the deleted name.
                Capsule::connection('game')->table('rscd_friends')->where('friend', $hash)->delete();
                Capsule::connection('game')->table('rscd_ignores')->where('ignore', $hash)->delete();
            });
        }
        catch(\Exception $e) {
            return $this->redirectWithFlash($state, $playerId > 0 ? 'account/player/id%3D' . $playerId . '/' : 'account/', 'err', $this->getError($e));
        }
        return $this->redirectWithFlash($state, 'account/', 'msg', 'Character "' . $player->username . '" has been deleted.');
    }

}
