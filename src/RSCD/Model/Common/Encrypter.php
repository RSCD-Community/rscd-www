<?php

namespace RSCD\Model\Common;

/**
 * Abstract base class defining the encrypter contract.
 *
 * Concrete implementations provide symmetric encryption and decryption
 * using a specific cipher (e.g. AES-CBC via mcrypt or OpenSSL).
 */
abstract class Encrypter {
    /**
     * Encrypt a plaintext string.
     *
     * @param  string|null $string The plaintext string to encrypt.
     * @return string|null The ciphertext, or null on failure.
     */
    abstract public function encrypt($string = null);

    /**
     * Decrypt a ciphertext string.
     *
     * @param  string|null $string The ciphertext to decrypt.
     * @return string|null The plaintext, or null on failure.
     */
    abstract public function decrypt($string = null);
}
