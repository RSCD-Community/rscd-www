<?php

namespace RSCD\Model\Scope;

/**
 * Eloquent global scope that automatically filters queries by a type column.
 *
 * Used by models that share a single database table across multiple logical types
 * (e.g. PurchaseOrder on the `order` table). Register in the model's boot():
 *
 *   protected static function boot() {
 *       parent::boot();
 *       static::addGlobalScope(new TypeScope(self::TYPE_PURCHASE));
 *   }
 *
 * Once registered, all queries on the model automatically include
 * WHERE type = {type}, so callers never need to add the filter manually.
 *
 * Note: SalesOrder does NOT use this scope — callers must filter by type manually.
 */
class TypeScope implements \Illuminate\Database\Eloquent\Scope {

    /** @var mixed The type value to filter by (typically an integer constant) */
    protected $type;

    /**
     * @param  mixed $type  The type value to inject into every query (e.g. Order::TYPE_PURCHASE).
     */
    public function __construct($type) {
        $this->type = $type;
    }

    /**
     * Apply the type filter to the Eloquent query builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $builder
     * @param  \Illuminate\Database\Eloquent\Model   $model
     * @return void
     */
    public function apply(\Illuminate\Database\Eloquent\Builder $builder, \Illuminate\Database\Eloquent\Model $model) {
        $builder->where('type', $this->type);
    }
}