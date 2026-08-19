<?php

namespace RSCD\Model\Encoder;

use \RSCD\Model\Common\Encoder;

/**
 * Hexadecimal encoder.
 *
 * Encodes raw byte strings to uppercase hexadecimal and decodes them back.
 * Uses PHP's bin2hex() / hex2bin() functions internally.
 */
class Hexadecimal extends Encoder {
    /**
     * Initialise the encoder.
     *
     * @param mixed|null $params Optional parameters (unused; accepted for interface compatibility).
     */
    public function __construct($params = null) {

    }

    /**
     * Encode a raw byte string to uppercase hexadecimal.
     *
     * @param  string|null $string The raw string to encode.
     * @return string|null Uppercase hex string, or null if input is empty.
     */
    public function encode($string = null) {
        if(empty($string)) {
            return null;
        }
        return mb_strtoupper(bin2hex($string), 'UTF-8');
    }

    /**
     * Decode a hexadecimal string back to the original raw byte string.
     *
     * @param  string|null $string The hex string to decode.
     * @return string|null Decoded binary string, or null if input is empty.
     */
    public function decode($string = null) {
        if(empty($string)) {
            return null;
        }

        return hex2bin($string);
    }
}
