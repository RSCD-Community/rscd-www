<?php

namespace RSCD\Model\Common;

/**
 * Abstract base class defining the encoder contract.
 *
 * Concrete implementations must provide encode() and decode() methods
 * to convert arbitrary byte strings to and from an encoded representation
 * (e.g. hexadecimal, base-64).
 */
abstract class Encoder {
    /**
     * Encode a raw string into the implementation-specific representation.
     *
     * @param  string|null $string The raw string to encode.
     * @return string|null The encoded string, or null on failure.
     */
    abstract public function encode($string = null);

    /**
     * Decode an implementation-specific representation back to the raw string.
     *
     * @param  string|null $string The encoded string to decode.
     * @return string|null The decoded raw string, or null on failure.
     */
    abstract public function decode($string = null);
}
