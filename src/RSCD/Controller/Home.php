<?php

namespace RSCD\Controller;

use RSCD\View\ShopView;

/**
 * Main entry-point controller for the application.
 *
 * Every unmatched top-level HTTP request arrives here and renders the public
 * landing page (ui/html/home.html) — the 2003-style title screen with the
 * stone navigation buttons and the live community player counter.
 */
class Home extends \RSCD\Controller\ObjectController {

    /**
     * How long (seconds) after its last heartbeat a world still counts as
     * online. Matches rscd-api's stale_after so the website and the client
     * worlds list agree on which worlds are alive.
     */
    const WORLD_STALE_AFTER = 300;

    /**
     * Initialise with the public-facing view and run the standard
     * authorization check so $state->activeUser is populated for the layout.
     */
    public function initialize() {
        $this->set('view', new ShopView($this->get('app')));
        $this->authorize();
    }

    /**
     * Default action — render the landing page.
     *
     * @param object $state Application state.
     */
    public function processDefaultAction($state) {
        $state->request->action = 'home';
        $state->request->method = 'GET';
        return $this->httpGetHome($state);
    }

    /**
     * Render the landing page. Uses the standalone (chrome-less) page path:
     * the title screen carries its own logo and navigation, so the header
     * title-box and footer are omitted.
     *
     * @param object $state Application state.
     */
    protected function httpGetHome($state) {
        $home = $state->view->getViewLayout('home.html');
        $home->populateHtmlFromFile();

        $playing = $this->getCommunityPlayerCount($state);
        $line = $playing === 1
            ? 'There is currently 1 person playing!'
            : 'There are currently ' . number_format($playing) . ' people playing!';

        $state->view->setStandalonePage($home->get('html'), [
            'player_count_line' => $line,
        ], 'Home');
    }

    /**
     * Total players online across every community world, public or private.
     *
     * The community_worlds registry lives in the game database (heartbeat
     * rows written by rscd-api), not in this application's own schema, so
     * this is a cross-database query over the same MySQL connection. Every
     * registered server counts except blocked ones — probation servers are
     * the "registered but private" case and are deliberately included.
     * Worlds that missed their heartbeat window have gone quiet and are
     * excluded, mirroring the client worlds list.
     *
     * @param  object $state Application state.
     * @return int           Sum of online players, 0 when the registry is unreachable.
     */
    protected function getCommunityPlayerCount($state) {
        $database = $state->config->getProperty('gameDatabase');
        $database = str_replace('`', '', !empty($database) ? $database : 'rscd');
        try {
            $rows = \Illuminate\Database\Capsule\Manager::select(
                'SELECT COALESCE(SUM(w.online), 0) AS playing
                   FROM `' . $database . '`.community_worlds w
                   JOIN `' . $database . '`.community_servers s ON s.id = w.server_id
                  WHERE s.status <> ? AND w.last_seen >= UNIX_TIMESTAMP() - ?',
                ['blocked', static::WORLD_STALE_AFTER]
            );
            return (int)($rows[0]->playing ?? 0);
        } catch (\Throwable $e) {
            // The landing page must render even when the registry database
            // is missing or unreadable (fresh installs, standalone setups).
            return 0;
        }
    }

}
