<?php
namespace RSCD\Model\Object;
use RSCD\Model\ObjectModelBase;

/**
 * Base model for the `session` table.
 *
 * Manages authenticated user sessions identified by a serial token derived from
 * seed, IP address, and user-agent. Sessions expire after TIMEOUT seconds of
 * inactivity. Extended by Session which may add business logic.
 */
class SessionBase extends ObjectModelBase {
    //status
    const ACTIVE = 1;
    const TERMINATED = 2;
    const EXPIRED = 3;
    //const
    const TIMEOUT = 3600;
    //setup
    protected $table = 'session';
    protected $filters = [
        'status'
    ];
    protected $columns = [
        'id' ,
        'uuid' ,
        'user_id' ,
        'status' ,
        'serial' ,
        'ip_address' ,
        'fingerprint' ,
        'is_shop_as' ,
        'created_at' ,
        'updated_at'
    ];
    protected $fillable = [
        'uuid' ,
        'status' ,
        'serial' ,
        'ip_address' ,
        'fingerprint' ,
        'is_shop_as'
    ];
    /**
     * Boot the model and register the UniqueModel observer.
     *
     * session.uuid is NOT NULL in the schema, so the observer must assign a
     * UUID before every insert.
     *
     * @return void
     */
    public static function boot() {
        parent::boot();
        static::observe(\RSCD\Model\Observer\UniqueModel::class);
    }
    //relationships
    /**
     * The user who owns this session.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user() {
        return $this->belongsTo( '\\RSCD\\Model\\Object\\User' , 'user_id' );
    }
    //scope
    /**
     * Scope to active sessions only.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive( $query ) {
        return $query->where( 'status' , self::ACTIVE );
    }
    /**
     * Scope to terminated sessions only.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeTerminated( $query ) {
        return $query->where( 'status' , self::TERMINATED );
    }
    /**
     * Scope to expired sessions only.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeExpired( $query ) {
        return $query->where( 'status' , self::EXPIRED );
    }
    //find
    /**
     * Compute the session serial for a given seed value.
     *
     * The seed alone is hashed — see Session::findSerialWithSeed() for why
     * IP address and user-agent are deliberately excluded.
     *
     * @param  string $seed  Seed value (typically from the auth cookie).
     * @return string        MD5 hex serial.
     */
    public static function findSerialWithSeed( $seed ) {
        return md5( $seed );
    }
    /**
     * Find the active session with the given serial token.
     *
     * @param  string $serial  Session serial to look up.
     * @return static|null
     */
    public static function findWithSerial( $serial ) {
        return self::active()->where( 'serial' , $serial )->first();
    }
    //misc
    /**
     * Mark all stale active sessions as expired.
     *
     * @param  int|null $timeout  Idle timeout in seconds; defaults to TIMEOUT.
     * @return void
     */
    public static function prune( $timeout = null ) {
        $from = date( 'Y-m-d' . ' 00:00:00', 0 );
        $to = date( 'Y-m-d H:i:s' , time() - ( ! empty( $timeout ) ? $timeout : self::TIMEOUT ) );

        $sessions = self::active()->whereBetween( 'updated_at' , [ $from , $to ] )->get();

        foreach($sessions as $session) {
            $session->status = self::EXPIRED;
            $session->save();
        }
    }
    //resolve
    /**
     * Convert a status string to its integer constant.
     *
     * @param  string $string  Human-readable status (e.g. "Active").
     * @return int             Matching constant, or -1 if unrecognised.
     */
    public static function resolveStatus( $string ) {
        $status = self::simplify( $string );
        switch($status) {
            case 'active':
                return self::ACTIVE;
            case 'terminated':
                return self::TERMINATED;
            case 'expired':
                return self::EXPIRED;
        }
        return -1;
    }
    //html
    /**
     * Build an HTML <option> list for a status select element.
     *
     * @param  int    $selected  Currently selected status constant.
     * @return string            Concatenated HTML <option> tags.
     */
    public static function statusOptions( $selected ) {
        $options = [
            self::optionify( self::ACTIVE , 'Active' , $selected == self::ACTIVE ) ,
            self::optionify( self::TERMINATED , 'Terminated' , $selected == self::TERMINATED ) ,
            self::optionify( self::EXPIRED , 'Expired' , $selected == self::EXPIRED )
        ];
        return implode( '' , $options );
    }
    /**
     * Return an HTML badge label for a status value.
     *
     * @param  int    $status  One of the status constants.
     * @return string          HTML badge string, or the "Unknown" badge if unrecognised.
     */
    public static function statusLabel( $status ) {
        switch($status) {
            case self::ACTIVE:
                return self::labelify( 'Active' , 'label-success' );
            case self::TERMINATED:
                return self::labelify( 'Terminated' , 'label-warning' );
            case self::EXPIRED:
                return self::labelify( 'Expired' , 'label-danger' );
        }
        return self::unknownLabel();
    }
}
