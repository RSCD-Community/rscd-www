<?php

namespace RSCD\Model\Cast;

/**
 * Eloquent custom cast for JSON columns.
 *
 * Implements the CastsAttributes contract so that models can declare JSON
 * database columns via $casts = ['column' => JSON::class]. The value is
 * transparently decoded to a PHP array on read and encoded back to a JSON
 * string on write.
 *
 * Usage in a model:
 *   protected $casts = ['metadata' => \RSCD\Model\Cast\JSON::class];
 */
class JSON {

    /**
     * Cast the raw JSON database value to a PHP array.
     *
     * @param  \Illuminate\Database\Eloquent\Model $model
     * @param  string                              $key
     * @param  mixed                               $value       Raw JSON string from the database.
     * @param  array<string, mixed>                $attributes
     * @return array<string, mixed>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array {
        return json_decode($value, true);
    }

    /**
     * Prepare the PHP array value for storage as a JSON string.
     *
     * @param  \Illuminate\Database\Eloquent\Model $model
     * @param  string                              $key
     * @param  mixed                               $value       PHP array or value to encode.
     * @param  array<string, mixed>                $attributes
     * @return string  JSON-encoded string for database storage.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string {
        return json_encode($value);
    }
    
}