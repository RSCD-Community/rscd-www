<?php

namespace RSCD\Util;

use RSCD\Util\Strings;

/**
 * Utility helpers for working with arrays.
 *
 * Provides small, focused helpers that normalize mixed input into consistent
 * array form, eliminating scattered is_array() checks throughout the codebase,
 * and simple accessors for the first and last elements of any iterable.
 */
class Arrays {

    /**
     * Remove empty or whitespace-only entries from an array and trim the remaining values.
     *
     * Keys are preserved. Useful for sanitizing user-supplied lists before
     * processing or persisting them.
     *
     * @param  array  $array  Input array of strings.
     * @return array          Filtered and trimmed copy of the input.
     */
    public static function clean($array) {
        $cleanArray = [];
        foreach($array as $key => $value) {
            if(empty($value) || strlen(Strings::trim($value)) < 1) {
                continue;
            }
            $cleanArray[$key] = Strings::trim($value);
        }
        return $cleanArray;
    }

    /**
     * Build a parallel array of references pointing into the source array.
     *
     * Useful when a function requires an array of references (e.g., mysqli
     * bind_param with a variable argument list). Modifying a value in the
     * returned array modifies the corresponding entry in $arrayWithValues.
     *
     * @param  array|null  &$arrayWithValues  Source array (passed by reference).
     * @return array                           Array whose values are references into $arrayWithValues.
     */
    public static function passByReference(&$arrayWithValues = null) {
        $arrayWithReferences = [];

        if($arrayWithValues !== null && is_array($arrayWithValues)) {
            foreach($arrayWithValues as $key => $value) {
                $arrayWithReferences[$key] = &$arrayWithValues[$key];
            }
        }
        return $arrayWithReferences;
    }

    /**
     * Merge two arrays after flattening the second into dotted key form.
     *
     * Equivalent to `array_merge($array1, Arrays::flatten($array2, ...))`.
     *
     * @param  array   $array1  Base array.
     * @param  array   $array2  Array to flatten and merge in.
     * @param  string  $format  sprintf format for nested keys (default `'%s.%s'`).
     * @param  string  $prefix  Optional prefix for all keys from $array2.
     * @param  bool    $encode  Whether to rawurlencode keys and values (default true).
     * @return array            Merged array.
     */
    public static function mergeAndFlatten($array1, $array2, $format = '%s.%s', $prefix = '', $encode = true) {
        return array_merge($array1, self::flatten($array2, $format, $prefix, $encode));
    }

    /**
     * Recursively flatten a nested array into a single-level dotted-key array.
     *
     * Nested arrays and objects are traversed, and their keys are joined with
     * $format (default `'%s.%s'`). Scalar values at each node are optionally
     * rawurlencode'd. Useful for building query strings from nested data structures.
     *
     * @param  array   $array   Input array (may contain nested arrays or objects).
     * @param  string  $format  sprintf format for joining parent and child keys.
     * @param  string  $prefix  Optional prefix applied to all top-level keys.
     * @param  bool    $encode  Whether to rawurlencode keys and scalar values.
     * @return array            Flat associative array of `'key' => value` pairs.
     */
    public static function flatten($array = [], $format = '%s.%s', $prefix = '', $encode = true) {
        $final = [];
        foreach($array as $key => $value) {
            if(is_array($value) || is_object($value)) {
                $flattened = self::flatten($value, $format, '', $encode);
                foreach($flattened as $fkey => $fvalue) {
                    $subKey = sprintf($format, $key, $fkey);
                    $newKey = $encode ? Strings::rawUrlEncode($subKey) : $subKey;
                    $final[$newKey] = $encode ? Strings::rawUrlEncode($fvalue) : $fvalue;
                }
            }
            else {
                $newKey = $encode ? Strings::rawUrlEncode($key) : $key;
                $final[$newKey] = $encode ? Strings::rawUrlEncode($value) : $value;
            }
        }
        if(!empty($prefix)) {
            $finalWithPrefix = [];
            foreach($final as $key => $value) {
                $newKey = sprintf($format, $prefix, $key);
                $finalWithPrefix[$newKey] = $value;
            }
            $final = $finalWithPrefix;
        }
        return $final;
    }

    /**
     * Serialize an array to a URL query string (including the leading `?`).
     *
     * Flattens nested arrays to dotted keys and rawurlencode's all keys and
     * values before joining with `&`.
     *
     * @param  array   $array   Data to serialize.
     * @param  string  $format  Key-join format passed through to flatten().
     * @return string            Query string starting with `?`, or `''` if the array is empty.
     */
    public static function getAsDataUri($array = [], $format = '%s.%s') {
        $dataUri = '';
        $flattened = self::flatten($array, $format);
        foreach($flattened as $key => $value) {
            $dataUri .= (strlen($dataUri) > 0 ? '&' : '?') . $key . '=' . Strings::rawUrlEncode($value);
        }
        return $dataUri;
    }

    /**
     * Coerce any value into an array.
     *
     * - Arrays are returned unchanged.
     * - Objects are cast to associative arrays via PHP's built-in cast (public
     *   properties become keys; private/protected properties get mangled keys).
     * - All other scalars (string, int, float, bool, null) are wrapped in a
     *   single-element indexed array.
     *
     * @param  mixed  $mixed  Any value: array, object, or scalar.
     * @return array          Always returns an array.
     */
    public static function fromMixed($mixed) {
        if(is_array($mixed)) {
            // Already an array — pass through without copying.
            return $mixed;
        }
        if(is_object($mixed)) {
            // Cast object to associative array using PHP's built-in mechanism.
            return (array)$mixed;
        }
        // Scalar or null — wrap in a single-element array.
        return [$mixed];
    }

    /**
     * Wrap any non-array value in a single-element array; leave arrays unchanged.
     *
     * Unlike fromMixed(), objects are NOT cast — they are wrapped as-is.
     * Use this when you need to iterate over a value that might be a single
     * model object or an array of model objects.
     *
     * @param  mixed  $mixed  Any value.
     * @return array          The original array, or [$mixed] for non-arrays.
     */
    public static function wrap($mixed) {
        if(is_array($mixed)) {
            return $mixed;
        }
        return [$mixed];
    }

    /**
     * Find the index of the first element in an array whose `id` property matches $id.
     *
     * Skips elements that do not have an `id` property. Returns null if no match is found.
     *
     * @param  mixed  $id     The ID value to search for (strict equality).
     * @param  array  $array  Array of objects, each expected to have an `id` property.
     * @return int|null        The array index of the matching element, or null if not found.
     */
    public static function getIndexById($id, $array) {
        foreach($array as $i => $object) {
            if(!isset($object->id)) {
                continue;
            }
            if($object->id === $id) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Return the first element of any iterable collection, or null if empty.
     *
     * Works with arrays, Laravel Eloquent collections, and any other iterable.
     * Useful when the collection type is unknown and only the first element is needed.
     *
     * @param  iterable|null $collection Any iterable value, or null.
     * @return mixed|null                 The first element, or null if the collection is empty.
     */
    public static function getFirst($collection) {
        if(empty($collection)) {
            return null;
        }
        foreach($collection as $object) {
            return $object;
        }
        return null;
    }

    /**
     * Return the last element of any iterable collection, or null if empty.
     *
     * Iterates through the entire collection to find the last element. Works with
     * arrays and any other iterable type.
     *
     * @param  iterable|null $collection Any iterable value, or null.
     * @return mixed|null                 The last element, or null if the collection is empty.
     */
    public static function getLast($collection) {
        if(empty($collection)) {
            return null;
        }
        $last = null;
        foreach($collection as $object) {
            $last = $object;
        }
        return $last;
    }

}
