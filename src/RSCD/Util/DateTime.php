<?php

namespace RSCD\Util;

/**
 * Date and time formatting helpers.
 *
 * Provides simple wrappers around PHP's date() and human-readable
 * relative/elapsed time strings. All methods are static.
 */
class DateTime {

    /**
     * Maps an interval in seconds to a singular English time-unit label.
     * Entries must be ordered from largest to smallest for the lookup loop to work.
     */
    const TIME_MAP = [
        12 * 30 * 24 * 60 * 60 => 'year',
        30 * 24 * 60 * 60      => 'month',
        24 * 60 * 60           => 'day',
        60 * 60                => 'hour',
        60                     => 'minute',
        1                      => 'second',
    ];

    /**
     * Return the current (or given) timestamp as a full datetime string.
     *
     * @param  int|null  $now  Unix timestamp, or null for the current time.
     * @return string          Formatted as `Y-m-d H:i:s`.
     */
    public static function getDateTime($now = null) {
        return ($now !== null ? date('Y-m-d H:i:s', $now) : date('Y-m-d H:i:s'));
    }

    /**
     * Return the current (or given) timestamp as a date-only string.
     *
     * @param  int|null  $now  Unix timestamp, or null for the current time.
     * @return string          Formatted as `Y-m-d`.
     */
    public static function getDate($now = null) {
        return ($now !== null ? date('Y-m-d', $now) : date('Y-m-d'));
    }

    /**
     * Return the current (or given) timestamp as a time-only string.
     *
     * @param  int|null  $now  Unix timestamp, or null for the current time.
     * @return string          Formatted as `H:i:s`.
     */
    public static function getTime($now = null) {
        return ($now !== null ? date('H:i:s', $now) : date('H:i:s'));
    }

    /**
     * Return a human-readable string describing how long ago $time occurred.
     *
     * Uses TIME_MAP to find the largest applicable unit and rounds to the
     * nearest whole number. Example: "3 days ago", "1 hour ago".
     *
     * @param  int       $time  Unix timestamp of the past event.
     * @param  int|null  $now   Reference timestamp, or null for the current time.
     * @return string|null       Relative time string, or null if no unit matches.
     */
    public static function getRelativeTimeString($time, $now = null) {
        if($now === null) {
            $now = time();
        }
        $diff = $now - $time;
        if($diff < 1) {
            return 'less than 1 second ago';
        }
        foreach(self::TIME_MAP as $seconds => $label) {
            $div = $diff / $seconds;
            if($div >= 1) {
                $t = round($div);
                return $t . ' ' . $label . ($t > 1 ? 's' : '') . ' ago';
            }
        }
        return null;
    }

    /**
     * Return a human-readable string describing a duration in seconds.
     *
     * Uses TIME_MAP to find the largest applicable unit and rounds to the
     * nearest whole number. Example: "2 hours", "45 minutes".
     *
     * @param  int  $elapsed  Duration in seconds.
     * @return string|null     Elapsed time string, or null if no unit matches.
     */
    public static function getElapsedTimeString($elapsed) {
        if($elapsed < 1) {
            return 'less than 1 second';
        }
        foreach(self::TIME_MAP as $seconds => $label) {
            $div = $elapsed / $seconds;
            if($div >= 1) {
                $t = round($div);
                return $t . ' ' . $label . ($t > 1 ? 's' : '');
            }
        }
        return null;
    }

}
