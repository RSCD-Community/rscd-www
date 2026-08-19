<?php

namespace RSCD\Model\Object;

/**
 * A file record representing any uploaded or system-generated document.
 *
 * The actual file content lives on the local filesystem under
 * {@see storageRoot()}; `path` and `name` together form the file location
 * relative to that root. The Assets controller is the only HTTP access path
 * to stored files (app/ itself is blocked by app.htaccess).
 *
 * @property int         $id
 * @property string      $uuid              UUIDv7, assigned by UniqueModel observer.
 * @property int|null    $user_id           FK to the User who owns/uploaded the file.
 * @property int|null    $secure_email_id   FK linking the file to a secure email, if any.
 * @property string      $name              Original file name (e.g. "avatar.png"). Encrypted at rest.
 * @property string|null $mimetype          MIME type (e.g. "image/png"). Encrypted at rest.
 * @property string      $path              Storage path prefix relative to the storage root. Encrypted at rest.
 * @property int         $size              File size in bytes.
 * @property string      $created_at
 * @property string      $updated_at
 */
class File extends \RSCD\Model\Object\FileBase {

    const SIGNED_INT_32 = 2147483646;

    /**
     * Validation metadata for each column, used by the API endpoint layer.
     */
    const COLUMN_FORMAT = [
        'id' => ['type' => 'integer', 'name' => 'ID', 'min' => 0, 'max' => self::SIGNED_INT_32],
        'uuid' => ['type' => 'string', 'name' => 'UUID', 'minlength' => 36, 'maxlength' => 36],
        'user_id' => ['type' => 'integer', 'name' => 'User ID', 'min' => 0, 'max' => self::SIGNED_INT_32],
        'name' => ['type' => 'string', 'name' => 'Name', 'maxlength' => 2048, 'required' => true],
        'mimetype' => ['type' => 'string', 'name' => 'Mimetype', 'maxlength' => 128],
        'path' => ['type' => 'string', 'name' => 'Path', 'maxlength' => 2048, 'required' => true],
        'size' => ['type' => 'integer', 'name' => 'Size', 'min' => 0, 'max' => self::SIGNED_INT_32, 'required' => true],
        'created_at' => ['type' => 'datetime', 'name' => 'Created at'],
        'updated_at' => ['type' => 'datetime', 'name' => 'Updated at']
    ];

    protected $table = 'file';

    protected $columns = [
        'id',
        'uuid',
        'user_id',
        'secure_email_id',
        'name',
        'mimetype',
        'path',
        'size',
        'created_at',
        'updated_at'
    ];

    protected $fillable = [
        'uuid',
        'user_id',
        'name',
        'mimetype',
        'path',
        'size'
    ];

    /** Hide FK columns that expose internal IDs from API responses. */
    protected $hidden = [
        'user_id',
        'secure_email_id'
    ];

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

    /**
     * Absolute path of the local file store root, created on demand.
     *
     * Lives inside app/, which app.htaccess blocks from direct HTTP access —
     * the Assets controller is the only way stored files reach a browser.
     *
     * @return string Absolute directory path, no trailing separator.
     */
    public static function storageRoot() {
        $root = __ROOTS__ . 'app' . DIRECTORY_SEPARATOR . 'files';
        if(!is_dir($root)) {
            mkdir($root, 0750, true);
        }
        return $root;
    }

    /**
     * Get the user who owns/uploaded this file.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user() {
       return $this->belongsTo('\\RSCD\\Model\\Object\\User', 'user_id');
    }

    /**
     * Get the system events associated with this file.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function events() {
       return $this->belongsToMany( '\\RSCD\\Model\\Object\\Event', 'file_event' );
    }
}
