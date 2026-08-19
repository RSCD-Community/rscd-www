<?php

namespace RSCD\Model\Encrypter;

use \RSCD\Model\Common\Encrypter;

/**
 * AES encrypter backed by the OpenSSL extension.
 *
 * Supports CBC mode with 128-, 192-, and 256-bit keys. PKCS5 padding is
 * applied before encryption and stripped after decryption. Cipher, padding,
 * key, and IV are supplied via the $params config object.
 */
class OpenSSLAES extends Encrypter {
    protected $bits;
    protected $cipher;
    protected $padding;
    protected $key;
    protected $iv;

    /**
     * Configure the encrypter from the supplied parameter object.
     *
     * @param  object|null $params Config object with cipher, padding, key, and iv properties.
     * @throws \Exception If any required parameter is missing or empty.
     */
    public function __construct($params = null) {
        $this->set('bits', 0);
        if(empty($params)) {
            return;
        }
        $cipher = ! empty($params->cipher) ? $params->cipher : '';
        $padding = ! empty($params->padding) ? $params->padding : '';
        $key = ! empty($params->key) ? $params->key : '';
        $iv = ! empty($params->iv) ? $params->iv : '';
        if(empty($cipher) || empty($padding) || empty($key) || empty($iv)) {
            throw new \Exception('Encrypter is misconfigured.');
        }
        if(strlen($cipher) > 0 && strlen($padding) > 0) {
            $this->set('cipher', mb_strtoupper($cipher, 'UTF-8'));
            $this->set('padding', mb_strtoupper($padding, 'UTF-8'));
            $this->set('key', $key);
            $this->set('iv', $iv);
            $keyLength = strlen($this->get('key'));
            if($keyLength == 32) {
                $this->set('bits', 32);
            } else if($keyLength == 24) {
                $this->set('bits', 24);
            } else if($keyLength == 16) {
                $this->set('bits', 16);
            }
        }
    }

    /**
     * Encrypt a plaintext string using the configured AES cipher and mode.
     *
     * @param  string|null $string The plaintext to encrypt.
     * @return string|null The ciphertext, or null if the encrypter is not configured or input is empty.
     */
    public function encrypt($string = null) {
        if(empty(($bits = $this->get('bits'))) || $bits == 0 || empty($string)) {
            return null;
        }
        if(! empty(($cipher = $this->get('cipher'))) && $cipher == 'CBC') {
            if($bits == 32) {
                return openssl_encrypt($this->pad($string), "aes-256-cbc", $this->get('key'), OPENSSL_ZERO_PADDING, $this->get('iv'));
            }
            if($bits == 24) {
                return openssl_encrypt($this->pad($string), "aes-192-cbc", $this->get('key'), OPENSSL_ZERO_PADDING, $this->get('iv'));
            }
            if($bits == 16) {
                return openssl_encrypt($this->pad($string), "aes-128-cbc", $this->get('key'), OPENSSL_ZERO_PADDING, $this->get('iv'));
            }
        }
        if(! empty(($cipher = $this->get('cipher'))) && $cipher == 'ECB') {
            return null;
        }
        return null;
    }

    /**
     * Decrypt a ciphertext string using the configured AES cipher and mode.
     *
     * @param  string|null $string The ciphertext to decrypt.
     * @return string|null The plaintext, or null if the encrypter is not configured or input is empty.
     */
    public function decrypt($string = null) {
        if(empty(($bits = $this->get('bits'))) || $bits == 0 || empty($string)) {
            return null;
        }
        if(! empty(($cipher = $this->get('cipher'))) && $cipher == 'CBC') {
            if($bits == 32) {
                return $this->unpad(openssl_decrypt($string, "aes-256-cbc", $this->get('key'), OPENSSL_ZERO_PADDING, $this->get('iv')));
            }
            if($bits == 24) {
                return $this->unpad(openssl_decrypt($string, "aes-192-cbc", $this->get('key'), OPENSSL_ZERO_PADDING, $this->get('iv')));
            }
            if($bits == 16) {
                return $this->unpad(openssl_decrypt($string, "aes-128-cbc", $this->get('key'), OPENSSL_ZERO_PADDING, $this->get('iv')));
            }
        }
        if(! empty(($cipher = $this->get('cipher'))) && $cipher == 'EBC') {
            return null;
        }
        return null;
    }

    /**
     * Return a property value by name.
     *
     * @param  string|null $property Property name.
     * @return mixed The property value, or null if it does not exist.
     */
    public function get($property = null) {
        if(property_exists($this, $property)) {
            return $this->$property;
        }
        return null;
    }

    /**
     * Set a property value by name.
     *
     * @param  string|null $property Property name.
     * @param  mixed       $value    Value to assign.
     * @return $this
     */
    public function set($property = null, $value = null) {
        if(property_exists($this, $property)) {
            $this->$property = $value;
        }
        return $this;
    }

    /**
     * Apply PKCS5 padding to a string before encryption.
     *
     * @param  string $string The plaintext string.
     * @return string The padded string.
     */
    protected function pad($string) {
        if(empty(($bits = $this->get('bits'))) || $bits == 0) {
            return $string;
        }
        if(! empty(($padding = $this->get('padding'))) && $padding == 'PKCS5') {
            $pad = $bits - (strlen($string) % $bits);
            return $string . str_repeat(chr($pad), $pad);
        }
        return $string;
    }

    /**
     * Strip PKCS5 padding from a decrypted string.
     *
     * @param  string $string The padded plaintext string.
     * @return string The unpadded string.
     */
    protected function unpad($string) {
        if(empty(($bits = $this->get('bits'))) || $bits == 0) {
            return $string;
        }
        if(! empty(($padding = $this->get('padding'))) && $padding == 'PKCS5') {
            $pad = ord(substr($string, -1));
            if($pad > strlen($string)) {
                return $string;
            }
            if(strspn($string, substr($string, -1), strlen($string) - $pad) != $pad) {
                return $string;
            }
            return substr($string, 0, -1 * $pad);
        }
        return $string;
    }
}
