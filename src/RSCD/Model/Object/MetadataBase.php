<?php
namespace RSCD\Model\Object;
use RSCD\Model\ObjectModelBase;

/**
 * Base model for the `metadata` table.
 *
 * Metadata records are generic key-value pairs (metakey/metavalue) that can
 * be attached to many entity types (orders, products, artworks, etc.) via
 * pivot tables. Extended by Metadata which may add business logic.
 */
class MetadataBase extends ObjectModelBase {
    //setup
    protected $table = 'metadata';
    protected $filters = [
        'metakey'
    ];
    protected $columns = [
        'id' , 'metakey' , 'metavalue' , 'created_at' , 'updated_at'
    ];
    protected $fillable = [
        'metakey' , 'metavalue'
    ];
}
