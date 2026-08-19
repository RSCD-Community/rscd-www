<?php

namespace RSCD\Model\MessageDigest;

use \RSCD\Model\Common\MessageDigest;

/**
 * MD5 message digest implementation.
 *
 * Produces an uppercase hexadecimal MD5 hash of the input string.
 * Intended for non-security-critical checksums and legacy compatibility;
 * do not use MD5 for new password hashing or cryptographic purposes.
 */
class MD5 extends MessageDigest {
    /**
     * Initialise the digest.
     *
     * @param mixed|null $params Optional parameters (unused; accepted for interface compatibility).
     */
    public function __construct($params = null) {

    }

    /**
     * Produce an uppercase MD5 hex digest of the given string.
     *
     * @param  string|null $string The input string to hash.
     * @return string|null Uppercase MD5 hex string, or null if input is empty.
     */
    public function digest($string = null) {
        if(empty($string)) {
            return null;
        }
        return mb_strtoupper(md5($string), 'UTF-8');
    }
}
