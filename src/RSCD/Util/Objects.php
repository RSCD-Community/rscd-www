<?php

namespace RSCD\Util;

/**
 * Utility helpers for working with PHP objects.
 *
 * All methods are static. Provides introspection and transformation utilities
 * for generic stdClass objects and model instances.
 */
class Objects {

    /**
     * Recursively build a flat key→value map from an object's properties.
     *
     * Nested objects are expanded into dotted key paths. For example, a property
     * `foo` containing an object with property `bar` produces the key `.foo.bar`.
     *
     * @param  object  $object  The object to traverse.
     * @param  string  $prefix  Dot-path prefix for the current level (used in recursion).
     * @return array            Flat associative array of `"prefix.key" => value` pairs.
     */
    public static function getPropertyArray($object, $prefix = '') {
        $map = [];
        foreach($object as $key => $value) {
            if(is_object($value)) {
                $map = array_merge($map, self::getPropertyArray($object, $prefix . '.' . $key));
            }
            $map[$prefix . '.' . $key] = $value;
        }
        return $map;
    }

}
