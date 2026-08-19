<?php

namespace RSCD\Util;

/**
 * HTTP cookie helpers.
 *
 * Wraps PHP's setcookie() with sensible defaults for this application and
 * keeps $_COOKIE in sync so that values written in the same request are
 * immediately readable without a page reload. All methods are static.
 */
class Cookies {

    /**
     * Set a cookie and immediately populate $_COOKIE with the value.
     *
     * @param  string  $key        Cookie name.
     * @param  mixed   $value      Cookie value.
     * @param  string  $directory  Cookie path (default `''` = root).
     * @param  string  $domain     Cookie domain (default `''` = current domain).
     * @param  int     $expires    Lifetime in seconds from now (default 1 year).
     * @return bool                 True on success, false if $key is empty or setcookie() fails.
     */
    public static function create($key = null, $value = null, $directory = '', $domain = '', $expires = 31536000) {
        if(empty($key)) {
            return false;
        }
        // Secure mirrors the actual connection: browsers reject Secure cookies
        // set over plain HTTP, which would silently break local development.
        $secure = !empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off';
        if(setcookie($key, $value, [
            'expires'  => time() + $expires,
            'path'     => $directory,
            'domain'   => $domain ?: '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ])) {
            $_COOKIE[$key] = $value;
            return true;
        }
        return false;
    }

    /**
     * Delete a cookie by setting its expiry in the past, and unset it from $_COOKIE.
     *
     * @param  string  $key        Cookie name.
     * @param  string  $directory  Cookie path (must match the path used when the cookie was set).
     * @param  string  $domain     Cookie domain (must match the domain used when the cookie was set).
     * @return bool                 True on success, false if $key is empty or setcookie() fails.
     */
    public static function delete($key = null, $directory = '', $domain = '') {
        if(empty($key)) {
            return false;
        }
        $secure = !empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off';
        if(setcookie($key, '', [
            'expires'  => time() - 31536000,
            'path'     => $directory,
            'domain'   => $domain ?: '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ])) {
            if(isset($_COOKIE[$key])) {
                unset($_COOKIE[$key]);
            }
            return true;
        }
        return false;
    }

}
