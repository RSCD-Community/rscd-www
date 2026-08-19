<?php

namespace RSCD\Controller;

use RSCD\Controller\Common\ListingHelpers;
use RSCD\Util\Strings;

/**
 * Static query helpers for building paginated, filtered, and searchable model listings.
 *
 * ObjectController is an abstract utility layer that extends Common\Controller with
 * reusable static methods for fetching Eloquent model collections. It is the base
 * class for all front-end controllers that need to expose paginated lists of
 * models to the UI.
 *
 * Key capabilities:
 *   - getListing()        — Paginated, filtered, and searched model fetch.
 *   - filterRows()        — Apply structured filter arrays to an Eloquent query builder.
 *   - searchAllColumns()  — Full-text LIKE search across all table columns and
 *                           class-specific relation searches (user tags/contact).
 *   - Helper methods for converting filter text notation to SQL operators.
 */
class ObjectController extends \RSCD\Controller\Common\Controller {

    use ListingHelpers;

    /**
     * Build a paginated, filtered, and searched model listing.
     *
     * Constructs an Eloquent query for $class, applies filters, applies a free-text
     * search across all columns (and class-specific relations), and returns both the
     * total match count and the requested page of results.
     *
     * The query is seeded differently depending on which columns the model exposes:
     *   - If 'uuid' column exists: whereNotNull('uuid')
     *   - Else if 'parent_id' exists: whereNotNull('parent_id')
     *   - Otherwise: where('id', '>', 0)
     *
     * @param string   $class        Fully-qualified Eloquent model class name.
     * @param string   $search       Free-text search string (empty string to skip).
     * @param array    $filters      Structured filter array [[column, value, operator?], ...].
     * @param int      $offset       Number of records to skip (for pagination).
     * @param int      $limit        Maximum number of records to return.
     * @param string   $sort         Column name to sort by.
     * @param string   $order        Sort direction: 'ASC' or 'DESC'.
     * @param array    $searchScope  If non-empty, limits column search to these column names.
     * @param string   $tzdata_id    Timezone ID used when converting date filter values.
     * @param mixed    $state        Unused; reserved for future context passing.
     * @param string   $searchMode   Search mode: 'exact' (default), 'all' (AND words), 'any' (OR words).
     * @return array Associative array with keys:
     *               - 'total_models'   (int): total result count before pagination.
     *               - 'queried_models' (Collection): paginated model collection.
     */
    public static function getListing($class, $search, $filters = [], $offset = 0, $limit = 50, $sort = 'updated_at', $order = 'DESC', $searchScope = [], $tzdata_id = 'UTC', $state = null, $searchMode = 'exact') {
        $model = new $class();
        $columns = $model->getColumns();
        // Seed the query with a base constraint that includes all rows.
        $query = (in_array('uuid', $columns) ? $class::whereNotNull('uuid') : (in_array('parent_id', $columns) ? $class::whereNotNull('parent_id') : $class::where('id', '>', 0)));

        if(!empty($filters)) {
            // Special case: a parent_id IS NULL filter must replace the base query seed.
            if(isset($filters[0]) && $filters[0][0] == 'parent_id' && $filters[0][1] === null) {
                $query = $class::whereNull('parent_id');
            }
            static::filterRows($class, $query, $filters, $tzdata_id);
        }

        if(!empty($search)) {
            static::searchAllColumns($class, $query, $columns, $search, $searchScope, $searchMode);
        }

        return [
            'total_models' => $query->count(),
            'queried_models' => $query->orderBy($sort, $order)->offset($offset)->limit($limit)->get()
        ];
    }

    /**
     * Apply a structured filter array to an Eloquent query builder.
     *
     * Processes each filter entry and adds WHERE clauses to $query:
     *   - 'start' / 'end' filters are converted to created_at >= / <= UTC timestamps.
     *   - 'event.uuid' uses a whereHas sub-query on the event relation.
     *   - 'tag_id' / 'tag_name' use a whereHas sub-query on the tags relation.
     *   - 'exclude_uuid' adds a uuid != exclusion clause.
     *   - All other columns use orWhere within a grouped WHERE for OR-within-AND behaviour,
     *     except 'created_at' which always uses AND WHERE (not OR).
     *
     * @param string $class     Fully-qualified model class name (used for type-specific logic).
     * @param mixed  &$query    Eloquent query builder to modify in place.
     * @param array  $filters   Filter entries: [[column, value] or [column, value, operator]].
     * @param string $tzdata_id Timezone ID for converting 'start'/'end' date strings to UTC.
     * @return void
     */
    protected static function filterRows($class, &$query, $filters, $tzdata_id = 'UTC') {
        $dateTime = new \DateTime();
        $dateTime->setTimezone(new \DateTimeZone($tzdata_id));
        $groups = [];
        foreach($filters as $filter) {
            if($filter[0] === 'limited') {
                continue;
            }
            if($filter[0] === 'tag_id') {
                $query->whereHas('tags', function($q) use($filter) {
                    $q->where('tag.id', $filter[1]);
                });
                continue;
            }
            if($filter[0] === 'tag_name') {
                $query->whereHas('tags', function($q) use($filter) {
                    $q->where('tag.name', $filter[1]);
                });
                continue;
            }
            if($filter[0] == 'start') {
                // Convert local date to a UTC ISO-8601 timestamp at the start of day.
                list($year, $month, $day) = explode('-', $filter[1]);
                $dateTime->setDate($year, $month, $day);
                $dateTime->setTime(0, 0, 0);
                $filter[1] = date('Y-m-d\TH:i:s\Z', $dateTime->getTimeStamp());
                //var_dump($filter[1]);
                if(!isset($groups['created_at'])) {
                    $groups['created_at'] = [['>=', $filter[1]]];
                }
                else {
                    $groups['created_at'][] = ['>=', $filter[1]];
                }
            }
            else if($filter[0] == 'end') {
                // Convert local date to a UTC ISO-8601 timestamp at the end of day.
                list($year, $month, $day) = explode('-', $filter[1]);
                $dateTime->setDate($year, $month, $day);
                $dateTime->setTime(23, 59, 59);
                $filter[1] = date('Y-m-d\TH:i:s\Z', $dateTime->getTimeStamp());
                //var_dump($filter[1]);
                if(!isset($groups['created_at'])) {
                    $groups['created_at'] = [['<=', $filter[1]]];
                }
                else {
                    $groups['created_at'][] = ['<=', $filter[1]];
                }
            }
            else {
                if(!isset($groups[$filter[0]])) {
                    if(isset($filter[2])) {
                        $groups[$filter[0]] = [[$filter[2], $filter[1]]];
                    }
                    else {
                        $groups[$filter[0]] = [$filter[1]];
                    }
                }
                else {
                    if(isset($filter[2])) {
                        $groups[$filter[0]][] = [[$filter[2], $filter[1]]];
                    }
                    else {
                        $groups[$filter[0]][] = $filter[1];
                    }
                }
            }
        }

        foreach($groups as $column => $values) {
            if($column === 'event.uuid') {
                // Filter through a has-relation sub-query against the event's uuid column.
                $column = 'uuid';

                $query->whereHas('event', function ($q) use($column, $values) {
                    $array = self::getAsArray($values);
                    foreach($array as $key => $value) {
                        if(is_array($value) && $value[0] === 'NOT' && $value[1] === null) {
                            $q->whereNotNull($column);
                        }
                        else if(is_array($value) && isset($value[1]) && $value[0] === 'NOT') {
                            $q->whereNot($column, $value[1]);
                        }
                        else if(is_array($value) && isset($value[1]) && $value[1] === null) {
                            $q->whereNull($column);
                        }
                        else if(is_array($value) && isset($value[1])) {
                            $q->where($column, $value[0], $value[1]);
                        }
                        else {
                            $q->where($column, $value);
                        }
                    }
                });
                continue;
            }
            if($column == 'exclude_uuid') {
                foreach($values as $value) {
                    $query->where('uuid', '!=', $value);
                }
                continue;
            }
            // General column filter: use AND WHERE for timestamp columns, OR WHERE for others.
            $query->where(function($query) use($column, $values) {
                foreach($values as $value) {
                    if($column === 'created_at') {
                        // Timestamp ranges are AND-ed (both start and end must match).
                        if(is_array($value) && isset($value[1])) {
                            $query->where($column, $value[0], $value[1]);
                        }
                        else {
                            $query->where($column, $value);
                        }
                    }
                    else {
                        // Non-timestamp multi-values are OR-ed inside the group.
                        if(is_array($value) && isset($value[1])) {
                            $query->orWhere($column, $value[0], $value[1]);
                        }
                        else {
                            $query->orWhere($column, $value);
                        }
                    }
                }
            });
        }
    }

    /**
     * Add full-text search WHERE clauses to an Eloquent query builder.
     *
     * Supports three search modes via $searchMode:
     *   - 'exact' (default): treats the entire search string as one phrase
     *   - 'all': splits on whitespace, every word must match (AND)
     *   - 'any': splits on whitespace, at least one word must match (OR)
     *
     * For most models, generates an orWhere LIKE '%term%' across every column.
     * Additionally applies class-specific relation searches:
     *   - User: searches associated tags and the contact name.
     *   - Tag: prefix LIKE match instead of substring match.
     * When $searchScope is non-empty, only the columns listed therein are searched.
     *
     * @param string $class       Fully-qualified model class name.
     * @param mixed  &$query      Eloquent query builder to modify in place.
     * @param array  $columns     List of table column names from the model.
     * @param string $search      Search string to look for.
     * @param array  $searchScope Optional whitelist of column names to limit the search.
     * @param string $searchMode  'exact', 'all', or 'any'.
     * @return void
     */
    protected static function searchAllColumns($class, &$query, $columns, $search, $searchScope = [], $searchMode = 'exact') {
        $terms = [$search];
        if(in_array($searchMode, ['all', 'any'])) {
            $split = preg_split('/\s+/', trim($search), -1, PREG_SPLIT_NO_EMPTY);
            if(count($split) > 1) { $terms = $split; } else { $searchMode = 'exact'; }
        }

        $buildClause = function($q, $term) use($class, $columns, $searchScope) {
            if($class === \RSCD\Model\Object\User::class) {
                $q->orWhereHas('tags', function ($q) use($term) {
                    $q->where('name', 'LIKE', $term . '%');
                });
                $q->orWhereHas('contact', function ($q) use($term) {
                    $q->where('name', 'LIKE', $term . '%');
                });
            }
            foreach($columns as $column) {
                if(!empty($searchScope) && !in_array($column, $searchScope)) {
                    continue;
                }
                if($class == \RSCD\Model\Object\Tag::class) {
                    $q->orWhere($column, 'LIKE', $term . '%');
                    continue;
                }
                $q->orWhere($column, 'LIKE', '%' . $term . '%');
            }
        };

        if($searchMode === 'exact') {
            $query->where(function($q) use($buildClause, $search) {
                $buildClause($q, $search);
            });
        } elseif($searchMode === 'all') {
            foreach($terms as $term) {
                $query->where(function($q) use($buildClause, $term) {
                    $buildClause($q, $term);
                });
            }
        } elseif($searchMode === 'any') {
            $query->where(function($outerQ) use($terms, $buildClause) {
                foreach($terms as $term) {
                    $outerQ->orWhere(function($q) use($buildClause, $term) {
                        $buildClause($q, $term);
                    });
                }
            });
        }
    }

}
