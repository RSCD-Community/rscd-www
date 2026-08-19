<?php

namespace RSCD\Model;

use \RSCD\Model\App;

/**
 * Static facade for the application's encoder, encrypter, and message-digest services.
 *
 * Call setup() once during the boot sequence (AppBase::basicSetup does this
 * automatically) to wire in the App container. After that, any code in the
 * request lifetime can call Mutator::encode(), ::decrypt(), ::digest(), etc.
 * without holding a reference to the App object.
 */
class Mutator {
    protected static $app;

    /**
     * Wire the Mutator to the application container.
     *
     * Idempotent: once the app is set, subsequent calls are silently ignored.
     *
     * @param  App $app The application container instance.
     * @return void
     */
    public static function setup(App $app) {
        if(empty(self::$app)) {
            self::$app = $app;
        }
    }

    /**
     * Encode a string using the application's configured encoder.
     *
     * @param  string|null $string The raw string to encode.
     * @return string|null The encoded string.
     * @throws \Exception If setup() has not been called or no encoder is configured.
     */
    public static function encode($string = null) {
        if(empty(self::$app)) {
            throw new \Exception('Mutator has not been setup', 500);
        }

        $encoder = self::$app->get('encoder');

        if(empty($encoder)) {
            throw new \Exception('Mutator invalid, App has no encoder', 501);
        }

        return $encoder->encode($string);
    }

    /**
     * Decode a string using the application's configured encoder.
     *
     * @param  string|null $string The encoded string to decode.
     * @return string|null The decoded raw string.
     * @throws \Exception If setup() has not been called or no encoder is configured.
     */
    public static function decode($string = null) {
        if(empty(self::$app)) {
            throw new \Exception('Mutator has not been setup', 500);
        }

        $encoder = self::$app->get('encoder');

        if(empty($encoder)) {
            throw new \Exception('Mutator invalid, App has no encoder', 501);
        }

        return $encoder->decode($string);
    }

    /**
     * Encrypt a string using the application's configured encrypter.
     *
     * @param  string|null $string The plaintext string to encrypt.
     * @return string|null The ciphertext.
     * @throws \Exception If setup() has not been called or no encrypter is configured.
     */
    public static function encrypt($string = null) {
        if(empty(self::$app)) {
            throw new \Exception('Mutator has not been setup', 500);
        }

        $encrypter = self::$app->get('encrypter');

        if(empty($encrypter)) {
            throw new \Exception('Mutator invalid, App has no encrypter', 502);
        }

        return $encrypter->encrypt($string);
    }

    /**
     * Decrypt a string using the application's configured encrypter.
     *
     * @param  string|null $string The ciphertext to decrypt.
     * @return string|null The plaintext.
     * @throws \Exception If setup() has not been called or no encrypter is configured.
     */
    public static function decrypt($string = null) {
        if(empty(self::$app)) {
            throw new \Exception('Mutator has not been setup', 500);
        }

        $encrypter = self::$app->get('encrypter');

        if(empty($encrypter)) {
            throw new \Exception('Mutator invalid, App has no encrypter', 502);
        }

        return $encrypter->decrypt($string);
    }

    /**
     * Produce a digest of a string using the application's configured message digest.
     *
     * @param  string|null $string The input string to hash.
     * @return string|null The digest string.
     * @throws \Exception If setup() has not been called or no message digest is configured.
     */
    public static function digest($string = null) {
        if(empty(self::$app)) {
            throw new \Exception('Mutator has not been setup', 500);
        }

        $messageDigest = self::$app->get('messageDigest');

        if(empty($messageDigest)) {
            throw new \Exception('Mutator invalid, App has no message digest', 503);
        }

        return $messageDigest->digest($string);
    }
}
