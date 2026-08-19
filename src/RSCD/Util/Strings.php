<?php

namespace RSCD\Util;

use RSCD\Util\Geography;

/**
 * String utility helpers for the RSCD application.
 *
 * Covers random string generation, URL encoding, regex escaping, validation,
 * phone number normalization, HTML manipulation, and HTML-entity round-tripping.
 * All methods are static.
 */
class Strings {

    /** Character set for machine-readable serials (no ambiguous chars like 0/O, 1/I). */
    const SERIAL    = 'ABCDEFHJKMNPRTXYZ2346789';
    const LOWERCASE = 'abcdefghijklmnopqrstuvwxyz';
    const UPPERCASE = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    const DIGITS    = '0123456789';
    const SYMBOLS   = '!#$%&*+-=?@^_';

    /**
     * Trim whitespace (or a custom mask) from both ends of a string using mb_trim().
     *
     * Drop-in replacement for both trim() and mb_trim():
     *   - Strings::trim($str)               — trims standard ASCII whitespace, UTF-8 safe
     *   - Strings::trim($str, $mask)         — trims custom character mask
     *   - Strings::trim($str, $mask, $enc)   — full parity with mb_trim()
     *
     * @param  string       $string    Input string.
     * @param  string|null  $mask      Characters to strip (default: standard whitespace).
     * @param  string       $encoding  Character encoding (default: UTF-8).
     * @return string                   Trimmed string.
     */
    public static function trim($string, ?string $mask = null, string $encoding = 'UTF-8'): string {
        if(empty($string)) {
            return '';
        }
        return mb_trim($string, $mask ?? " \n\r\t\v\0", $encoding);
    }
    
    /** @var int|null Unix millisecond timestamp of the last generated UUID */
    protected static $lastUnixMs = null;
    /** @var int Monotonic sequence counter within the same millisecond */
    protected static $sequence = 0;

    /**
     * Generate a UUIDv7 (time-ordered, sortable UUID with millisecond precision).
     *
     * Within the same millisecond a monotonically increasing 14-bit sequence
     * counter is used.  If the counter wraps (overflows 14 bits) the timestamp
     * is bumped by 1 ms to avoid collisions.  The sequence is seeded with a
     * random value at the start of each new millisecond.
     *
     * Format: standard 8-4-4-4-12 hex groups (UUID string).
     *
     * @return string  UUIDv7 string.
     */
    public static function uuid(): string {
        $unixMs = (int)(microtime(true) * 1000);

        if ($unixMs === static::$lastUnixMs) {
            static::$sequence++;
            static::$sequence &= 0x3FFF; // keep within the 14-bit sequence range
            if (static::$sequence === 0) {
                $unixMs++; // bump time slightly to avoid collision
            }
        } else {
            static::$sequence   = random_int(0, 0x3FFF); // random start per ms
            static::$lastUnixMs = $unixMs;
        }

        $timeHigh              = ($unixMs >> 16) & 0xFFFFFFFF;
        $timeLow               = $unixMs & 0xFFFF;
        $timeHiAndVersion      = ($timeLow & 0x0FFF) | (0x7 << 12);
        $clockSeqHiAndReserved = (static::$sequence & 0x3FFF) | 0x8000;
        $randHex               = bin2hex(random_bytes(6));

        return sprintf(
            '%08x-%04x-%04x-%04x-%012s',
            $timeHigh,
            $timeLow,
            $timeHiAndVersion,
            $clockSeqHiAndReserved,
            $randHex
        );
    }

    /**
     * Generate a UUID v4-style unique identifier string.
     *
     * @deprecated Use Strings::uuid() for a cryptographically-safe UUIDv7.
     * @return string  Unique identifier in UUID format.
     */
    public static function generateUniqueId(): string {
        return static::uuid();
    }

    /**
     * Encode a string with rawurlencode() while preserving existing `[` and `]` characters.
     *
     * PHP's rawurlencode() encodes `[` as `%5B` and `]` as `%5D`, which breaks
     * query-string array notation. This method first decodes then re-encodes to
     * normalise double-encoded input, then restores literal brackets.
     *
     * @param  string  $string  Input string to encode.
     * @return string            URL-encoded string with brackets left as-is.
     */
    public static function rawUrlEncode($string) {
        return str_replace('%5D', ']', str_replace('%5B', '[', rawurlencode(rawurldecode($string))));
    }

    /**
     * Generate a random lowercase alphanumeric string of the given length.
     *
     * @param  int     $length  Desired string length (default 32; clamped to 32 if invalid).
     * @param  string  $string  Optional seed string to append to (used for recursion/extension).
     * @return string            Random alphanumeric string.
     */
    public static function generateAlphanumeric($length = 32, $string = '') {
        if($length === null || !is_numeric($length) || $length < 0) {
            $length = 32;
        }
        $chars = static::DIGITS . static::LOWERCASE;
        $charsLength = strlen($chars);
        $stringLength = strlen($string);
        for($i = $stringLength; $i < $length; $i++) {
            $string .= $chars[random_int(0, $charsLength - 1)];
        }
        return $string;
    }

    /**
     * Generate a random serial string in hyphen-delimited groups.
     *
     * Uses the SERIAL character set (no ambiguous characters) to produce
     * machine-readable activation keys. Example: `ABCD-EFHJ-KMNP-RTXY`.
     *
     * @param  int  $groupLength  Number of characters per group (default 4).
     * @param  int  $groupCount   Number of groups (default 4).
     * @return string              Hyphen-delimited serial string.
     */
    public static function generateSerial($groupLength = 4, $groupCount = 4) {
        if($groupLength === null || !is_numeric($groupLength) || $groupLength < 0) {
            $groupLength = 4;
        }
        if($groupCount === null || !is_numeric($groupCount) || $groupCount < 0) {
            $groupCount = 4;
        }
        $string = '';
        $chars = static::SERIAL;
        $charsLength = strlen($chars);
        for($i = 0; $i < $groupCount; $i++) {
            for($j = 0; $j < $groupLength; $j++) {
                $string .= $chars[random_int(0, $charsLength - 1)];
            }
            if($i + 1 < $groupCount) {
                $string .= '-';
            }
        }
        return $string;
    }

    /**
     * Generate a random string from the full printable character set.
     *
     * When $clean is true, the first character is forced to be a letter (not a
     * digit or symbol) and symbols are excluded from all positions — useful for
     * generating passwords that must start with a letter.
     *
     * @param  int        $length  Desired string length (default 32).
     * @param  bool|mixed $clean   If truthy, exclude symbols and force letter-first.
     * @param  string     $string  Optional seed string to append to.
     * @return string               Generated string.
     */
    public static function generate($length = 32, $clean = false, $string = '') {
        if($length === null || !is_numeric($length) || $length < 0) {
            $length = 32;
        }
        $chars = static::DIGITS . static::UPPERCASE . static::LOWERCASE . static::SYMBOLS;
        $stringLength = strlen($string);
        $charsLength = strlen($chars);
        $digitsLength = strlen(static::DIGITS);
        $symbolsLength = strlen(static::SYMBOLS);
        for($i = $stringLength; $i < $length; $i++) {
            if($clean !== false && $i == $stringLength) {
                // First character must be a letter (skip digits and symbols).
                $string .= $chars[random_int($digitsLength, $charsLength - $symbolsLength - 1)];
            }
            else if($clean !== false) {
                // Subsequent characters: letters and digits only (no symbols).
                $string .= $chars[random_int(0, $charsLength - $symbolsLength - 1)];
            }
            else {
                $string .= $chars[random_int(0, $charsLength - 1)];
            }
        }
        return $string;
    }

    /**
     * Convert a string to a URL-safe slug.
     *
     * Replaces all non-word characters with hyphens, then collapses consecutive
     * hyphens into one. The result is lowercase UTF-8.
     *
     * @param  string  $string  Input string.
     * @return string            Slugified string (e.g. `'Hello World!'` → `'hello-world-'`).
     */
    public static function slugify($string) {
        return mb_strtolower(preg_replace('/-+/i', '-', preg_replace('/\W/i', '-', $string)), 'UTF-8');
    }

    /**
     * Strip all non-alphanumeric characters from a string.
     *
     * @param  string  $string  Input string.
     * @return string            String containing only `[a-zA-Z0-9]` characters.
     */
    public static function alphanumeric($string) {
        return preg_replace('/\W/i', '', $string);
    }

    /**
     * Alias for {@see makeRegexCompatible()}.
     *
     * @param  string     $string    Input string to escape.
     * @param  bool|mixed $wildcard  Character to treat as `.*` wildcard, or false.
     * @return string                 Regex-safe string.
     */
    public static function regexCompatible($string = '', $wildcard = false) {
        return static::makeRegexCompatible($string, $wildcard);
    }

    /**
     * Escape a replacement value for use in preg_replace().
     *
     * Ensures that literal `$` characters in the replacement string are not
     * interpreted as backreferences by preg_replace().
     *
     * @param  string  $string  Replacement string.
     * @return string            Escaped replacement string safe for preg_replace.
     */
    public static function makeRegexValueCompatible($string = '') {
        return str_replace('$', '\\$', str_replace('\\$', '$', $string));
    }

    /**
     * Escape user-supplied text for display in a page.
     *
     * htmlspecialchars neutralises markup but not the [{...}] template
     * tokens, and user text rendered into a page is still walked by the
     * later injection passes -- a forum post titled [{active_user}] would
     * otherwise render the READER's account object into the page. &#91;
     * displays as "[" and can never open a token.
     *
     * @param  string  $string  Raw user text.
     * @return string            Markup- and token-safe display text.
     */
    public static function displayText($string) {
        return str_replace('[{', '&#91;{', htmlspecialchars((string)$string, ENT_QUOTES));
    }

    /**
     * Escape all regex metacharacters in a string so it can be used as a literal pattern.
     *
     * Performs a two-pass approach: first un-escape any already-escaped metacharacters
     * (to avoid double-escaping), then re-escape everything. Optionally replaces a
     * wildcard character with the `.*` pattern.
     *
     * @param  string     $string    Input string to escape.
     * @param  bool|mixed $wildcard  A character to treat as `.*`, or false to disable.
     * @return string                 Regex-safe string.
     */
    public static function makeRegexCompatible($string = '', $wildcard = false) {
        $string = str_replace(
            ['\\.', '\\^', '\\$', '\\(', '\\|', '\\)', '\\?', '\\*', '\\+', '\\{', '\\}', '\\[', '\\]', '\\/'],
            ['.', '^', '$', '(', '|', ')', '?', '*', '+', '{', '}', '[', ']', '/'],
            $string
        );
        $string = str_replace(
            ['.', '^', '$', '(', '|', ')', '?', '*', '+', '{', '}', '[', ']', '/'],
            ['\\.', '\\^', '\\$', '\\(', '\\|', '\\)', '\\?', '\\*', '\\+', '\\{', '\\}', '\\[', '\\]', '\\/'],
            $string
        );
        if($wildcard !== false) {
            $string = str_replace($wildcard, '.*', $string);
        }
        return $string;
    }

    /**
     * Test whether $haystack starts with $needle.
     *
     * @param  string  $needle      The prefix to search for.
     * @param  string  $haystack    The string to test.
     * @param  bool    $ignoreCase  If true, comparison is case-insensitive.
     * @return bool                  True if $haystack starts with $needle.
     */
    public static function startsWith($needle = '', $haystack = '', $ignoreCase = false) {
        return preg_match('/^' . static::makeRegexCompatible($needle) . '.*/' . ($ignoreCase ? 'i' : ''), $haystack) == 1;
    }

    /**
     * Test whether $haystack ends with $needle.
     *
     * @param  string  $needle      The suffix to search for.
     * @param  string  $haystack    The string to test.
     * @param  bool    $ignoreCase  If true, comparison is case-insensitive.
     * @return bool                  True if $haystack ends with $needle.
     */
    public static function endsWith($needle = '', $haystack = '', $ignoreCase = false) {
        return preg_match('/.*' . static::makeRegexCompatible($needle) . '$/' . ($ignoreCase ? 'i' : ''), $haystack) == 1;
    }

    /**
     * Test whether $haystack matches $needle, treating $wildcard as a `.*` glob.
     *
     * @param  string  $needle      Pattern string, may contain $wildcard characters.
     * @param  string  $haystack    The string to test.
     * @param  string  $wildcard    Character that acts as a wildcard (default `'%'`).
     * @param  bool    $ignoreCase  If true, comparison is case-insensitive.
     * @return bool                  True if $haystack matches the pattern.
     */
    public static function wildcardCompare($needle = '', $haystack = '', $wildcard = '%', $ignoreCase = false) {
        return preg_match('/^' . static::makeRegexCompatible($needle, $wildcard) . '$/' . ($ignoreCase ? 'i' : ''), $haystack) == 1;
    }

    /**
     * Validate that $ipAddress is a well-formed IP address (v4 or v6).
     *
     * @param  string  $ipAddress  Input string.
     * @return bool                 True if valid.
     */
    public static function ipAddress($ipAddress = '') {
        return filter_var($ipAddress, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Validate that $emailAddress is a well-formed email address.
     *
     * @param  string  $emailAddress  Input string.
     * @return bool                    True if valid.
     */
    public static function emailAddress($emailAddress = '') {
        return filter_var($emailAddress, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Extract email addresses from a string using a basic regex.
     *
     * @param  string     $string  Input text to search.
     * @param  bool       $first   If true, return only the first match as a string.
     * @return array|string|null   Array of matches, the first match string, or null if none found.
     */
    public static function extractEmailAddresses($string, $first = false) {
        $matches = [];
        if(preg_match('/[-0-9a-zA-Z.+_]+@[-0-9a-zA-Z.+_]+.[a-zA-Z]{2,4}/i', $string, $matches) == 1) {
            return $first !== false ? $matches[0] : $matches;
        }
        return null;
    }

    /**
     * Extract the first email address from a string, or null if none found.
     *
     * @param  string       $string  Input text to search.
     * @return string|null            First email address found, or null.
     */
    public static function extractFirstEmailAddress($string) {
        return static::extractEmailAddresses($string, true);
    }

    /**
     * Strip formatting characters from a phone number string.
     *
     * Removes spaces, dashes, parentheses, underscores, `#`, and the literal
     * string `ext` and trims the result.
     *
     * @param  string  $phoneNumber  Raw phone number string.
     * @return string                 Digits and `+` only.
     */
    public static function stripPhoneNumber($phoneNumber) {
        return trim(str_replace([' ', '-', '(', ')', '_', '#', 'ext'], '', $phoneNumber));
    }

    /**
     * Normalize a phone number to E.164 format by prepending the country calling code.
     *
     * If the stripped number already starts with `+`, it is returned as-is.
     * Otherwise the country code from Geography::CALLING_CODES is prepended,
     * or `+1` (US) if $countryOfOrigin is empty.
     *
     * @param  string       $phoneNumber       Raw phone number string.
     * @param  string|null  $countryOfOrigin   ISO 3166-1 alpha-2 country code, or null for US default.
     * @return string                           Normalized E.164 phone number.
     */
    public static function normalizePhoneNumber($phoneNumber, $countryOfOrigin = null) {
        $stripped = static::stripPhoneNumber($phoneNumber);
        if(strpos($stripped, '+') === false) {
            if(empty($countryOfOrigin)) {
                $stripped = '+1' . $stripped;
            }
            else if(!empty(Geography::CALLING_CODES[$countryOfOrigin]['code'])) {
                $stripped = '+' . Geography::CALLING_CODES[$countryOfOrigin]['code'] . $stripped;
            }
        }
        return $stripped;
    }

    /**
     * Replace placeholder tokens in a string with provided values.
     *
     * Tokens are formatted using sprintf($scheme, $key) and matched
     * case-insensitively. Example: `inject('Hello [{name}]', ['name'], ['World'])`.
     *
     * @param  string        $string  Template string containing tokens.
     * @param  string|array  $keys    Token name(s).
     * @param  string|array  $values  Replacement value(s), positionally matching $keys.
     * @param  string        $scheme  sprintf format for the token wrapper (default `'[{%s}]'`).
     * @return string                  String with tokens replaced.
     */
    public static function inject($string, $keys, $values, $scheme = '[{%s}]') {
        $keys   = !is_array($keys)   ? [$keys]   : $keys;
        $values = !is_array($values) ? [$values] : $values;
        if(($keyCount = count($keys)) > 0) {
            for($i = 0; $i < $keyCount; $i++) {
                /* A literal, case-insensitive swap. This used to go through
                   preg_replace, where a value containing \1 or $0 -- a
                   Windows path in a forum post, say -- was read as a
                   backreference and silently corrupted the page. */
                $string = str_ireplace(sprintf($scheme, $keys[$i]), $values[$i], $string);
            }
        }
        return $string;
    }

    /**
     * Extract and normalize plain text from an HTML string.
     *
     * Strips `<head>`, `<script>`, `<style>`, `<link>`, and `<noscript>` elements,
     * then strips remaining tags, collapses whitespace, and trims the result.
     *
     * @param  string  $html  HTML input string.
     * @return string          Plain text with normalized whitespace.
     */
    public static function getTextFromHtml($html) {
        $expressions = ['/&nbsp;/i', '/\t/i', '/\n/i', '/\r/i', '/\0/i', '/\x0B/i'];
        $tags = ['head', 'script', 'style', 'link', 'noscript'];
        $dom = new \DOMDocument();
        $dom->loadHTML($html);
        $dom = static::removeElementsByTagName($tags, $dom);
        return trim(preg_replace('/\s+/i', ' ', preg_replace($expressions, ' ', strip_tags($dom->saveHtml()))));
    }

    /**
     * Remove all elements matching one or more tag names from a DOMDocument.
     *
     * Iterates in reverse order (high-to-low index) so that removing a node
     * does not shift the indices of unprocessed siblings.
     *
     * @param  string|array  $tagName  Tag name or array of tag names to remove.
     * @param  \DOMDocument  $dom      The document to modify in place.
     * @return \DOMDocument             The same document, with matching elements removed.
     */
    public static function removeElementsByTagName($tagName, $dom) {
        if(!is_array($tagName)) {
            $tagName = [$tagName];
        }
        foreach($tagName as $tag) {
            $nodeList = $dom->getElementsByTagName($tag);
            for($nodeId = $nodeList->length; --$nodeId >= 0;) {
                $node = $nodeList->item($nodeId);
                $node->parentNode->removeChild($node);
            }
        }
        return $dom;
    }

    // ── RSCD-specific HTML-entity helpers ──────────────────────────────────

    /**
     * Encode a string to safe HTML entities, including backslashes.
     *
     * Decodes any existing entities first (via fromHtmlEntities) to avoid
     * double-encoding, then re-encodes. Backslashes are encoded as `&bsol;`.
     *
     * @param  string  $string  Input string.
     * @return string            HTML-entity-encoded string.
     */
    public static function toHtmlEntities($string) {
        return preg_replace('/\\\/i', '&bsol;', htmlentities(self::fromHtmlEntities($string)));
    }

    /**
     * Decode an HTML-entity-encoded string back to plain text.
     *
     * Lowercases entity names first (via lowercaseHtmlEntities) for consistent
     * decoding, then decodes with html_entity_decode. `&bsol;` is restored to `\`.
     *
     * @param  string  $string  HTML-entity-encoded string.
     * @return string            Decoded plain-text string.
     */
    public static function fromHtmlEntities($string) {
        return preg_replace('/&bsol;/i', '\\', html_entity_decode(self::lowercaseHtmlEntities($string)));
    }

    /**
     * Lowercase all HTML entity names in a string (e.g. `&Amp;` → `&amp;`).
     *
     * Needed before html_entity_decode() because PHP only recognises
     * lowercase entity names.
     *
     * @param  string  $string  Input string possibly containing mixed-case entities.
     * @return string            String with all entity names lowercased.
     */
    public static function lowercaseHtmlEntities($string) {
        return preg_replace_callback('/&[A-Za-z0-9#]+;/', function($matches) {
            return strtolower($matches[0]);
        }, $string);
    }

    /**
     * Truncate a number to a fixed number of decimal places without rounding.
     *
     * Works by converting to a string and slicing, so it avoids floating-point
     * rounding artefacts. If the number has no decimal point it is returned as-is.
     *
     * @param  int|float  $num  Input number.
     * @param  int        $len  Number of decimal places to keep.
     * @return string|int|float  Truncated value (string if a decimal point was present).
     */
    public static function truncateAfterDecimal($num, $len) {
        $str = (string)$num;
        $dot = strpos($str, '.');
        if($dot === false) {
            return $num;
        }
        return substr($str, 0, $dot + 1 + $len);
    }

}
