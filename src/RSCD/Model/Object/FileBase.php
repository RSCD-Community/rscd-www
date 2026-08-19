<?php
namespace RSCD\Model\Object;
use RSCD\Model\ObjectModelBase;
use RSCD\Model\Mutator;

/**
 * Base model for the `file` table.
 *
 * Stores references to stored files with encrypted name, MIME type, and
 * path fields. Mutator pairs automatically encrypt on write and decrypt on
 * read. Extended by File which adds relations and the storage root.
 */
class FileBase extends ObjectModelBase {
    //setup
    protected $table = 'file';
    protected $columns = [
        'id' , 'name' , 'mimetype' , 'path' , 'size', 'created_at' , 'updated_at'
    ];
    protected $fillable = [
        'name' , 'mimetype' , 'path', 'size'
    ];
    //relationships
    /**
     * Metadata key-value pairs attached to this file.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function metadata() {
       return $this->belongsToMany( '\\RSCD\\Model\\Object\\Metadata' , 'file_metadata' );
    }
    //mutator
    /**
     * Decrypt and return the file name attribute.
     *
     * @return string|null
     */
    public function getNameAttribute() {
        if( empty( $this->attributes['name'] ) ) {
          return null;
        }
        return Mutator::decrypt( Mutator::decode( $this->attributes['name'] ) );
    }
    /**
     * Encrypt and store the file name attribute.
     *
     * @param  string $value
     * @return void
     */
    public function setNameAttribute( $value ) {
        if( empty( $value ) ) {
            $value = '';
        }
        $this->attributes['name'] = Mutator::encode( Mutator::encrypt( $value ) );
    }
    /**
     * Decrypt and return the MIME type attribute.
     *
     * @return string|null
     */
    public function getMimetypeAttribute() {
        if( empty( $this->attributes['mimetype'] ) ) {
          return null;
        }
        return Mutator::decrypt( Mutator::decode( $this->attributes['mimetype'] ) );
    }
    /**
     * Encrypt and store the MIME type attribute.
     *
     * @param  string $value
     * @return void
     */
    public function setMimetypeAttribute( $value ) {
        if( empty( $value ) ) {
            $value = '';
        }
        $this->attributes['mimetype'] = Mutator::encode( Mutator::encrypt( $value ) );
    }
    /**
     * Decrypt and return the file path attribute.
     *
     * @return string|null
     */
    public function getPathAttribute() {
        if( empty( $this->attributes['path'] ) ) {
          return null;
        }
        return Mutator::decrypt( Mutator::decode( $this->attributes['path'] ) );
    }
    /**
     * Encrypt and store the file path attribute.
     *
     * @param  string $value
     * @return void
     */
    public function setPathAttribute( $value ) {
        if( empty( $value ) ) {
            $value = '';
        }
        $this->attributes['path'] = Mutator::encode( Mutator::encrypt( $value ) );
    }
}
