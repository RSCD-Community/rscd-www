<?php

namespace RSCD\Util;

/**
 * Geographic reference data and helpers.
 *
 * Provides static reference data (country codes, calling codes, etc.) and
 * utility methods used for address normalization and phone number formatting.
 *
 * The CALLING_CODES constant maps ISO 3166-1 alpha-2 country codes to their
 * international dialing code and country name, used by Strings::normalizePhoneNumber().
 */
class Geography {

    /**
     * Map of ISO 3166-1 alpha-2 country codes to calling code and country name.
     *
     * Each entry has the shape: `['code' => int, 'name' => string]`.
     *
     * @var array<string, array{code: int, name: string}>
     */
    const CALLING_CODES = [];

}
