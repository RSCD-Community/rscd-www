<?php

namespace RSCD\Model\Observer;

use RSCD\Util\Strings;

/**
 * Eloquent model observer that auto-generates UUIDv7 values on creation.
 *
 * Register this observer in a model's boot() method:
 *   static::observe(new UniqueModel());
 *
 * On creation, a new UUIDv7 is assigned to $model->uuid. On update, the
 * original uuid is restored to prevent callers from changing it; if somehow
 * the original uuid is empty (e.g. legacy row with no uuid), a new one is
 * generated and locked in.
 *
 * UUID generation is delegated to Strings::uuid() so that the algorithm
 * lives in one canonical place.
 */
class UniqueModel {

    /**
     * Handle "creating" event of an eloquent model.  Set a globally unique identifier upon creation.
     *
     * @param  \Illuminate\Database\Eloquent\Model $model
     * @return void
     */
    public function creating(\Illuminate\Database\Eloquent\Model $model) {
        $model->uuid = Strings::uuid();
    }

    /**
     * Handle "updating" event of an eloquent model.  Prevent a globally unique identifier from being modified.
     *
     * Restores the original uuid on every update. If the original is empty
     * (legacy row), generates and assigns a new uuid.
     *
     * @param  \Illuminate\Database\Eloquent\Model $model
     * @return void
     */
    public function updating(\Illuminate\Database\Eloquent\Model $model) {
        $model->uuid = $model->getOriginal('uuid');
        if (empty($model->uuid)) {
            $model->uuid = Strings::uuid();
        }
    }

    /**
     * Generate a UUIDv7. Delegates to Strings::uuid().
     *
     * @deprecated Call Strings::uuid() directly.
     * @return string  UUID string in standard 8-4-4-4-12 hex format.
     */
    public static function uuid7(): string {
        return Strings::uuid();
    }

}