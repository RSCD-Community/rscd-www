<?php

namespace RSCD\Model\Object;

/**
 * A JSON-encoded access control policy.
 *
 * JsonPolicy records store rule or resource definitions that govern what a
 * user is allowed to see or do. Policies attach either directly to a user
 * (user_id) or to a role via the role_json_policy pivot; the authorization
 * layer (Controller::authorize() → RuleManager) unions both sets at request
 * time and evaluates them against the controller's condition slugs.
 *
 * Two policy types are supported:
 * - TYPE_RULE: a permission rule document (collections of ALLOW/DENY rules
 *   with condition patterns, e.g. {"action":"ALLOW","conditions":["%"]}).
 * - TYPE_RESOURCE: a resource scope document.
 *
 * The `value` column contains the raw JSON policy document.
 *
 * @property int    $id
 * @property string $uuid
 * @property int    $user_id    FK to the User this policy applies to (0/null for role policies).
 * @property int    $type       One of TYPE_RULE or TYPE_RESOURCE.
 * @property string $value      JSON-encoded policy document.
 * @property string $created_at
 * @property string $updated_at
 */
class JsonPolicy extends \RSCD\Model\Object\Common\Model {

    /** Policy that defines an access permission rule. */
    const TYPE_RULE = 1;

    /** Policy that defines the set of resources a user can access. */
    const TYPE_RESOURCE = 2;

    /**
     * Column metadata used by the framework for validation and display.
     *
     * @var array<string, array<string, mixed>>
     */
    const COLUMN_FORMAT = [
        'id' => ['type' => 'integer', 'name' => 'ID', 'min' => 0, 'max' => self::SIGNED_INT_32],
        'uuid' => ['type' => 'string', 'name' => 'UUID', 'minlength' => 36, 'maxlength' => 36],
        'user_id' => ['type' => 'integer', 'name' => 'User ID', 'min' => 0, 'max' => self::SIGNED_INT_32],
        'type' => ['type' => 'integer', 'name' => 'Type', 'min' => 0, 'max' => self::SIGNED_INT_8],
        'value' => ['type' => 'string', 'name' => 'Policy JSON'],
        'created_at' => ['type' => 'datetime', 'name' => 'Created at'],
        'updated_at' => ['type' => 'datetime', 'name' => 'Updated at']
    ];

    protected $table = 'json_policy';
    protected $columns = [
        'id',
        'uuid',
        'user_id',
        'type',
        'value',
        'created_at',
        'updated_at'
    ];
    protected $fillable = [
        'user_id',
        'type',
        'value'
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

    /**
     * The user this policy is directly assigned to, if any.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user() {
        return $this->belongsTo('\\RSCD\\Model\\Object\\User', 'user_id');
    }

    /**
     * Roles carrying this policy via the role_json_policy pivot.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function roles() {
        return $this->belongsToMany('\\RSCD\\Model\\Object\\Role', 'role_json_policy');
    }
}
