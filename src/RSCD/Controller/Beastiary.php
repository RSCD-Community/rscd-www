<?php

namespace RSCD\Controller;

use RSCD\Model\GameData;
use RSCD\Util\Strings;
use RSCD\View\ShopView;

/**
 * The Beastiary — every NPC in the game, from the game's own data.
 *
 * Driven entirely by Data/npc-data.php, which tools/generate-game-data.py
 * writes from the server's NPCDef.xml.gz and RareDropTable.xml.gz, so the
 * website can never disagree with what the server actually does. No
 * database is involved.
 *
 * Pages:
 *   GET /beastiary/                    monsters (attackable), A-Z; ?q= filters
 *   GET /beastiary/all%3D1/            every npc, shopkeepers and all
 *   GET /beastiary/npc/id%3DN/         one npc: stats and its full drop table
 *   GET /beastiary/rare/               the shared rare drop table
 *
 * Drop rates are shown exactly as the server rolls them (Npc.killedBy):
 * weight 0 always drops, weighted rows are weight in total+1 (the +1 is the
 * roll's built-in empty outcome), id -1 is an explicit nothing, id -2 hands
 * the roll to the shared rare drop table.
 */
class Beastiary extends \RSCD\Controller\ObjectController {

    /**
     * Initialise with the public-facing view.
     */
    public function initialize() {
        $this->set('view', new ShopView($this->get('app')));
    }

    /**
     * Default action — the monster list.
     *
     * @param object $state Application state.
     */
    public function processDefaultAction($state) {
        $this->authorize();
        $state = $this->getState();
        $base = $state->url->getBaseUrl();
        $all = (int)$state->url->getVariable('all') === 1;
        $query = trim((string)$state->url->getVariable('q'));

        $npcs = GameData::npcData()['npcs'];
        $listed = [];
        foreach($npcs as $id => $npc) {
            if(!$all && empty($npc['attackable'])) {
                continue;
            }
            if($query !== '' && stripos($npc['name'], $query) === false) {
                continue;
            }
            $listed[$id] = $npc;
        }
        uasort($listed, function($a, $b) {
            return strcasecmp($a['name'], $b['name']) ?: (GameData::npcCombatLevel($a) <=> GameData::npcCombatLevel($b));
        });

        $rows = '';
        foreach($listed as $id => $npc) {
            $rows .= '<tr>'
                . '<td><a href="' . $base . 'beastiary/npc/id%3D' . $id . '/"><b>' . htmlspecialchars($npc['name'], ENT_QUOTES) . '</b></a></td>'
                . '<td>' . (!empty($npc['attackable']) ? GameData::npcCombatLevel($npc) : '-') . '</td>'
                . '<td>' . (!empty($npc['attackable']) ? $npc['hits'] : '-') . '</td>'
                . '<td>' . (!empty($npc['aggressive']) ? 'Yes' : 'No') . '</td>'
                . '<td>' . htmlspecialchars($npc['desc'], ENT_QUOTES) . '</td>'
                . '</tr>';
        }
        $listing = $rows !== ''
            ? '<table class="data-table forum-table"><tr><th>Name</th><th>Combat</th><th>Hits</th><th>Aggressive</th><th>Description</th></tr>' . $rows . '</table>'
            : '<p>Nothing matches that search.</p>';

        $err = $state->url->getVariable('err');
        $page = $state->view->getViewLayout('beastiary' . DIRECTORY_SEPARATOR . 'index.html');
        $page->populateHtmlFromFile();
        $page->injectHtml('alerts', !empty($err)
            ? '<div class="alert alert-danger" role="alert">' . Strings::displayText(rawurldecode($err)) . '</div>'
            : '');
        $page->injectHtml('count', count($listed));
        $page->injectHtml('mode', $all ? 'every character in the game' : 'every monster you can fight');
        $page->injectHtml('q', Strings::displayText($query));
        $page->injectHtml('listing', $listing);
        $page->injectHtml('toggle', $all
            ? '<a href="' . $base . 'beastiary/">Show monsters only</a>'
            : '<a href="' . $base . 'beastiary/all%3D1/">Show every npc, shopkeepers and all</a>');
        $state->view->setPage($page->get('html'), [], 'Beastiary');
    }

    /**
     * One npc's page: stats and its drop table.
     *
     * @param object $state Application state.
     */
    protected function httpGetNpc($state) {
        $this->authorize();
        $state = $this->getState();
        $id = (int)$state->url->getVariable('id');
        $npcs = GameData::npcData()['npcs'];
        if(!isset($npcs[$id])) {
            return $state->app->redirect($state->url->getBaseUrl() . 'beastiary/?' . http_build_query(['err' => 'No such creature.']));
        }
        $npc = $npcs[$id];

        $stats = '<table class="data-table beastiary-stats">'
            . '<tr><th>Combat level</th><td>' . (!empty($npc['attackable']) ? GameData::npcCombatLevel($npc) : '- (cannot be attacked)') . '</td></tr>'
            . '<tr><th>Hits</th><td>' . (int)$npc['hits'] . '</td></tr>'
            . '<tr><th>Attack</th><td>' . (int)$npc['att'] . '</td></tr>'
            . '<tr><th>Strength</th><td>' . (int)$npc['str'] . '</td></tr>'
            . '<tr><th>Defense</th><td>' . (int)$npc['def'] . '</td></tr>'
            . '<tr><th>Aggressive</th><td>' . (!empty($npc['aggressive']) ? 'Yes' : 'No') . '</td></tr>'
            . '<tr><th>Respawn</th><td>' . (int)$npc['respawn'] . ' seconds</td></tr>';
        $quests = [];
        foreach(GameData::questsForNpc($id) as $slug) {
            $quest = GameData::manualQuest($slug);
            $quests[] = '<a href="' . $state->url->getBaseUrl() . 'manual/quest/name%3D' . $slug . '/">'
                . htmlspecialchars($quest !== null ? $quest['name'] : $slug, ENT_QUOTES) . '</a>';
        }
        if(!empty($quests)) {
            $stats .= '<tr><th>Quests</th><td>' . implode(', ', $quests) . '</td></tr>';
        }
        $stats .= '</table>';

        // sprite portraits are pre-rendered from the game cache by the
        // client tree's SpriteDumper; a handful of npcs have no drawable
        // sprite, so only show the ones that exist
        $portrait = '';
        if(is_file(__ROOTS__ . 'ui' . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'beastiary' . DIRECTORY_SEPARATOR . 'npc' . $id . '.png')) {
            $portrait = '<p class="beastiary-portrait"><img src="' . $state->url->getBaseUrl()
                . 'ui/img/beastiary/npc' . $id . '.png" alt="' . htmlspecialchars($npc['name'], ENT_QUOTES) . '" /></p>';
        }

        $page = $state->view->getViewLayout('beastiary' . DIRECTORY_SEPARATOR . 'npc.html');
        $page->populateHtmlFromFile();
        $page->injectHtml('portrait', $portrait);
        $page->injectHtml('npc.name', htmlspecialchars($npc['name'], ENT_QUOTES));
        $page->injectHtml('npc.desc', htmlspecialchars($npc['desc'], ENT_QUOTES));
        $page->injectHtml('stats', $stats);
        $page->injectHtml('drops', !empty($npc['attackable'])
            ? $this->buildDropsHtml($state, $npc['drops'])
            : '<p>' . htmlspecialchars($npc['name'], ENT_QUOTES) . ' cannot be attacked.</p>');
        $state->view->setPage($page->get('html'), [], $npc['name'] . ' - Beastiary');
    }

    /**
     * The shared rare drop table's own page.
     *
     * @param object $state Application state.
     */
    protected function httpGetRare($state) {
        $this->authorize();
        $state = $this->getState();
        $page = $state->view->getViewLayout('beastiary' . DIRECTORY_SEPARATOR . 'rare.html');
        $page->populateHtmlFromFile();
        $page->injectHtml('drops', $this->buildDropsHtml($state, GameData::npcData()['rare'], true));
        $state->view->setPage($page->get('html'), [], 'Rare drop table - Beastiary');
    }

    /**
     * A drop table rendered with the server's actual odds.
     *
     * @param  object $state Application state.
     * @param  array  $drops Rows of [item id, amount, weight].
     * @param  bool   $rare  Whether this IS the rare table (no link to itself).
     * @return string        HTML.
     */
    protected function buildDropsHtml($state, $drops, $rare = false) {
        if(empty($drops)) {
            return '<p>Drops nothing at all.</p>';
        }
        $base = $state->url->getBaseUrl();
        $total = 0;
        foreach($drops as $drop) {
            $total += $drop[2];
        }
        // random(0, total) is inclusive on both rolls (Npc.killedBy and
        // RareDropTable.roll), so weighted odds are out of total+1: the one
        // unmatched value is the built-in empty outcome shown below.
        $outOf = $total + 1;

        $rows = '';
        foreach($drops as $drop) {
            list($itemId, $amount, $weight) = $drop;
            if($itemId === -2) {
                $name = '<a href="' . $base . 'beastiary/rare/">Rare drop table</a>';
            }
            else if($itemId < 0) {
                $name = '<i>Nothing</i>';
            }
            else {
                $name = htmlspecialchars(GameData::itemName($itemId), ENT_QUOTES);
            }
            if($weight === 0) {
                $rate = '<b>Always</b>';
            }
            else {
                $pct = 100 * $weight / $outOf;
                $rate = '1 in ' . rtrim(rtrim(number_format($outOf / $weight, 1), '0'), '.')
                    . ' (' . ($pct >= 10 ? round($pct) : rtrim(rtrim(number_format($pct, 2), '0'), '.')) . '%)';
            }
            $rows .= '<tr><td>' . $name . '</td><td>' . ($itemId < 0 ? '-' : (int)$amount) . '</td><td>' . $rate . '</td></tr>';
        }
        if($total > 0) {
            $rows .= '<tr><td><i>Nothing (empty roll)</i></td><td>-</td><td>1 in ' . number_format($total + 1) . '</td></tr>';
        }
        return '<table class="data-table forum-table"><tr><th>Item</th><th>Amount</th><th>Rate</th></tr>' . $rows . '</table>';
    }

}
