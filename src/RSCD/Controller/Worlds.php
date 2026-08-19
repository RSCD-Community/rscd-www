<?php

namespace RSCD\Controller;

use RSCD\View\ShopView;

/**
 * worlds.json — the community world list, served from this origin.
 *
 * The registry itself lives in rscd-api, which publishes the canonical list at
 * api.rscd-community.org/worlds.json. The desktop client fetches that directly
 * and always will: a Java HTTP client does not care which host answers.
 *
 * A browser does. The page is on rscd-community.org and the list is on
 * api.rscd-community.org — a different origin — so the fetch is subject to
 * CORS, and the API sends no Access-Control-Allow-Origin header. The browser
 * therefore discards a perfectly good 200 response before the client can read
 * a byte of it, and the Worlds screen shows "Could not reach ...". Nothing is
 * wrong with the API; the browser is refusing to hand over what it fetched.
 *
 * This endpoint removes the cross-origin hop entirely rather than depending on
 * another host's header policy. It is a cache in front of the API, not a second
 * implementation of it: the registry logic stays in rscd-api, which remains the
 * one place that knows how a listing is built.
 *
 * It answers with Access-Control-Allow-Origin itself, because a community site
 * hosting the browser client for a world list it does not own is exactly the
 * federation this project is for, and refusing them would be the same mistake
 * one layer down.
 */
class Worlds extends \RSCD\Controller\ObjectController {

    /** Where the canonical list is published. Override with the worldsApi config key. */
    const DEFAULT_API_URL = 'https://api.rscd-community.org/worlds.json';

    /**
     * How long a cached copy is served before going back upstream.
     *
     * Matches the Cache-Control the API sets on the file. Shorter would ask
     * more often than the list can change; longer would show a world as online
     * after it has gone.
     */
    const CACHE_SECONDS = 60;

    /** Give up on the API rather than hold a page request open. */
    const TIMEOUT_SECONDS = 5;

    /**
     * Initialise with a view the parent framework requires.
     *
     * Nothing is rendered through it — this controller writes JSON straight to
     * the response, the same way Assets streams files.
     */
    public function initialize() {
        $this->set('view', new ShopView($this->get('app')));
    }

    /**
     * Serve the world list.
     *
     * Fresh cache wins; otherwise fetch, and fall back to a stale cache if the
     * fetch fails. A stale list is worth more than no list — every entry in it
     * was true within the last few minutes, and the alternative is an empty
     * Worlds screen during a blip upstream.
     *
     * @param object $state Application state.
     */
    public function processDefaultAction($state) {
        $state = $this->getState();
        $cache = $this->cachePath($state);
        $age = is_file($cache) ? time() - (int)filemtime($cache) : PHP_INT_MAX;

        if($age <= static::CACHE_SECONDS) {
            return $this->output(file_get_contents($cache));
        }

        $url = $state->config->getProperty('worldsApi');
        $body = $this->fetch(!empty($url) ? $url : static::DEFAULT_API_URL);

        if($body !== null) {
            // Written beside the target and renamed, so a request arriving
            // mid-write reads the old copy rather than half of the new one.
            $temporary = $cache . '.' . getmypid() . '.tmp';
            if(@file_put_contents($temporary, $body) !== false) {
                @rename($temporary, $cache);
            }
            return $this->output($body);
        }

        if(is_file($cache)) {
            return $this->output(file_get_contents($cache), true);
        }

        // Nothing fresh and nothing kept. Say so with a status the client can
        // tell apart from an empty world list, which would be a lie.
        http_response_code(502);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: no-store');
        print json_encode(['error' => 'the world registry could not be reached']);
    }

    /**
     * GET the upstream list.
     *
     * Only a 200 whose body parses as an object carrying a servers array is
     * accepted. An error page, a captive-portal redirect or a truncated file
     * would otherwise be cached for a minute and served to every visitor as
     * though it were the world list.
     *
     * @param  string      $url Upstream URL.
     * @return string|null      Response body, or null on any failure.
     */
    protected function fetch($url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => static::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => static::TIMEOUT_SECONDS,
            CURLOPT_USERAGENT      => 'rscd-www worlds.json proxy',
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if($status !== 200 || !is_string($body) || $body === '') {
            return null;
        }

        $parsed = json_decode($body, true);
        if(!is_array($parsed) || !isset($parsed['servers']) || !is_array($parsed['servers'])) {
            return null;
        }

        return $body;
    }

    /**
     * Where the cached copy lives.
     *
     * The application's own tmp directory, resolved per environment from the
     * paths config, rather than a path built from the document root: on the
     * live host the two are not the same place, and the web user can only
     * write to the configured one.
     *
     * A failure to write there is not an error to the visitor. The list still
     * gets served — the cost is a fetch upstream on every request instead of
     * one a minute — so this returns a path and lets the caller carry on if it
     * turns out not to be writable.
     *
     * @param  object $state Application state.
     * @return string
     */
    protected function cachePath($state) {
        $tmp = (string)($state->tmp ?? '');
        if($tmp === '') {
            $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
        }
        return rtrim($tmp, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'rscd-worlds.json';
    }

    /**
     * Send a list body.
     *
     * @param string  $body  JSON.
     * @param boolean $stale Whether this came from a cache the API could not refresh.
     */
    protected function output($body, $stale = false) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: public, max-age=' . static::CACHE_SECONDS);
        if($stale) {
            // Not an error to a client, but the one place an operator can see
            // that the registry stopped answering without reading a log.
            header('X-Worlds-Stale: 1');
        }
        header('Content-Length: ' . strlen($body));
        print $body;
    }

}
