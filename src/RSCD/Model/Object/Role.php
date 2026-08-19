<?php

namespace RSCD\Model\Object;

/**
 * Role model — a named permission group that can be assigned to users.
 *
 * Roles sit at the broad permission level. A user may have multiple roles,
 * and each role carries one or more JsonPolicies that define fine-grained
 * allow/deny rules in JSON. Assignment to users is tracked via the
 * user_role pivot; policies attach via the role_json_policy pivot.
 *
 * Table: role
 */
class Role extends \RSCD\Model\Object\Common\Model {

    /**
     * Column metadata used by the framework for validation and display.
     *
     * The 'required' array on uuid and name means both must be present together.
     *
     * @var array<string, array<string, mixed>>
     */
    const COLUMN_FORMAT = [
        'id' => ['type' => 'integer', 'name' => 'ID', 'min' => 0, 'max' => self::SIGNED_INT_32],
        'uuid' => ['type' => 'string', 'name' => 'UUID', 'minlength' => 36, 'maxlength' => 36, 'required' => ['uuid', 'name']],
        'name' => ['type' => 'string', 'name' => 'Name', 'maxlength' => 255, 'required' => ['uuid', 'name']],
        'created_at' => ['type' => 'datetime', 'name' => 'Created at'],
        'updated_at' => ['type' => 'datetime', 'name' => 'Updated at'],
        'access_policies' => ['name' => 'Access Policy', 'class' => '\\RSCD\\Model\\Object\\JsonPolicy']
    ];

    /**
     * The underlying database table.
     *
     * @var string
     */
    protected $table = 'role';

    /**
     * All columns on this table.
     *
     * @var string[]
     */
    protected $columns = [
        'id',
        'uuid',
        'name',
        'created_at',
        'updated_at'
    ];

    /**
     * Mass-assignable columns.
     *
     * @var string[]
     */
    protected $fillable = [
        'uuid',
        'name'
    ];

    /**
     * Boot the model and register the UniqueModel observer.
     *
     * Auto-generates a UUID on record creation.
     *
     * @return void
     */
    public static function boot(){
        parent::boot();
        static::observe(\RSCD\Model\Observer\UniqueModel::class);
    }

    /**
     * JSON access policies attached to this role via the role_json_policy pivot.
     *
     * When evaluating permissions the system unions all policies from a
     * user's directly-assigned policies and from all roles they hold.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function accessPolicies() {
        return $this->belongsToMany('\\RSCD\\Model\\Object\\JsonPolicy', 'role_json_policy');
    }

    /**
     * Users holding this role via the user_role pivot.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function users() {
        return $this->belongsToMany('\\RSCD\\Model\\Object\\User', 'user_role');
    }
}
