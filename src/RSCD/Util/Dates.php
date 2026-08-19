<?php

namespace RSCD\Util;

use RSCD\Model\State;

/**
 * Display formatting for timestamps.
 *
 * The database stores UTC unix timestamps everywhere and nothing here changes
 * that. What a person sees is another matter -- time is relative to the
 * reader -- so display goes through the timezone State already resolves once
 * per request: the signed-in user's own timezone when their account names a
 * valid one, else the site-wide `timeZone` from app.json.
 *
 * Every user-facing timestamp should render through display(). Raw date()
 * prints the server's clock, which is nobody's.
 */
class Dates {

    /**
     * Format a unix timestamp for display in the viewer's timezone.
     *
     * @param  int    $timestamp Unix timestamp (UTC, as stored).
     * @param  string $format    date() format.
     * @param  string $empty     What to print when the timestamp is 0/null.
     * @return string
     */
    public static function display($timestamp, $format = 'j M Y, H:i', $empty = '-') {
        if(empty($timestamp)) {
            return $empty;
        }

        $state = State::get();
        $zone = !empty($state->defaultTimeZone->tzdata_id) ? $state->defaultTimeZone->tzdata_id : 'UTC';
        $when = new \DateTime('@' . (int)$timestamp);
        $when->setTimezone(new \DateTimeZone($zone));
        return $when->format($format);
    }

}
