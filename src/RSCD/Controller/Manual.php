<?php

namespace RSCD\Controller;

use RSCD\Model\GameData;
use RSCD\View\ShopView;

/**
 * The game manual.
 *
 * The quests section is generated from Data/manual-quests.php, which
 * tools/generate-game-data.py writes from the quest classes' own define()
 * declarations — the same declarations Quest.grantRewards() pays out of —
 * so the manual can never disagree with what completing a quest actually
 * does. No database is involved.
 *
 * The rest of the manual is the December 2002 manual itself: page
 * fragments sanitised from the Wayback Machine mirror by
 * tools/build-manual.py (see Data/manual-pages.php for the slug list),
 * served verbatim inside the site layout.
 *
 * Pages:
 *   GET /manual/                          the 2003 manual index
 *   GET /manual/controls/name%3D<page>/   an archived Controls page
 *   GET /manual/guides/name%3D<page>/     an archived Guides page
 *   GET /manual/skills/name%3D<page>/     an archived Skills page
 *   GET /manual/worldmap/                 the 2003 world map
 *   GET /manual/quests/                   quest index, standard and members
 *   GET /manual/quest/name%3D<slug>/      one quest's page
 */
class Manual extends \RSCD\Controller\ObjectController {

    /**
     * Initialise with the public-facing view.
     */
    public function initialize() {
        $this->set('view', new ShopView($this->get('app')));
    }

    /**
     * Default action — the 2003 manual index page.
     *
     * @param object $state Application state.
     */
    public function processDefaultAction($state) {
        $this->authorize();
        return $this->servePage($this->getState(), 'index');
    }

    /**
     * An archived Controls page.
     *
     * @param object $state Application state.
     */
    protected function httpGetControls($state) {
        $this->authorize();
        $state = $this->getState();
        return $this->servePage($state, 'controls/' . trim((string)$state->url->getVariable('name')));
    }

    /**
     * An archived Guides page.
     *
     * @param object $state Application state.
     */
    protected function httpGetGuides($state) {
        $this->authorize();
        $state = $this->getState();
        return $this->servePage($state, 'guides/' . trim((string)$state->url->getVariable('name')));
    }

    /**
     * An archived Skills page.
     *
     * @param object $state Application state.
     */
    protected function httpGetSkills($state) {
        $this->authorize();
        $state = $this->getState();
        return $this->servePage($state, 'skills/' . trim((string)$state->url->getVariable('name')));
    }

    /**
     * An archived About RuneScape page.
     *
     * @param object $state Application state.
     */
    protected function httpGetAbout($state) {
        $this->authorize();
        $state = $this->getState();
        return $this->servePage($state, 'about/' . trim((string)$state->url->getVariable('name')));
    }

    /**
     * The 2003 world map: the archived map10.gif in a scrollable pane
     * with the archived map key. The original page panned the map with
     * a frames-era script; a scrolling viewport does the same job.
     *
     * @param object $state Application state.
     */
    protected function httpGetWorldmap($state) {
        $this->authorize();
        $state = $this->getState();
        $page = $state->view->getViewLayout('manual' . DIRECTORY_SEPARATOR . 'worldmap.html');
        $page->populateHtmlFromFile();
        $state->view->setPage($page->get('html'), [], 'World map - Manual');
    }

    /**
     * One sanitised 2003 page, wrapped in the site layout.
     *
     * The slug is validated against the generated manifest — only pages
     * the build wrote can ever be read, so the name variable can never
     * reach the filesystem as a path.
     *
     * @param object $state Application state.
     * @param string $slug  Manifest key, e.g. 'index' or 'skills/herblaw'.
     */
    protected function servePage($state, $slug) {
        $pages = GameData::manualPages();
        if(!isset($pages[$slug])) {
            return $state->app->redirect($state->url->getBaseUrl() . 'manual/');
        }
        $file = __ROOTS__ . 'ui' . DIRECTORY_SEPARATOR . 'html' . DIRECTORY_SEPARATOR . 'manual'
            . DIRECTORY_SEPARATOR . 'site' . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $slug) . '.html';
        $page = $state->view->getViewLayout('manual' . DIRECTORY_SEPARATOR . 'page.html');
        $page->populateHtmlFromFile();
        $page->injectHtml('content', file_get_contents($file));
        $state->view->setPage($page->get('html'), [],
            ($slug === 'index' ? 'Manual' : $pages[$slug] . ' - Manual'));
    }

    /**
     * The quest index, split the way the 2003 manual split it.
     *
     * A minigame or miniquest (a non-vanilla quest class) is listed only
     * once it declares a description — the records the server also stores
     * as quest classes (gang membership, god spell charges) never do.
     *
     * @param object $state Application state.
     */
    protected function httpGetQuests($state) {
        $this->authorize();
        $state = $this->getState();
        $base = $state->url->getBaseUrl();

        $standard = [];
        $members = [];
        $extras = [];
        foreach(GameData::manualQuests() as $slug => $quest) {
            if(!empty($quest['vanilla'])) {
                if(!empty($quest['members'])) {
                    $members[$slug] = $quest['name'];
                }
                else {
                    $standard[$slug] = $quest['name'];
                }
            }
            else if($quest['description'] !== '') {
                $extras[$slug] = $quest['name'];
            }
        }

        $list = function($quests) use ($base) {
            $html = '';
            foreach($quests as $slug => $name) {
                $html .= '<a href="' . $base . 'manual/quest/name%3D' . $slug . '/">'
                    . htmlspecialchars($name, ENT_QUOTES) . '</a><br />';
            }
            return $html !== '' ? $html : '<i>None yet.</i>';
        };

        $page = $state->view->getViewLayout('manual' . DIRECTORY_SEPARATOR . 'quests.html');
        $page->populateHtmlFromFile();
        $page->injectHtml('standard', $list($standard));
        $page->injectHtml('members', $list($members));
        $page->injectHtml('extras', $extras !== []
            ? '<h3>Miniquests and minigames</h3><div class="manual-quest-list">' . $list($extras) . '</div>'
            : '');
        $state->view->setPage($page->get('html'), [], 'Quests - Manual');
    }

    /**
     * One quest's page: the 2003 manual's lines, then the declared
     * requirements and rewards.
     *
     * @param object $state Application state.
     */
    protected function httpGetQuest($state) {
        $this->authorize();
        $state = $this->getState();
        $base = $state->url->getBaseUrl();
        $slug = trim((string)$state->url->getVariable('name'));
        $quest = GameData::manualQuest($slug);
        if($quest === null) {
            return $state->app->redirect($base . 'manual/quests/');
        }

        $requirements = [];
        foreach($quest['req_levels'] as $req) {
            $requirements[] = 'Level ' . (int)$req[1] . ' ' . htmlspecialchars(GameData::skillName($req[0]), ENT_QUOTES);
        }
        foreach($quest['req_quests'] as $reqSlug) {
            $required = GameData::manualQuest($reqSlug);
            $requirements[] = 'Completion of <a href="' . $base . 'manual/quest/name%3D' . $reqSlug . '/">'
                . htmlspecialchars($required !== null ? $required['name'] : $reqSlug, ENT_QUOTES) . '</a>';
        }
        foreach($quest['req_other'] as $text) {
            $requirements[] = htmlspecialchars($text, ENT_QUOTES);
        }

        $rewards = [];
        if($quest['points'] > 0) {
            $rewards[] = (int)$quest['points'] . ' quest point' . ($quest['points'] === 1 ? '' : 's');
        }
        foreach($quest['reward_items'] as $reward) {
            $rewards[] = (int)$reward[1] . ' x ' . htmlspecialchars(GameData::itemName($reward[0]), ENT_QUOTES);
        }
        foreach($quest['reward_exp'] as $reward) {
            // base + perLevel per level of the skill: RSC quest xp grows
            // with the level you finish at, and the manual says so honestly
            $skill = htmlspecialchars(GameData::skillName($reward[0]), ENT_QUOTES);
            $rewards[] = $reward[2] > 0
                ? (int)$reward[1] . ' ' . $skill . ' experience, plus ' . (int)$reward[2] . ' per ' . $skill . ' level you have'
                : (int)$reward[1] . ' ' . $skill . ' experience';
        }
        foreach($quest['reward_other'] as $text) {
            $rewards[] = htmlspecialchars($text, ENT_QUOTES);
        }

        $lines = '<table class="data-table manual-quest-lines">'
            . '<tr><th>Start point</th><td>' . ($quest['start'] !== '' ? htmlspecialchars($quest['start'], ENT_QUOTES) : '-') . '</td></tr>'
            . '<tr><th>Speak to</th><td>' . ($quest['speak'] !== '' ? htmlspecialchars($quest['speak'], ENT_QUOTES) : '-') . '</td></tr>'
            . '<tr><th>Mission length</th><td>' . ($quest['length'] !== '' ? htmlspecialchars($quest['length'], ENT_QUOTES) : '-') . '</td></tr>'
            . '<tr><th>Minimum requirements</th><td>' . ($requirements !== [] ? implode('<br />', $requirements) : 'None') . '</td></tr>'
            . '<tr><th>Members only</th><td>' . (!empty($quest['members']) ? 'Yes' : 'No') . '</td></tr>'
            . '<tr><th>Rewards</th><td>' . ($rewards !== [] ? implode('<br />', $rewards) : '-') . '</td></tr>'
            . '</table>';

        $page = $state->view->getViewLayout('manual' . DIRECTORY_SEPARATOR . 'quest.html');
        $page->populateHtmlFromFile();
        $page->injectHtml('quest.name', htmlspecialchars($quest['name'], ENT_QUOTES));
        $page->injectHtml('quest.desc', $quest['description'] !== ''
            ? '<p class="auth-hint">' . htmlspecialchars($quest['description'], ENT_QUOTES) . '</p>'
            : '');
        $page->injectHtml('lines', $lines);
        $state->view->setPage($page->get('html'), [], $quest['name'] . ' - Manual');
    }

}
