<?php

namespace RSCD\Util;

/**
 * Network and HTTP request utilities.
 *
 * Provides helpers for resolving the true client IP address behind
 * Cloudflare and other reverse-proxy layers. All methods are static.
 */
class Networking {

    /**
     * Resolve the client's IP address from the server variable map.
     *
     * Checks headers in priority order:
     *  1. `HTTP_CF_CONNECTING_IP` — Cloudflare's real-IP header.
     *  2. `HTTP_CLIENT_IP` — forwarded by some proxies.
     *  3. `HTTP_X_FORWARDED_FOR` — standard proxy forwarding header.
     *  4. `REMOTE_ADDR` — the direct TCP connection address.
     *
     * If $server is null or not an array, the real $_SERVER superglobal
     * values are fetched via filter_input() with FILTER_SANITIZE_STRING.
     *
     * @param  array|null  $server  Server variable map, or null to read from PHP superglobals.
     * @return string                Client IP address, or `'unknown'` if none is found.
     */
    public static function getIpAddress($server = null) {
        if($server === null || !is_array($server)) {
            $server = [
                'HTTP_CF_CONNECTING_IP' => filter_input(INPUT_SERVER, 'HTTP_CF_CONNECTING_IP', FILTER_SANITIZE_STRING),
                'HTTP_CLIENT_IP'        => filter_input(INPUT_SERVER, 'HTTP_CLIENT_IP',        FILTER_SANITIZE_STRING),
                'HTTP_X_FORWARDED_FOR'  => filter_input(INPUT_SERVER, 'HTTP_X_FORWARDED_FOR',  FILTER_SANITIZE_STRING),
                'REMOTE_ADDR'           => filter_input(INPUT_SERVER, 'REMOTE_ADDR',           FILTER_SANITIZE_STRING),
            ];
        }
        if(!empty($server['HTTP_CF_CONNECTING_IP'])) {
            return $server['HTTP_CF_CONNECTING_IP'];
        }
        if(!empty($server['HTTP_CLIENT_IP'])) {
            return $server['HTTP_CLIENT_IP'];
        }
        if(!empty($server['HTTP_X_FORWARDED_FOR'])) {
            return $server['HTTP_X_FORWARDED_FOR'];
        }
        return !empty($server['REMOTE_ADDR']) ? $server['REMOTE_ADDR'] : 'unknown';
    }

    /**
     * Alias for {@see getIpAddress()}.
     *
     * @param  array|null  $server  Server variable map, or null to read from superglobals.
     * @return string                Client IP address.
     */
    public static function ipAddress($server = null) {
        return self::getIpAddress($server);
    }

    /**
     * Alias for {@see getIpAddress()}.
     *
     * @param  array|null  $server  Server variable map, or null to read from superglobals.
     * @return string                Client IP address.
     */
    public static function getClientIpAddress($server = null) {
        return self::getIpAddress($server);
    }

}
