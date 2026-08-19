<?php

namespace RSCD\Controller;

use Illuminate\Database\Capsule\Manager as Capsule;
use RSCD\Model\GameData;
use RSCD\Util\Strings;
use RSCD\View\ShopView;

/**
 * Hiscores — player rankings, straight from the game's own tables, laid out
 * the way the 2003 site did it: a header box, a "Select hiscore table"
 * column beside the ranking table, and rank/name search boxes underneath.
 *
 * The 2003 board list is used verbatim: the four melee stats are not ranked
 * individually — they are one combined "Fighting" table (sum of attack,
 * defense, strength and hits experience; the level column is the sum of the
 * four levels). The overall board orders by total level (skill_total,
 * maintained by the game server on every save) with total experience as the
 * tie-break; each skill board orders by that skill's experience.
 *
 * Exact ties break first-come, the way the original hiscores did: whoever
 * reached the score first holds the higher rank. The stamp_<skill> columns
 * record when each experience figure was attained (0 = before the columns
 * existed, which sorts as "longest ago"), and username is the final key so
 * the order is total. The personal page's rank counts use the identical
 * comparison chain as the board ORDER BYs — the two must never disagree
 * about a player's position.
 *
 * Banned players and admin characters (group 1) are excluded everywhere —
 * members and mods only.
 *
 * Pages:
 *   GET /hiscores/                       overall ranking; page%3DN paginates
 *   GET /hiscores/skill%3Dcooking/       one skill's ranking
 *   GET /hiscores/?skill=x&rank=N        jump to the page containing rank N
 *   GET /hiscores/player/?name=X         one player: rank in every table
 */
class Hiscores extends \RSCD\Controller\ObjectController {

    /** Rows per ranking page — the 2003 hiscores paged 21 at a time. */
    const PER_PAGE = 21;

    /** The four stats the combined Fighting table is built from. */
    const FIGHTING_SKILLS = ['attack', 'defense', 'strength', 'hits'];

    /**
     * The 2003 table list, in menu order, plus one board of our own on the
     * end. Every key except 'overall', 'fighting' and 'party' is a
     * GameData::SKILLS slug. 'party' is Party Animals: items the party
     * cannons fired during each host's scheduled parties, read from
     * rscd_party_animals (maintained by the login server's
     * PartyScheduleHandler) rather than from the experience table.
     */
    const BOARDS = [
        'overall'    => 'Overall',
        'fighting'   => 'Fighting',
        'ranged'     => 'Ranged',
        'prayer'     => 'Prayer',
        'magic'      => 'Magic',
        'cooking'    => 'Cooking',
        'woodcut'    => 'Woodcutting',
        'fletching'  => 'Fletching',
        'fishing'    => 'Fishing',
        'firemaking' => 'Firemaking',
        'crafting'   => 'Crafting',
        'smithing'   => 'Smithing',
        'mining'     => 'Mining',
        'herblaw'    => 'Herblaw',
        'agility'    => 'Agility',
        'thieving'   => 'Thieving',
        'runecrafting' => 'Runecrafting',
        'party'      => 'Party Animals',
    ];

    /**
     * Initialise with the public-facing view.
     */
    public function initialize() {
        $this->set('view', new ShopView($this->get('app')));
    }

    /**
     * Ranked players — everyone except banned characters and admins.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function rankedQuery() {
        return Capsule::connection('game')->table('rscd_players as p')
            ->join('rscd_experience as e', 'e.user', '=', 'p.user')
            ->where('p.banned', 0)
            ->where('p.group_id', '!=', 1);
    }

    /**
     * SQL expression summing every skill's experience column.
     *
     * @return string
     */
    protected function totalExperienceSql() {
        $columns = [];
        foreach(array_keys(GameData::SKILLS) as $skill) {
            $columns[] = 'e.exp_' . $skill;
        }
        return '(' . implode(' + ', $columns) . ')';
    }

    /**
     * SQL expression summing the four Fighting experience columns.
     *
     * @return string
     */
    protected function fightingExperienceSql() {
        $columns = [];
        foreach(self::FIGHTING_SKILLS as $skill) {
            $columns[] = 'e.exp_' . $skill;
        }
        return '(' . implode(' + ', $columns) . ')';
    }

    /**
     * SQL expression for when a score built from these skills was attained —
     * the first-come tie-break key. A summed score last moved when its most
     * recently changed part moved, hence GREATEST over the parts' stamps.
     *
     * @param  array $skills Skill slugs the score is built from.
     * @return string
     */
    protected function attainedStampSql($skills) {
        $columns = [];
        foreach($skills as $skill) {
            $columns[] = 'e.stamp_' . $skill;
        }
        return count($columns) > 1
            ? 'GREATEST(' . implode(', ', $columns) . ')'
            : $columns[0];
    }

    /**
     * When this player attained a score built from these skills — the PHP
     * mirror of attainedStampSql(), evaluated on their own experience row.
     *
     * @param  object $exp    The player's rscd_experience row.
     * @param  array  $skills Skill slugs the score is built from.
     * @return int
     */
    protected function attainedStamp($exp, $skills) {
        $stamp = 0;
        foreach($skills as $skill) {
            $stamp = max($stamp, (int)$exp->{'stamp_' . $skill});
        }
        return $stamp;
    }

    /**
     * Default action — one ranking table, chosen by the skill%3Dname
     * segment (or ?skill= from the search-by-rank form). A ?rank=N query
     * jumps to the page containing that rank.
     *
     * @param object $state Application state.
     */
    public function processDefaultAction($state) {
        $this->authorize();
        $state = $this->getState();
        $base  = $state->url->getBaseUrl();

        $skill = strtolower(trim((string)$state->url->getVariable('skill')));
        if($skill === 'overall') {
            $skill = '';
        }
        if($skill !== '' && !array_key_exists($skill, self::BOARDS)) {
            return $state->app->redirect($base . 'hiscores/');
        }

        $page = max(1, (int)$state->url->getVariable('page'));
        $rank = (int)$state->url->getVariable('rank');
        if($rank > 0) {
            $page = (int)ceil($rank / self::PER_PAGE);
        }

        if($skill === '') {
            $query = $this->rankedQuery();
            $level = function($row) { return (int)$row->skill_total; };
            $order = function($q) {
                return $q->selectRaw('p.username, p.skill_total, '
                        . $this->totalExperienceSql() . ' as xp')
                    ->orderByDesc('p.skill_total')
                    ->orderByDesc('xp')
                    ->orderByRaw($this->attainedStampSql(array_keys(GameData::SKILLS)) . ' asc')
                    ->orderBy('p.username');
            };
        }
        else if($skill === 'fighting') {
            $fightSql = $this->fightingExperienceSql();
            $query = $this->rankedQuery()->whereRaw($fightSql . ' > 0');
            $level = function($row) {
                $sum = 0;
                foreach(self::FIGHTING_SKILLS as $s) {
                    $sum += GameData::experienceToLevel((int)$row->{'exp_' . $s});
                }
                return $sum;
            };
            $order = function($q) use($fightSql) {
                $columns = [];
                foreach(self::FIGHTING_SKILLS as $s) {
                    $columns[] = 'e.exp_' . $s;
                }
                return $q->selectRaw('p.username, ' . implode(', ', $columns)
                        . ', ' . $fightSql . ' as xp')
                    ->orderByDesc('xp')
                    ->orderByRaw($this->attainedStampSql(self::FIGHTING_SKILLS) . ' asc')
                    ->orderBy('p.username');
            };
        }
        else if($skill === 'party') {
            // Party Animals joins its own table, not rscd_experience — the
            // score is items fired, and the stamp column (when the total
            // last moved) is the first-come tie-break, same rule as XP.
            $query = Capsule::connection('game')->table('rscd_players as p')
                ->join('rscd_party_animals as a', 'a.user', '=', 'p.user')
                ->where('p.banned', 0)
                ->where('p.group_id', '!=', 1)
                ->where('a.items', '>', 0);
            $level = null;
            $order = function($q) {
                return $q->selectRaw('p.username, a.items as xp')
                    ->orderByDesc('xp')
                    ->orderBy('a.stamp')
                    ->orderBy('p.username');
            };
        }
        else {
            $query = $this->rankedQuery()->where('e.exp_' . $skill, '>', 0);
            $level = function($row) {
                return GameData::experienceToLevel((int)$row->xp);
            };
            $order = function($q) use($skill) {
                return $q->selectRaw('p.username, e.exp_' . $skill . ' as xp')
                    ->orderByDesc('xp')
                    ->orderBy('e.stamp_' . $skill)
                    ->orderBy('p.username');
            };
        }

        $total = (clone $query)->count();
        $pages = max(1, (int)ceil($total / self::PER_PAGE));
        $page  = min($page, $pages);
        $offset = ($page - 1) * self::PER_PAGE;

        $rows = $order($query)->limit(self::PER_PAGE)->offset($offset)->get();

        $listing = $skill === 'party'
            ? $this->buildTable($rows, $offset, $base, null, 'Items fired')
            : $this->buildTable($rows, $offset, $base, $level);
        if($total === 0) {
            $listing = '<p class="hs-empty">Nobody is ranked here yet.</p>';
        }

        $this->render($state, $skill,
            self::BOARDS[$skill === '' ? 'overall' : $skill] . ' Hiscores',
            $listing, $this->buildPageNav($base, $skill, $page, $pages));
    }

    /**
     * Personal hiscores — one player's rank, level, and experience in every
     * table on the menu.
     *
     * @param object $state Application state.
     */
    protected function httpGetPlayer($state) {
        $this->authorize();
        $state = $this->getState();
        $base  = $state->url->getBaseUrl();

        $name = trim((string)($state->url->getVariable('name') ?? filter_input(INPUT_GET, 'name', FILTER_DEFAULT)));
        if($name === '') {
            return $state->app->redirect($base . 'hiscores/');
        }

        $player = Capsule::connection('game')->table('rscd_players')
            ->where('banned', 0)
            ->where('group_id', '!=', 1)
            ->whereRaw('LOWER(username) = ?', [strtolower($name)])
            ->first();
        $exp = !empty($player->user)
            ? Capsule::connection('game')->table('rscd_experience')->where('user', $player->user)->first()
            : null;

        if(empty($player) || empty($exp)) {
            return $this->render($state, '', 'Personal Hiscores',
                '<p class="hs-empty">No player named "'
                    . Strings::displayText($name) . '" is ranked.</p>', '');
        }

        $myTotalXp = 0;
        foreach(array_keys(GameData::SKILLS) as $skill) {
            $myTotalXp += (int)$exp->{'exp_' . $skill};
        }
        $myFightXp = 0;
        $myFightLevel = 0;
        foreach(self::FIGHTING_SKILLS as $skill) {
            $myFightXp += (int)$exp->{'exp_' . $skill};
            $myFightLevel += GameData::experienceToLevel((int)$exp->{'exp_' . $skill});
        }

        // Every rank below counts the players strictly ahead using the same
        // comparison chain the board ORDER BYs sort with — score desc, then
        // attained-stamp asc (first-come wins the tie), then username — so
        // this page and the board tables always agree on the position.
        $totalSql  = $this->totalExperienceSql();
        $stampSql  = $this->attainedStampSql(array_keys(GameData::SKILLS));
        $myStamp   = $this->attainedStamp($exp, array_keys(GameData::SKILLS));
        $overallRank = $this->rankedQuery()
            ->whereRaw('(p.skill_total > ?'
                . ' OR (p.skill_total = ? AND ' . $totalSql . ' > ?)'
                . ' OR (p.skill_total = ? AND ' . $totalSql . ' = ? AND (' . $stampSql . ' < ?'
                    . ' OR (' . $stampSql . ' = ? AND p.username < ?))))',
                [(int)$player->skill_total,
                 (int)$player->skill_total, $myTotalXp,
                 (int)$player->skill_total, $myTotalXp, $myStamp,
                 $myStamp, $player->username])
            ->count() + 1;

        $rows = $this->personalRow($base, 'overall', 'Overall', $overallRank,
            (int)$player->skill_total, $myTotalXp);

        if($myFightXp > 0) {
            $fightSql      = $this->fightingExperienceSql();
            $fightStampSql = $this->attainedStampSql(self::FIGHTING_SKILLS);
            $myFightStamp  = $this->attainedStamp($exp, self::FIGHTING_SKILLS);
            $fightRank = $this->rankedQuery()
                ->whereRaw('(' . $fightSql . ' > ?'
                    . ' OR (' . $fightSql . ' = ? AND (' . $fightStampSql . ' < ?'
                        . ' OR (' . $fightStampSql . ' = ? AND p.username < ?))))',
                    [$myFightXp, $myFightXp, $myFightStamp, $myFightStamp, $player->username])
                ->count() + 1;
        }
        else {
            $fightRank = '-';
        }
        $rows .= $this->personalRow($base, 'fighting', 'Fighting', $fightRank,
            $myFightLevel, $myFightXp);

        foreach(self::BOARDS as $skill => $label) {
            if($skill === 'overall' || $skill === 'fighting' || $skill === 'party') {
                continue;
            }
            $xp = (int)$exp->{'exp_' . $skill};
            if($xp <= 0) {
                $skillRank = '-';
            }
            else {
                $myStamp = (int)$exp->{'stamp_' . $skill};
                $skillRank = $this->rankedQuery()
                    ->whereRaw('(e.exp_' . $skill . ' > ?'
                        . ' OR (e.exp_' . $skill . ' = ? AND (e.stamp_' . $skill . ' < ?'
                            . ' OR (e.stamp_' . $skill . ' = ? AND p.username < ?))))',
                        [$xp, $xp, $myStamp, $myStamp, $player->username])
                    ->count() + 1;
            }
            $rows .= $this->personalRow($base, $skill, $label, $skillRank,
                GameData::experienceToLevel($xp), $xp);
        }

        // Party Animals, last on the menu: no level to show, and the rank
        // counts ahead-of-me on its own table with the same score-desc,
        // stamp-asc, username chain as the board.
        $party = Capsule::connection('game')->table('rscd_party_animals')
            ->where('user', $player->user)->first();
        $partyItems = !empty($party) ? (int)$party->items : 0;
        if($partyItems <= 0) {
            $partyRank = '-';
        }
        else {
            $partyStamp = (int)$party->stamp;
            $partyRank = Capsule::connection('game')->table('rscd_party_animals as a')
                ->join('rscd_players as p', 'p.user', '=', 'a.user')
                ->where('p.banned', 0)
                ->where('p.group_id', '!=', 1)
                ->whereRaw('(a.items > ?'
                    . ' OR (a.items = ? AND (a.stamp < ?'
                        . ' OR (a.stamp = ? AND p.username < ?))))',
                    [$partyItems, $partyItems, $partyStamp, $partyStamp, $player->username])
                ->count() + 1;
        }
        $rows .= $this->personalRow($base, 'party', 'Party Animals', $partyRank,
            '-', $partyItems);

        $listing = '<table class="hs-table">'
            . '<tr><th>Table</th><th>Rank</th><th>Level</th><th>XP</th></tr>'
            . $rows . '</table>';

        $this->render($state, '',
            'Hiscores for ' . Strings::displayText($player->username),
            $listing, '');
    }

    /**
     * One row of the personal table.
     *
     * @param string     $base  Base URL.
     * @param string     $skill Board key.
     * @param string     $label Board label.
     * @param int|string $rank  Rank, or '-' when unranked.
     * @param int|string $level Level column value, or '-' for boards without one.
     * @param int        $xp    Score column value.
     * @return string
     */
    protected function personalRow($base, $skill, $label, $rank, $level, $xp) {
        $url = $base . 'hiscores/' . ($skill === 'overall' ? '' : 'skill%3D' . $skill . '/');
        return '<tr>'
            . '<td><a href="' . $url . '">' . $label . '</a></td>'
            . '<td>' . $rank . '</td>'
            . '<td>' . $level . '</td>'
            . '<td>' . GameData::displayExperience($xp) . '</td>'
            . '</tr>';
    }

    /**
     * A ranking table for a board page — Rank, Name, Level, XP, exactly the
     * 2003 columns. Party Animals is the one board with no level to show:
     * a null $level drops that column, and $score renames XP to what the
     * number actually counts.
     *
     * @param iterable      $rows   Result rows with username and xp.
     * @param int           $offset Rank offset of the first row.
     * @param string        $base   Base URL.
     * @param callable|null $level  Row-to-level mapper, or null for no column.
     * @param string        $score  Header of the score column.
     * @return string
     */
    protected function buildTable($rows, $offset, $base, $level, $score = 'XP') {
        $html = '';
        $rank = $offset;
        foreach($rows as $row) {
            $rank++;
            $html .= '<tr>'
                . '<td>' . $rank . '</td>'
                . '<td><a href="' . $base . 'hiscores/player/?name=' . rawurlencode($row->username) . '">'
                    . Strings::displayText($row->username) . '</a></td>'
                . ($level !== null ? '<td>' . $level($row) . '</td>' : '')
                . '<td>' . GameData::displayExperience((int)$row->xp) . '</td>'
                . '</tr>';
        }
        if($html === '') {
            return '';
        }
        return '<table class="hs-table">'
            . '<tr><th>Rank</th><th>Name</th>'
            . ($level !== null ? '<th>Level</th>' : '')
            . '<th>' . $score . '</th></tr>'
            . $html . '</table>';
    }

    /**
     * Previous/next page links for a board.
     *
     * @param string $base  Base URL.
     * @param string $skill Board key, or '' for overall.
     * @param int    $page  Current page (1-based).
     * @param int    $pages Total pages.
     * @return string
     */
    protected function buildPageNav($base, $skill, $page, $pages) {
        if($pages <= 1) {
            return '';
        }
        $url = function($n) use($base, $skill) {
            return $base . 'hiscores/' . ($skill !== '' ? 'skill%3D' . $skill . '/' : '')
                . ($n > 1 ? 'page%3D' . $n . '/' : '');
        };
        $html = '<p class="hs-pagenav">';
        if($page > 1) {
            $html .= '<a href="' . $url($page - 1) . '">&laquo; Previous</a> &middot; ';
        }
        $html .= 'Page ' . $page . ' of ' . $pages;
        if($page < $pages) {
            $html .= ' &middot; <a href="' . $url($page + 1) . '">Next &raquo;</a>';
        }
        return $html . '</p>';
    }

    /**
     * Render a hiscores page through the 2003-layout template.
     *
     * @param object $state   Application state.
     * @param string $skill   Board key of the current page ('' = overall).
     * @param string $heading Right-column heading.
     * @param string $listing Right-column body (trusted HTML).
     * @param string $pagenav Page links under the table (trusted HTML).
     */
    protected function render($state, $skill, $heading, $listing, $pagenav) {
        $base = $state->url->getBaseUrl();

        $menu = '';
        foreach(self::BOARDS as $key => $label) {
            $url = $base . 'hiscores/' . ($key === 'overall' ? '' : 'skill%3D' . $key . '/');
            $menu .= '<a href="' . $url . '">' . $label . '</a><br />';
        }

        $hidden = $skill !== ''
            ? '<input type="hidden" name="skill" value="' . $skill . '" />'
            : '';

        $page = $state->view->getViewLayout('hiscores' . DIRECTORY_SEPARATOR . 'index.html');
        $page->populateHtmlFromFile();
        $page->injectHtml('alerts', '');
        $page->injectHtml('heading', $heading);
        $page->injectHtml('skill_menu', $menu);
        $page->injectHtml('listing', $listing);
        $page->injectHtml('pagenav', $pagenav);
        $page->injectHtml('skill_hidden', $hidden);
        $state->view->setPage($page->get('html'), [], $heading);
    }
}
