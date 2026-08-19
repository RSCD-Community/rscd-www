<?php

namespace RSCD\Model\Common;

/**
 * Abstract base class defining the message-digest (hash) contract.
 *
 * Concrete implementations produce a fixed-length digest from an arbitrary
 * input string (e.g. MD5, SHA-256).
 */
abstract class MessageDigest {
    /**
     * Produce a digest (hash) of the given string.
     *
     * @param  string|null $string The input string to digest.
     * @return string|null The digest string, or null on failure.
     */
    abstract public function digest($string = null);
}
