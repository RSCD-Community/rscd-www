<?php

namespace RSCD\Model\Object;

/**
 * User model — a site account for rscd-community.org.
 *
 * One site account owns the Account Manager sign-in and the forum identity,
 * and may own any number of game characters (rscd_players rows keyed by
 * owner). Game characters are managed by the Account Manager controllers,
 * not through relations here, because the rscd_* tables predate this
 * framework and follow the game server's own schema.
 *
 * Passwords are stored with password_hash() (bcrypt via PASSWORD_DEFAULT).
 * Assigning to $user->password hashes automatically and stamps
 * password_last_changed_at; the stored hash is never exposed through
 * toArray()/toJson().
 *
 * parent_id supports sub-users: a child account belongs to a parent, and
 * getRoot() walks up to the top-level owner.
 *
 * Table: user
 */
class User extends \RSCD\Model\Object\Common\Model {

    /** Account is active and may sign in. */
    const STATUS_ACTIVE = 1;

    /** Account is deactivated; sign-in refused. */
    const STATUS_INACTIVE = 3;

    /** Account registered but the email address is not yet confirmed. */
    const STATUS_PENDING_CONFIRMATION = 4;

    /**
     * Column metadata used by the framework for validation, listing, and search.
     *
     * @var array<string, array<string, mixed>>
     */
    const COLUMN_FORMAT = [
        'id' => ['type' => 'integer', 'name' => 'ID', 'min' => 0, 'max' => self::SIGNED_INT_32],
        'uuid' => ['type' => 'string', 'name' => 'UUID', 'minlength' => 36, 'maxlength' => 36],
        'parent_id' => ['type' => 'integer', 'name' => 'Parent user ID', 'min' => 0, 'max' => self::SIGNED_INT_32],
        'status' => ['type' => 'integer', 'name' => 'Status', 'min' => 0, 'max' => self::SIGNED_INT_8],
        'name' => ['type' => 'string', 'name' => 'Name', 'maxlength' => 255],
        'email_address' => ['type' => 'email', 'name' => 'Email address', 'maxlength' => 256],
        'timezone' => ['type' => 'string', 'name' => 'Timezone', 'maxlength' => 64],
        'signed_in_last_at' => ['type' => 'datetime', 'name' => 'Last sign-in'],
        'password_last_changed_at' => ['type' => 'datetime', 'name' => 'Password changed'],
        'created_at' => ['type' => 'datetime', 'name' => 'Created at'],
        'updated_at' => ['type' => 'datetime', 'name' => 'Updated at'],
        'contact' => ['name' => 'Contact', 'class' => '\\RSCD\\Model\\Object\\Contact'],
        'roles' => ['name' => 'Role', 'class' => '\\RSCD\\Model\\Object\\Role'],
        'metadata' => ['name' => 'Metadata', 'class' => '\\RSCD\\Model\\Object\\Metadata'],
        'tags' => ['name' => 'Tag', 'class' => '\\RSCD\\Model\\Object\\Tag']
    ];

    /**
     * The underlying database table.
     *
     * @var string
     */
    protected $table = 'user';

    /**
     * Columns exposed to the generic listing/filter machinery.
     *
     * The password hash is deliberately absent — it must never be listable,
     * sortable, or searchable.
     *
     * @var string[]
     */
    protected $columns = [
        'id',
        'uuid',
        'parent_id',
        'status',
        'name',
        'email_address',
        'timezone',
        'signed_in_last_at',
        'password_last_changed_at',
        'created_at',
        'updated_at'
    ];

    /**
     * Columns the listing machinery may filter on directly.
     *
     * @var string[]
     */
    protected $filters = [
        'status',
        'parent_id'
    ];

    /**
     * Mass-assignable columns.
     *
     * @var string[]
     */
    protected $fillable = [
        'uuid',
        'parent_id',
        'status',
        'name',
        'email_address',
        'password',
        'timezone'
    ];

    /**
     * Attributes hidden from toArray()/toJson().
     *
     * @var string[]
     */
    protected $hidden = [
        'password'
    ];

    /**
     * Boot the model and register the UniqueModel observer (UUID on create).
     *
     * @return void
     */
    public static function boot() {
        parent::boot();
        static::observe(\RSCD\Model\Observer\UniqueModel::class);
    }

    // mutators

    /**
     * Hash the password on assignment and stamp password_last_changed_at.
     *
     * Assigning an empty value is ignored so form handlers can pass through
     * an untouched blank password field without clearing the stored hash.
     *
     * @param  string  $value  The plain-text password.
     * @return void
     */
    public function setPasswordAttribute($value) {
        if($value === null || $value === '') {
            return;
        }
        $this->attributes['password'] = password_hash($value, PASSWORD_DEFAULT);
        $this->attributes['password_last_changed_at'] = date('Y-m-d H:i:s');
    }

    // find

    /**
     * Verify an email address + password pair and return the matching user.
     *
     * Fetches by email address (excluding inactive and unconfirmed accounts),
     * then verifies the password against the stored bcrypt hash. On success
     * the hash is transparently upgraded if PHP's default algorithm/cost has
     * changed, and signed_in_last_at is stamped.
     *
     * Returns null on any failure; callers must not distinguish "no such
     * account" from "wrong password" in user-facing output.
     *
     * @param  string  $email     The email address to look up.
     * @param  string  $password  The plain-text password to verify.
     * @return static|null
     */
    public static function findWithCredentials($email, $password) {
        if(empty($email) || empty($password)) {
            return null;
        }
        $user = static::where('email_address', $email)
            ->whereNotIn('status', [self::STATUS_INACTIVE, self::STATUS_PENDING_CONFIRMATION])
            ->first();
        if(empty($user->id)) {
            return null;
        }
        $hash = (string)$user->getRawOriginal('password');
        if(!password_verify($password, $hash)) {
            return null;
        }
        if(password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            // Direct query update: the mutator would also stamp
            // password_last_changed_at, which a silent rehash must not do.
            static::where('id', $user->id)
                ->update(['password' => password_hash($password, PASSWORD_DEFAULT)]);
        }
        $user->signed_in_last_at = date('Y-m-d H:i:s');
        $user->save();
        return $user;
    }

    // relationships

    /**
     * The user's default contact record (name/address book entry).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function contact() {
        return $this->hasOne('\\RSCD\\Model\\Object\\Contact', 'user_id')
            ->where('is_default', 1);
    }

    /**
     * All contact records belonging to this user.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function contacts() {
        return $this->hasMany('\\RSCD\\Model\\Object\\Contact', 'user_id');
    }

    /**
     * The parent account, when this is a sub-user.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function parentUser() {
        return $this->belongsTo('\\RSCD\\Model\\Object\\User', 'parent_id');
    }

    /**
     * Sub-users owned by this account.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function children() {
        return $this->hasMany('\\RSCD\\Model\\Object\\User', 'parent_id');
    }

    /**
     * Roles assigned to this user via the user_role pivot.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function roles() {
        return $this->belongsToMany('\\RSCD\\Model\\Object\\Role', 'user_role');
    }

    /**
     * JSON access policies assigned directly to this user.
     *
     * Policy evaluation unions these with the policies of every role the
     * user holds; see Controller::authorize().
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function accessPolicies() {
        return $this->hasMany('\\RSCD\\Model\\Object\\JsonPolicy', 'user_id');
    }

    /**
     * All sessions ever created for this user.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function sessions() {
        return $this->hasMany('\\RSCD\\Model\\Object\\Session', 'user_id');
    }

    /**
     * The most recently used active session.
     *
     * Note: the Authenticator assigns the request's own Session model to
     * $user->session after cookie/credential auth; that assignment shadows
     * this relation for the rest of the request, which is intended.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function session() {
        return $this->hasOne('\\RSCD\\Model\\Object\\Session', 'user_id')
            ->where('status', Session::STATUS_ACTIVE)
            ->latest('updated_at');
    }

    /**
     * Metadata key-value pairs attached via the user_metadata pivot.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function metadata() {
        return $this->belongsToMany('\\RSCD\\Model\\Object\\Metadata', 'user_metadata');
    }

    /**
     * Tags applied to this user via the user_tag pivot.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function tags() {
        return $this->belongsToMany('\\RSCD\\Model\\Object\\Tag', 'user_tag');
    }

    /**
     * Events recorded against this user via the user_event pivot.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function events() {
        return $this->belongsToMany('\\RSCD\\Model\\Object\\Event', 'user_event');
    }

    // misc

    /**
     * Walk the parent chain to the top-level account.
     *
     * Returns $this when the user has no parent. Depth is bounded to guard
     * against accidental parent_id cycles.
     *
     * @return static
     */
    public function getRoot() {
        $user = $this;
        $depth = 0;
        while(!empty($user->parent_id) && $depth++ < 10) {
            $parent = $user->parentUser;
            if(empty($parent->id) || $parent->id === $user->id) {
                break;
            }
            $user = $parent;
        }
        return $user;
    }

    /**
     * Build the safe, public representation of a user for page templates.
     *
     * Only fields that are harmless in client-side JavaScript belong here.
     *
     * @param  User  $user  The user to represent.
     * @return object
     */
    public static function toObject($user) {
        return (object)[
            'uuid' => $user->uuid,
            'name' => $user->name,
            'email_address' => $user->email_address,
            'timezone' => $user->timezone,
            'status' => $user->status
        ];
    }

    // resolve

    /**
     * Convert a status string to its integer constant.
     *
     * @param  string  $string  Human-readable status (e.g. "Active").
     * @return int              Matching constant, or -1 if unrecognised.
     */
    public static function resolveStatus($string) {
        $status = self::simplify($string);
        switch($status) {
            case 'active':
                return self::STATUS_ACTIVE;
            case 'inactive':
                return self::STATUS_INACTIVE;
            case 'pendingconfirmation':
            case 'pending':
                return self::STATUS_PENDING_CONFIRMATION;
        }
        return -1;
    }

    // html

    /**
     * Build an HTML <option> list for a status select element.
     *
     * @param  int  $selected  Currently selected status constant.
     * @return string          Concatenated HTML <option> tags.
     */
    public static function statusOptions($selected) {
        $options = [
            self::optionify(self::STATUS_ACTIVE, 'Active', $selected == self::STATUS_ACTIVE),
            self::optionify(self::STATUS_INACTIVE, 'Inactive', $selected == self::STATUS_INACTIVE),
            self::optionify(self::STATUS_PENDING_CONFIRMATION, 'Pending confirmation', $selected == self::STATUS_PENDING_CONFIRMATION)
        ];
        return implode('', $options);
    }

    /**
     * Return an HTML badge label for a status value.
     *
     * @param  int  $status  One of the STATUS_* constants.
     * @return string        HTML badge string, or the "Unknown" badge.
     */
    public static function statusLabel($status) {
        switch($status) {
            case self::STATUS_ACTIVE:
                return self::labelify('Active', 'label-success');
            case self::STATUS_INACTIVE:
                return self::labelify('Inactive', 'label-danger');
            case self::STATUS_PENDING_CONFIRMATION:
                return self::labelify('Pending confirmation', 'label-warning');
        }
        return self::unknownLabel();
    }

}
