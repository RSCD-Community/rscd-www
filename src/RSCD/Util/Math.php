<?php

namespace RSCD\Util;

/**
 * General-purpose mathematical helpers.
 *
 * All methods are static. This class is intended to grow as shared
 * arithmetic utilities are needed across the codebase.
 */
class Math {

    /**
     * Compute the greatest common divisor of two integers using the Euclidean algorithm.
     *
     * @param  int  $a  First integer.
     * @param  int  $b  Second integer.
     * @return int       GCD of $a and $b.
     */
    public static function gcd($a, $b) {
        return ($a % $b) ? self::gcd($b, $a % $b) : $b;
    }

}
