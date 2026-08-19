<?php

namespace RSCD\Controller\Common;

/**
 * Shared helper methods for listing/filter operations.
 *
 * Provides static utility methods used by both the customer-facing
 * ObjectController and the admin ObjectController for parsing filter
 * values, normalising arrays, and converting operator text codes.
 */
trait ListingHelpers {

    /**
     * Convert the text values 'null' or 'nul' to an actual PHP null.
     *
     * Used when parsing filter values so that the string literal "null"
     * from a URL or POST body is treated as a real null in Eloquent queries.
     *
     * @param mixed $text The value to check.
     * @return mixed Null if the input is 'null'/'nul', otherwise the input unchanged.
     */
    protected static function getNullFromText($text) {
        if (is_string($text) && in_array(strtolower($text), ['null', 'nul'])) {
            return null;
        }
        return $text;
    }

    /**
     * Wrap a non-array value in a single-element array; pass arrays through unchanged.
     *
     * @param mixed $arr Value or array to normalise.
     * @return array The input as an array.
     */
    protected static function getAsArray($arr) {
        if (!is_array($arr)) {
            return [$arr];
        }
        return $arr;
    }

    /**
     * Convert a filter operator text code to the corresponding SQL operator symbol.
     *
     * Supported codes: eq (=), neq (!=), lt (<), lte (<=), gt (>), gte (>=), like (LIKE).
     *
     * @param string $text Operator code string.
     * @return string|null SQL operator symbol, or null for unrecognised codes.
     */
    protected static function getOperatorSymbolFromText($text) {
        switch ($text) {
            case 'eq':
                return '=';
            case 'neq':
                return '!=';
            case 'lt':
                return '<';
            case 'lte':
                return '<=';
            case 'gt':
                return '>';
            case 'gte':
                return '>=';
            case 'like':
                return 'LIKE';
        }
        return null;
    }
}
