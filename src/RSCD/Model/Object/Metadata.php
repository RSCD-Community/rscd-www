<?php

namespace RSCD\Model\Object;

/**
 * A generic key/value metadata record attachable to any entity.
 *
 * Metadata provides a flexible extension mechanism for storing arbitrary
 * structured data against artworks, files, die-cut nesting requests, products,
 * and other models without adding columns to their primary tables. Each row
 * represents a single metakey/metavalue pair. Values are stored as TEXT
 * (up to 32 KiB) to accommodate JSON blobs or long descriptions.
 *
 * Models attach Metadata via many-to-many pivot tables
 * (e.g. `artwork_metadata`, `file_metadata`, `line_item_metadata`).
 *
 * @property int    $id
 * @property string $metakey   The metadata key (max 64 characters).
 * @property string $metavalue The metadata value (max 32,767 characters / 32 KiB).
 */
class Metadata extends \RSCD\Model\Object\MetadataBase {

    const SIGNED_INT_32 = 2147483646;
    const SIGNED_INT_16 = 32767;
    const SIGNED_INT_8 = 127;

    /**
     * Validation metadata for each column, used by the API endpoint layer.
     *
     * The metavalue cap of 32,767 characters matches the MySQL TEXT column
     * limit to prevent silent truncation on insert.
     */
    const COLUMN_FORMAT = [
        'id' => ['type' => 'integer', 'name' => 'ID', 'min' => 0, 'max' => self::SIGNED_INT_32],
        'metakey' => ['type' => 'string', 'name' => 'Key', 'maxlength' => 64, 'required' => true],
        'metavalue' => ['type' => 'string', 'name' => 'Value', 'maxlength' => 32767] // 32KiB
    ];

}
