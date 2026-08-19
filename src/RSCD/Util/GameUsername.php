<?php

namespace RSCD\Util;

/**
 * RuneScape Classic base-37 username hashing.
 *
 * The game database keys every player row on `user` — the RSC base-37 hash of
 * the character name, stored as a decimal string. The encoding is the one the
 * original client and server both use: lowercase the name, collapse anything
 * that is not a letter or digit to a space, trim, cap at 12 characters, then
 * fold each character into the accumulator as hash*37 + value where letters
 * are 1-26, digits are 27-36, and a space is 0.
 *
 * A 12-character name of all digits tops out at 37^12 ≈ 6.6e18 in theory, but
 * the leading character can never make the total exceed ~4.75e18, which fits
 * comfortably in PHP's signed 64-bit int — so this is native integer math
 * (bcmath, which the legacy site used, is deliberately not required).
 */
class GameUsername {

    /** Maximum encodable name length, per the original protocol. */
    const MAX_LENGTH = 12;

    /**
     * Encode a character name to its base-37 hash.
     *
     * @param  string $username Raw character name as typed.
     * @return int              Base-37 hash; 0 for an empty/unencodable name.
     */
    public static function encode($username) {
        $clean = strtolower(trim($username));
        $hash = 0;
        $length = min(strlen($clean), static::MAX_LENGTH);
        for($i = 0; $i < $length; $i++) {
            $c = $clean[$i];
            $hash *= 37;
            if($c >= 'a' && $c <= 'z') {
                $hash += 1 + ord($c) - ord('a');
            }
            else if($c >= '0' && $c <= '9') {
                $hash += 27 + ord($c) - ord('0');
            }
            // Anything else counts as a space: contributes 0.
        }
        return $hash;
    }

    /**
     * Decode a base-37 hash back to its canonical display name.
     *
     * Mirrors the client's hash-to-name routine: digits come back as digits,
     * letters as lowercase with the first letter of each word capitalised,
     * zeros as the spaces between words.
     *
     * @param  int|string $hash Base-37 hash (decimal string or int).
     * @return string           Canonical character name.
     */
    public static function decode($hash) {
        $hash = (int)$hash;
        if($hash < 0) {
            return 'invalid_name';
        }
        $username = '';
        while($hash != 0) {
            $i = $hash % 37;
            $hash = intdiv($hash, 37);
            if($i == 0) {
                $username = ' ' . $username;
            }
            else if($i < 27) {
                $c = chr($i + ord('a') - 1);
                // Capitalise the start of each word (start of string or after a space).
                if($hash % 37 == 0) {
                    $c = strtoupper($c);
                }
                $username = $c . $username;
            }
            else {
                $username = chr($i + ord('0') - 27) . $username;
            }
        }
        return $username;
    }

}
