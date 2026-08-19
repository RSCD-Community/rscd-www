<?php

namespace RSCD\Model\Object;

/**
 * A taxonomy tag that can be attached to products and other entities.
 *
 * Tags provide a flexible, user-defined labelling system used to improve
 * search discoverability on the storefront and to help staff categorise
 * heat transfer packs by ink colour, formula, or other attributes.
 * Tags are attached to products (and other models) via many-to-many
 * pivot tables.
 *
 * @property int    $id
 * @property string $uuid       UUIDv7, assigned by UniqueModel observer.
 * @property string $name       Unique tag name (e.g. "Screen-Printed Heat Transfer").
 * @property string $created_at
 * @property string $updated_at
 */
class Tag extends \RSCD\Model\Object\Common\Model {

    /**
     * Validation metadata for each column, used by the API endpoint layer.
     */
    const COLUMN_FORMAT = [
        'id' => ['type' => 'integer', 'name' => 'ID', 'min' => 0, 'max' => self::SIGNED_INT_32],
        'uuid' => ['type' => 'string', 'name' => 'UUID', 'minlength' => 36, 'maxlength' => 36],
        'name' => ['type' => 'string', 'name' => 'Tag name', 'maxlength' => 128, 'required' => true],
        'created_at' => ['type' => 'datetime', 'name' => 'Created at'],
        'updated_at' => ['type' => 'datetime', 'name' => 'Updated at']
    ];

    protected $table = 'tag';

    protected $columns = [
        'id',
        'uuid',
        'name',
        'created_at',
        'updated_at'
    ];

    protected $fillable = [
        'name'
    ];

    protected $hidden = [];

    protected $dates = [
        'created_at',
        'updated_at'
    ];

    /**
     * Boot the model and register the UniqueModel observer.
     *
     * The observer automatically generates a UUIDv7 for new records.
     */
    public static function boot(){
        parent::boot();
        static::observe(\RSCD\Model\Observer\UniqueModel::class);
    }

}
