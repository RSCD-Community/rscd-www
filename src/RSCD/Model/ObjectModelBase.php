<?php
namespace RSCD\Model;
use Illuminate\Database\Eloquent\Model;
use RSCD\Util\Strings;

/**
 * Base Eloquent model for all RSCD object models.
 *
 * Provides common infrastructure shared by all model classes:
 *   - Standard $filters, $columns, $importable, $fillable, and $dates arrays
 *   - Getters for those arrays
 *   - A no-op scopeAll() so all queries can chain ->all()
 *   - Static utility helpers: toObject(), toMap(), simplify(), optionify(),
 *     labelify(), and unknownLabel()
 */
class ObjectModelBase extends Model {
    protected $table;
    protected $filters = [];
    protected $columns = [];
    protected $importable = [];
    protected $fillable = [];
    protected $dates = [
        'created_at',
        'updated_at'
    ];
    /**
     * Return the list of filterable column names for this model.
     *
     * @return array
     */
    public function getFilters() {
        return $this->filters;
    }
    /**
     * Return the full list of column names for this model.
     *
     * @return array
     */
    public function getColumns() {
        return $this->columns;
    }
    /**
     * Return the list of fillable (mass-assignable) column names.
     *
     * @return array
     */
    public function getFillableColumns() {
        return $this->fillable;
    }
    /**
     * Return the list of importable column names for this model.
     *
     * @return array
     */
    public function getImportableColumns() {
        return $this->importable;
    }
    /**
     * No-op scope so callers can chain ->all() on any query builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAll( $query ) {
        return $query;
    }
    //misc
    /**
     * Cast any value to a stdClass object via JSON round-trip.
     *
     * @param  mixed $object  Value to decode.
     * @return object
     */
    public static function toObject($object) {
        return json_decode((string)$object);
    }
    /**
     * Flatten a model or object into a dot-notated key-value map.
     *
     * @param  mixed  $model   Model or object to flatten.
     * @param  string $prefix  Prefix to prepend to all keys.
     * @return array
     */
    public static function toMap($model = null, $prefix = '') {
        $object = self::toObject($model);
        $map = [];
        foreach($object as $key => $value) {
            if(is_object($value)) {
                $map = array_merge($map, $this->toMap($object,$prefix . '.' . $key));
            }
            $map[$prefix . '.' . $key] = $value;
        }
        return $map;
    }
    /**
     * Normalise a string to lowercase trimmed form, replacing &nbsp; with space.
     *
     * @param  string $string  Input string.
     * @return string
     */
    protected static function simplify( $string ) {
        return str_replace( '&nbsp;' , ' ' , Strings::trim( mb_strtolower( $string , 'UTF-8' ) ) );
    }
    /**
     * Build a single HTML <option> tag.
     *
     * @param  mixed  $value     Option value attribute.
     * @param  string $text      Option display text.
     * @param  bool   $selected  Whether this option should be selected.
     * @return string            HTML <option> tag.
     */
    protected static function optionify( $value , $text , $selected = false ) {
        return '<option value="' . $value . '"' . ( $selected ? ' SELECTED' : '' ) . '>' . $text . '</option>';
    }
    /**
     * Wrap text in a status-label span with the given CSS class.
     *
     * @param  string $text   Display text (spaces replaced with &nbsp;).
     * @param  string $class  Label CSS class (e.g. "label-success").
     * @return string         HTML span element.
     */
    protected static function labelify( $text , $class ) {
        return '<span class="label ' . $class . '">' . str_replace( ' ' , '&nbsp;' , $text ) . '</span>';
    }
    /**
     * Return an HTML badge for an unrecognised/unknown value.
     *
     * @return string
     */
    protected static function unknownLabel() {
        return self::labelify( 'Unknown' , 'label-unknown' );
    }
}
