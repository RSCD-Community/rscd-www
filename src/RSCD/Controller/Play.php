<?php

namespace RSCD\Controller;

use Illuminate\Database\Capsule\Manager as Capsule;
use RSCD\Model\App;
use RSCD\Util\Strings;
use RSCD\View\ShopView;

/**
 * Play Game — the web version of the client's Worlds menu.
 *
 * Reads the same community_worlds/community_servers registry the client
 * does (heartbeat rows written by rscd-api in the game database), shows
 * every listed server's worlds, and renders each online world's Join Now
 * as an rscd:// link. The installed client registers the rscd:// scheme
 * on Windows, Linux and macOS, so following a link opens the client
 * pointed at that world. The link carries host:port plus a name query
 * parameter, which is all the client's Config.applyJoinUri reads — the
 * name labels the sign-in screen; any protocol check happens against the
 * live server on login, not against anything in the link.
 *
 * Probation servers ("registered private") are counted in the summary
 * line but never listed — same policy as the landing-page counter.
 */
class Play extends \RSCD\Controller\ObjectController {

    /** Seconds after the last heartbeat before a world reads as offline. */
    const WORLD_STALE_AFTER = 300;

    /**
     * Where webclient/build.sh deploys the browser client, relative to the
     * document root. Its presence is what decides whether this page offers
     * browser play at all — see browserClientAvailable().
     */
    const BROWSER_CLIENT_DIR = 'browser';

    /**
     * Path this site's Apache vhost proxies to the world's ws_port.
     *
     * Matches the ProxyPass in 011-rscd-community.org-ssl.conf. Change one and
     * the other stops being true — browser play breaks with a connect failure
     * and nothing else to go on, because the client cannot tell a missing
     * proxy from a stopped world.
     */
    const BROWSER_BRIDGE_PATH = '/ws';

    /**
     * Initialise with the public-facing view.
     */
    public function initialize() {
        $this->set('view', new ShopView($this->get('app')));
    }

    /**
     * Default action — the worlds list.
     *
     * @param object $state Application state.
     */
    public function processDefaultAction($state) {
        $this->authorize();
        $state = $this->getState();

        $database = $state->config->getProperty('gameDatabase');
        $database = str_replace('`', '', !empty($database) ? $database : 'rscd');
        $servers = [];
        $privateCount = 0;
        try {
            $servers = Capsule::select(
                'SELECT s.name, s.host, s.protocol, w.world, w.port, w.online, w.capacity,
                        (w.last_seen >= UNIX_TIMESTAMP() - ?) AS up
                   FROM `' . $database . '`.community_servers s
                   JOIN `' . $database . '`.community_worlds w ON w.server_id = s.id
                  WHERE s.status = ?
                    AND w.verified_at > 0
                  ORDER BY s.name, w.world',
                [static::WORLD_STALE_AFTER, 'listed']
            );
            $rows = Capsule::select(
                'SELECT COUNT(*) AS registered FROM `' . $database . '`.community_servers WHERE status = ?',
                ['probation']
            );
            $privateCount = (int)($rows[0]->registered ?? 0);
        } catch (\Throwable $e) {
            // Render the page with an empty list when the registry database
            // is missing (fresh installs, standalone setups).
        }

        $page = $state->view->getViewLayout('play' . DIRECTORY_SEPARATOR . 'index.html');
        $page->populateHtmlFromFile();
        $page->injectHtml('listing', $this->buildWorldsHtml($servers));
        $page->injectHtml('browser_line', $this->browserClientAvailable()
            ? '<p class="auth-hint"><b>Play in Browser</b> runs the same client on this page &mdash; '
                . 'nothing to install.  It needs the server to be running its WebSocket bridge, so '
                . 'not every world will accept it; the desktop client always will.</p>'
            : '');
        $page->injectHtml('private_line', $privateCount > 0
            ? '<p class="auth-hint">' . $privateCount . ' more '
                . ($privateCount === 1 ? 'server is' : 'servers are')
                . ' registered privately and not shown here.</p>'
            : '');
        $state->view->setPage($page->get('html'), [], 'Play Game');
    }

    /**
     * /play/browser — the browser client, inside the site.
     *
     * The client is the same compiled JavaScript either way; what changes is
     * the page around it. webclient/web/index.html is a standalone page for
     * operators who host the client on something that is not rscd-www, and it
     * looks like nothing else here because it cannot depend on anything here.
     * Reached from this site it was a dead end: no header, no footer, and no
     * way back to the rest of the site except the browser's Back button.
     *
     * So this action renders the same canvas through the normal view, which
     * means the 2003 frame, the nav, the footer and the stylesheet come for
     * free and keep matching the site when any of them change. The standalone
     * page stays exactly as it was, for the hosts that need it.
     *
     * Everything the client needs is in the query string it was given, and it
     * reads that itself from location.search — this action passes nothing to
     * it and only uses ?server= and ?port= to say which world the player is
     * looking at.
     *
     * @param object $state Application state.
     */
    protected function httpGetBrowser($state) {
        $this->authorize();
        $state = $this->getState();

        // A checkout that has not run webclient/build.sh has no client to
        // show. The Play page hides its buttons in that case, so this is only
        // reachable by hand — send them where the buttons would have been.
        if(!$this->browserClientAvailable()) {
            return $state->app->redirect($state->url->getBaseUrl() . 'play/');
        }

        $host = (string)$state->url->getVariable('server');
        $port = (int)$state->url->getVariable('port');
        $world = $this->findWorld($state, $host, $port);

        if($world !== null) {
            $title = Strings::displayText($world->name) . ' &mdash; World ' . (int)$world->world;
            $note = !empty($world->up) ? '' : '<p class="alert alert-warning">This world is not '
                . 'answering right now, so sign-in will fail.  It may be restarting &mdash; the '
                . '<a href="' . '[{url.base}]play/">worlds list</a> shows when it is back.</p>';
        } elseif($host !== '') {
            // Not in the registry: a hand-written link, or a world that has
            // delisted since the page was opened. Playing it is still the
            // player's call — this is a client, not a gate.
            $title = Strings::displayText($host . ($port > 0 ? ':' . $port : ''));
            $note = '<p class="alert alert-warning">This world is not listed in the community '
                . 'registry.  It will still connect if its operator is running it.</p>';
        } else {
            // No ?server= at all: the client opens on its own Worlds screen and
            // the player picks from there, which is the honest default.
            $title = 'Play in Browser';
            $note = '';
        }

        /*
         * Which world the bridge URL below belongs to.
         *
         * With ?server=/?port= it is the world being opened. Without them the
         * player is going to the client's own Worlds screen, and this page
         * still knows one thing worth telling it: where THIS site's bridge is.
         * Naming the world that URL describes is what makes it safe to hand
         * over unasked — the client uses it for our world and ignores it for
         * everyone else's.
         *
         * Leaving it out was a real failure, not a missing nicety: a player
         * who opened /play/browser and picked this world from the list got no
         * bridge URL at all, fell back to the port+1 default, and could not
         * connect, because this site's bridge is fronted at /ws on 443 and
         * 43595 is not open to the internet.
         */
        $bridgeHost = $host !== '' ? $host : $this->siteHost();
        $bridgePort = $port > 0 ? $port : $this->ownWorldPort($state, $bridgeHost);

        $page = $state->view->getViewLayout('play' . DIRECTORY_SEPARATOR . 'browser.html');
        $page->populateHtmlFromFile();
        $page->injectHtml('world_title', $title);
        $page->injectHtml('world_note', $note);
        // Empty for anyone else's world; the client then uses its own default.
        // See bridgeUrl() for why this site refuses to answer for other hosts.
        $page->injectHtml('ws_url', htmlspecialchars((string)$this->bridgeUrl($bridgeHost), ENT_QUOTES));
        $page->injectHtml('ws_host', htmlspecialchars((string)$bridgeHost, ENT_QUOTES));
        $page->injectHtml('ws_port', (string)(int)$bridgePort);
        $page->injectHtml('client_assets', static::clientVersion());
        $state->view->setPage($page->get('html'), [], 'Play in Browser');
    }

    /**
     * The hostname this page was requested on, without any port.
     *
     * @return string Hostname, or '' if the request carried no Host header.
     */
    protected function siteHost() {
        return preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    }

    /**
     * $host without a leading "www." label, for same-site comparisons.
     *
     * The registry (community_servers.host) and the "Play in Browser" link
     * both carry one canonical hostname per server — here, the bare
     * "rscd-community.org", with no separate "www." row. A visitor on
     * www.rscd-community.org clicking that link sends this page
     * ?server=rscd-community.org, which then fails every exact host==host
     * check against $_SERVER['HTTP_HOST'] ("www.rscd-community.org"):
     * ownWorldPort() and findWorld() silently return nothing, and
     * bridgeUrl() returns null, which ships the client an empty ws_url. The
     * override chain then falls through to the client's port+1 guess, which
     * this site's Apache never proxies — a WebSocket connect that nothing
     * answers, so the player sits on the sign-in screen for the full 30s
     * CONNECT_TIMEOUT_MS before it fails. Reproduced 2026-08-08 end to end.
     *
     * @param  string $host Hostname to normalize.
     * @return string
     */
    protected function bareHost($host) {
        $host = strtolower((string)$host);
        return (strncmp($host, 'www.', 4) === 0) ? substr($host, 4) : $host;
    }

    /**
     * The game port of this site's own first world.
     *
     * Which world the site's single /ws bridge fronts. A server running four
     * worlds runs four bridges and would need four paths; until it does, the
     * honest answer is "world 1 and no other", and the rest fall back to the
     * client's own default rather than being told something wrong.
     *
     * @param  object  $state Application state.
     * @param  string  $host  This site's hostname.
     * @return integer        Port, or 0 if this host lists no world.
     */
    protected function ownWorldPort($state, $host) {
        if($host === '') {
            return 0;
        }

        $database = $state->config->getProperty('gameDatabase');
        $database = str_replace('`', '', !empty($database) ? $database : 'rscd');
        try {
            // s.host holds one canonical form per server (see bareHost()) —
            // matched against both the given host and its bare form so a
            // "www." visitor still finds the same row a bare visitor does.
            $rows = Capsule::select(
                'SELECT w.port
                   FROM `' . $database . '`.community_servers s
                   JOIN `' . $database . '`.community_worlds w ON w.server_id = s.id
                  WHERE s.status = ?
                    AND w.verified_at > 0
                    AND (s.host = ? OR s.host = ?)
                  ORDER BY w.world ASC
                  LIMIT 1',
                ['listed', $host, $this->bareHost($host)]
            );
            return !empty($rows) ? (int)$rows[0]->port : 0;
        } catch (\Throwable $e) {
            // Same as everywhere else here: no registry means a page with less
            // on it, never a page that refuses to load.
            return 0;
        }
    }

    /**
     * The registry row for one host and port, if it is listed.
     *
     * Used only to label the page. A miss is not an error — see httpGetBrowser.
     *
     * @param  object      $state Application state.
     * @param  string      $host  Server hostname from the query string.
     * @param  integer     $port  Game port from the query string.
     * @return object|null        Registry row, or null when not listed.
     */
    protected function findWorld($state, $host, $port) {
        if($host === '' || $port <= 0) {
            return null;
        }

        $database = $state->config->getProperty('gameDatabase');
        $database = str_replace('`', '', !empty($database) ? $database : 'rscd');
        try {
            $rows = Capsule::select(
                'SELECT s.name, w.world,
                        (w.last_seen >= UNIX_TIMESTAMP() - ?) AS up
                   FROM `' . $database . '`.community_servers s
                   JOIN `' . $database . '`.community_worlds w ON w.server_id = s.id
                  WHERE s.status = ?
                    AND w.verified_at > 0
                    AND (s.host = ? OR s.host = ?)
                    AND w.port = ?
                  LIMIT 1',
                [static::WORLD_STALE_AFTER, 'listed', $host, $this->bareHost($host), $port]
            );
            return !empty($rows) ? $rows[0] : null;
        } catch (\Throwable $e) {
            // Same as the worlds list: no registry is a page without names on
            // it, not a page that refuses to load.
            return null;
        }
    }

    /**
     * The worlds table, one row per world of every listed server.
     *
     * @param  array  $servers Registry rows from processDefaultAction().
     * @return string          HTML.
     */
    protected function buildWorldsHtml($servers) {
        if(empty($servers)) {
            return '<p>No community worlds are listed right now.  Check back soon!</p>';
        }
        $browser = $this->browserClientAvailable();
        $rows = '';
        foreach($servers as $row) {
            $up = !empty($row->up);
            $join = 'rscd://' . rawurlencode($row->host) . ':' . (int)$row->port . '/'
                . '?name=' . rawurlencode($row->name)
                . '&world=' . (int)$row->world
                . '&protocol=' . (int)$row->protocol;
            $rows .= '<tr>'
                . '<td><b>' . Strings::displayText($row->name) . '</b></td>'
                . '<td>World ' . (int)$row->world . '</td>'
                . '<td>' . ($up
                    ? (int)$row->online . ' / ' . (int)$row->capacity . ' players'
                    : '-') . '</td>'
                . '<td>' . ($up ? 'Online' : '<i>Offline</i>') . '</td>'
                . '<td>' . ($up
                    ? '<a class="btn btn-primary" href="' . htmlspecialchars($join, ENT_QUOTES) . '">Join Now</a>'
                    : '') . '</td>'
                . ($browser
                    ? '<td>' . ($up ? $this->browserLink($row) : '') . '</td>'
                    : '') . '</tr>';
        }
        return '<table class="data-table forum-table"><tr><th>Server</th><th>World</th><th>Players</th><th>Status</th><th></th>'
            . ($browser ? '<th></th>' : '') . '</tr>' . $rows . '</table>';
    }

    /**
     * The browser-client button for one world.
     *
     * The page is the same client compiled to JavaScript, so it takes the same
     * two facts the rscd:// link carries: which host, and which port. It gets
     * them as ?server= and ?port=, which override the client's own settings;
     * ?target= on top of that is what skips the Worlds screen, so the player
     * lands on sign-in for the world they clicked rather than on a list they
     * have just finished reading.
     *
     * The WebSocket port is not passed and is not in the registry — the client
     * derives it as port+1, the shipped relationship on both sides, and an
     * operator whose bridge sits elsewhere fronts it there in their proxy.
     *
     * The link goes to /play/browser, not to browser/index.html: the latter is
     * the standalone page and has none of this site around it. See
     * httpGetBrowser().
     *
     * @param  object $row Registry row.
     * @return string      HTML.
     */
    protected function browserLink($row) {
        $host = (string)$row->host;
        $port = (int)$row->port;
        $url = '[{url.base}]play/browser'
            . '?server=' . rawurlencode($host)
            . '&port=' . $port
            . '&target=' . rawurlencode($host . ':' . $port);
        return '<a class="btn" href="' . htmlspecialchars($url, ENT_QUOTES) . '">Play in Browser</a>';
    }

    /**
     * Where this site fronts its own world's WebSocket bridge, if it does.
     *
     * A browser cannot open a raw TCP socket, so the browser client speaks the
     * game protocol over a WebSocket that the world unwraps on ws_port. That
     * port is not reachable directly on a TLS site — a page served over https
     * is forbidden to open a plain ws:// socket at all — so Apache terminates
     * TLS and reverse-proxies wss://<this host>/ws to it.
     *
     * Answers only for worlds hosted on this same host, and that restraint is
     * the point: this site knows exactly one bridge, its own. Handing that URL
     * out for somebody else's world would dial our world while claiming to be
     * theirs — a wrong connection that looks like a working one. Returning
     * null instead leaves the client on the next source it has, which fails
     * honestly if it has none.
     *
     * Answering for our own world only is still correct now that the client
     * reads a per-world ws_url from the registry: this page is the more
     * specific statement of the two (it knows how its own bridge is fronted
     * right now, without a heartbeat round trip), and the client tries it
     * first for exactly that reason. Everyone else's world is the registry's
     * business, not this site's.
     *
     * @param  string      $host World's advertised host.
     * @return string|null       Bridge URL, or null if this is not our world.
     */
    protected function bridgeUrl($host) {
        $siteHost = $this->siteHost();
        if($siteHost === '' || $this->bareHost($siteHost) !== $this->bareHost((string)$host)) {
            return null;
        }
        $secure = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') == 443);
        return ($secure ? 'wss://' : 'ws://') . $siteHost . static::BROWSER_BRIDGE_PATH;
    }

    /**
     * Whether the browser client has been built into this deployment.
     *
     * webclient/build.sh generates browser/rscd-client.js; nothing in the
     * repository does, because it is build output (see .gitignore). A checkout
     * that has not been built has no browser client to offer, and offering one
     * anyway would be a button that 404s — so the whole column disappears
     * instead.
     *
     * @return boolean
     */
    protected function browserClientAvailable() {
        return is_file(__ROOTS__ . static::BROWSER_CLIENT_DIR . DIRECTORY_SEPARATOR . 'rscd-client.js');
    }

    /**
     * Cache-busting stamp for the browser client's scripts: the newest
     * modification time across browser/.
     *
     * PageView::assetVersion() already does this for the stylesheets, and its
     * comment explains why — deploying a file has to be the same action as
     * invalidating the cached copy, or a fix ships to nobody who has already
     * visited. The browser client was left out of that, and it is the worst
     * file to leave out: rscd-client.js is 800KB of compiled output that
     * changes on every client build, it is served with no Cache-Control and no
     * Expires, and a browser with no freshness information of its own falls
     * back to a heuristic — typically a tenth of the time since Last-Modified
     * — during which it will not even revalidate.
     *
     * The failure that produces is the worst kind: the site is fine, the
     * bridge is fine, the world is fine, and one returning visitor is running
     * a build from before any of it worked. Nothing in a log says so, and
     * "clear your cache" is not a thing players should have to know.
     *
     * Separate stamp from the stylesheets' on purpose. They are built by
     * different things at different times, and sharing one would mean a CSS
     * tweak forcing everyone to re-download 800KB of client.
     *
     * Falls back to the release version if browser/ cannot be read, which is
     * the same thing assetVersion() does and still renders a page.
     *
     * @return string
     */
    protected static function clientVersion() {
        static $stamp = null;
        if($stamp !== null) {
            return $stamp;
        }

        $newest = 0;
        $pattern = __ROOTS__ . static::BROWSER_CLIENT_DIR . DIRECTORY_SEPARATOR . '*.js';
        foreach(glob($pattern) ?: [] as $file) {
            $mtime = @filemtime($file);
            if($mtime !== false && $mtime > $newest) {
                $newest = $mtime;
            }
        }

        $stamp = $newest > 0 ? App::VERSION . '.' . $newest : App::VERSION;
        return $stamp;
    }

}
