<?php

namespace RSCD\Model\Object;

/**
 * Session model — tracks user login sessions for authentication.
 *
 * Extends the Roots framework Session base class which provides the underlying
 * table mapping, status scope, and standard fields (user_id, serial, status,
 * created_at, etc.).
 *
 * Sessions are identified by a serial token derived from a seed value and a
 * browser fingerprint built from stable HTTP headers. IP address is intentionally
 * excluded from the serial — mobile clients (iCloud Private Relay, etc.) rotate
 * IPs mid-session which would break authentication. IP is stored separately for
 * audit purposes only.
 *
 * Status values:
 *   1 = Active   — session is valid and usable
 *   2 = Terminated — user explicitly logged out
 *   3 = Expired  — session timed out
 */
class Session extends \RSCD\Model\Object\SessionBase {

    /** Session is valid and the user is authenticated. */
    const STATUS_ACTIVE = 1;

    /** Session was explicitly terminated (logout). */
    const STATUS_TERMINATED = 2;

    /** Session expired due to inactivity or time limit. */
    const STATUS_EXPIRED = 3;

    /**
     * Derive a deterministic session serial from a caller-supplied seed value.
     *
     * The seed is the sole input — browser fingerprint headers (User-Agent,
     * Accept-Language, Sec-CH-UA) were removed because Safari's Advanced
     * Fingerprinting Protection (AFP, default on iOS 26) normalises those headers
     * differently on XHR requests vs page navigations, causing the serial computed
     * at sign-in to not match on subsequent page loads and bouncing the user back
     * to sign-in. The random seed is the actual security mechanism; the headers
     * added no meaningful protection.
     *
     * @param  string  $seed  A caller-provided seed (e.g. a random token).
     * @return string          32-character hex MD5 hash usable as a session serial.
     */
    public static function findSerialWithSeed($seed) {
        return md5($seed);
    }

    /**
     * Look up an active session by its serial token.
     *
     * Applies the 'active' scope (STATUS_ACTIVE) defined on the parent class
     * and returns the first matching session or null if none is found.
     *
     * @param  string  $serial  The 32-character serial to look up.
     * @return static|null       Active Session model, or null if not found / expired.
     */
    public static function findWithSerial($serial) {
        return static::active()->where('serial', $serial)->first();
    }

}
