<?php

namespace RSCD\Controller\Admin\Common;

use RSCD\Controller\Common\ListingHelpers;
use RSCD\Model\State;
use RSCD\Util\Strings;

/**
 * Base admin controller providing paginated listing, filtering, and search
 * capabilities shared by all object-level admin controllers.
 *
 * Key static methods:
 * - createListingObject(): parses URL query variables (query, filters, page,
 *   limit, sort, mode) into a normalised listing config array.  Filters are
 *   encoded as semicolon-separated key:value pairs with optional operators
 *   (!=, >=, <=, <, >).
 * - getListing(): executes a paginated, filtered, searched Eloquent query for
 *   any Model class.  Returns total_models and queried_models.  Applies
 *   special scopes (Metadata distinct queries).
 * - filterRows(): translates the parsed filter array into Eloquent where
 *   clauses, including special virtual columns (event.uuid, tag_id, tag_name)
 *   and date range filters (start/end → created_at >= / <=).
 * - searchAllColumns(): applies a LIKE %search% against all model columns and
 *   class-specific relationship searches (user contact/metadata, tags).
 *
 * Helper utilities:
 * - getNullFromText(): converts "null"/"nul" strings to PHP null.
 * - getAsArray(): wraps a scalar in an array if not already one.
 * - getOperatorSymbolFromText(): maps text aliases (eq/neq/lt/lte/gt/gte/like)
 *   to SQL operator strings.
 */
class ObjectController extends \RSCD\Controller\Admin\Common\PageController {

    use ListingHelpers;

    /**
     * Builds a normalised listing configuration array from URL query variables.
     *
     * Reads query, filters, page, limit, sort, and mode from the URL or the
     * provided $state.  Filters are decoded from a semicolon-separated string
     * of key:value or key{op}value pairs (supported operators: !=, >=, <=, <, >).
     * Defaults: page=1, limit=25 (max 100), sort=updated_at, mode=desc.
     *
     * @param mixed|null $state Application state; uses State::get() if null.
     * @return array{query: string, filters: string, page: int, limit: int, sort: string, mode: string}
     *              JSON-encoded query and filters plus integer pagination params.
     */
    protected static function createListingObject($state = null) {
        if(empty($state)) {
            $state = State::get();
        }

        $listingQuery = $state->url->getVariable('query');
        $listingFilters = $state->url->getVariable('filters');
        $listingPage = (int)$state->url->getVariable('page');
        $listingLimit = (int)$state->url->getVariable('limit');
        $listingSort = $state->url->getVariable('sort');
        $listingMode = $state->url->getVariable('mode');
        $listingFilterArray = [];

        if(empty($listingQuery)) {
            $listingQuery = '';
        }
        if(empty($listingFilters)) {
            $listingFilters = '';
        }
        if($listingPage < 1 || !is_int($listingPage)) {
            $listingPage = 1;
        }
        if(empty($listingLimit) || $listingLimit <= 0 || !is_int($listingLimit)) {
            $listingLimit = 25;
        }
        if($listingLimit > 100) {
            $listingLimit = 100;
        }
        if(empty($listingSort)) {
            $listingSort = 'updated_at';
        }
        if(empty($listingMode)) {
            $listingMode = 'desc';
        }

        // Decode semicolon-separated filter segments into [column, value] or [column, value, operator] tuples
        $listingFilterStringSegments = explode(';', $listingFilters);

        foreach($listingFilterStringSegments as $segment) {
            // Skip segments that contain no operator character at all
            if(strlen(str_replace([':', '!=', '>=', '<=', '<', '>'], '', $segment)) === strlen($segment)) {
                continue;
            }
            $key = null;
            $value = null;
            $symbol = null;
            if(strpos($segment, ':') !== false) {
                // Simple equality filter: column:value
                 list($key, $value) = explode(':', $segment);
            }
            else {
                // Detect comparison operator in priority order (multi-char before single-char)
                if(strpos($segment, '!=') !== false) {
                     $symbol = '!=';
                }
                else if(strpos($segment, '>=') !== false) {
                     $symbol = '>=';
                }
                else if(strpos($segment, '<=') !== false) {
                     $symbol = '<=';
                }
                else if(strpos($segment, '<') !== false) {
                     $symbol = '<';
                }
                else if(strpos($segment, '>') !== false) {
                     $symbol = '>';
                }
            }
            if(!empty($symbol)) {
                // Comparison filter: [column, value, operator]
                list($key, $value) = explode($symbol, $segment);
                $listingFilterArray[] = [
                    Strings::trim($key),
                    Strings::trim($value),
                    Strings::trim($symbol)
                ];
            }
            else {
                // Equality filter: [column, value]
                $listingFilterArray[] = [
                    Strings::trim($key),
                    Strings::trim($value)
                ];
            }
        }
        return [
            'query' => json_encode($listingQuery),
            'filters' => json_encode($listingFilterArray),
            'page' => $listingPage,
            'limit' => $listingLimit,
            'sort' => $listingSort,
            'mode' => $listingMode,
        ];
    }

    /**
     * Renders a complete server-side listing page (no client-side JavaScript).
     *
     * Reads query/page/limit/sort/mode from the URL via createListingObject(),
     * runs getListing(), and assembles a search form, sortable table, and
     * pagination links into the shared admin/listing.html template.
     *
     * $config keys:
     * - 'class'       Fully-qualified Eloquent model class (required).
     * - 'title'       Page heading (required).
     * - 'baseUrl'     Module listing path relative to the site base,
     *                 e.g. 'admin/roles/list/' (required).
     * - 'columns'     List of column definitions (required); each is an array:
     *                 ['label' => string,
     *                  'sort' => string|null   sortable DB column, null = not sortable,
     *                  'render' => callable(model): string  returns SAFE (escaped) HTML].
     * - 'defaultSort' Default sort column (default 'updated_at').
     * - 'load'        Relations to lazy-eager-load onto the result collection.
     * - 'actions'     Safe HTML for the action bar (e.g. a Create link), default ''.
     *
     * @param mixed $state  Application state object.
     * @param array $config Listing configuration (see above).
     * @return void
     */
    protected function renderListingPage($state, array $config) {
        $view = $this->get('view');
        $listing = static::createListingObject($state);
        $query = (string)json_decode($listing['query']);
        $filters = json_decode($listing['filters'], true) ?: [];
        $page = $listing['page'];
        $limit = $listing['limit'];
        $sort = $listing['sort'];
        $mode = strtolower($listing['mode']) === 'asc' ? 'asc' : 'desc';

        if(!empty($config['defaultSort']) && $state->url->getVariable('sort') === null) {
            $sort = $config['defaultSort'];
        }
        // Only allow sorting by columns this listing declares — never raw URL input.
        $sortable = [];
        foreach($config['columns'] as $column) {
            if(!empty($column['sort'])) {
                $sortable[] = $column['sort'];
            }
        }
        if(!in_array($sort, $sortable, true)) {
            $sort = !empty($config['defaultSort']) ? $config['defaultSort'] : 'updated_at';
        }

        // The requested page has to be clamped to the actual last page before
        // it can be turned into an offset -- otherwise a stale or hand-edited
        // ?page= beyond the end runs the real query with an offset past every
        // row (Game.php's httpGetPlayers() does this with a cheap count()
        // first; do the same here). So: find the total first, clamp, then run
        // the real listing query with the corrected offset.
        $counted = static::getListing(
            $config['class'], $query, $filters, 0, 1,
            $sort, strtoupper($mode), [], $state->defaultTimeZone->tzdata_id ?? 'UTC', $state
        );
        $total = (int)$counted['total_models'];
        $pages = max(1, (int)ceil($total / $limit));
        $page = min(max($page, 1), $pages);

        $results = static::getListing(
            $config['class'], $query, $filters, ($page - 1) * $limit, $limit,
            $sort, strtoupper($mode), [], $state->defaultTimeZone->tzdata_id ?? 'UTC', $state
        );
        $models = $results['queried_models'];
        if(!empty($config['load'])) {
            $models->load($config['load']);
        }

        $base = $state->url->getBaseUrl() . $config['baseUrl'];
        $link = function(array $overrides) use ($base, $query, $sort, $mode, $limit) {
            $params = array_merge(
                ['query' => $query, 'sort' => $sort, 'mode' => $mode, 'limit' => $limit],
                $overrides
            );
            $params = array_filter($params, function($value) { return $value !== '' && $value !== null; });
            return htmlspecialchars($base . (empty($params) ? '' : '?' . http_build_query($params)), ENT_QUOTES);
        };

        // Search form (plain GET; the router merges query-string params into URL variables).
        $search = '<form class="listing-search" method="GET" action="' . htmlspecialchars($base, ENT_QUOTES) . '">'
                . '<input type="text" name="query" value="' . htmlspecialchars($query, ENT_QUOTES) . '" placeholder="Search for..." />'
                . '<button type="submit">Search</button>'
                . ($query !== '' ? ' <a href="' . $link(['query' => '', 'page' => 1]) . '">Clear</a>' : '')
                . '</form>';

        // Table head with sort toggles.
        $table = '<table class="listing-table"><thead><tr>';
        foreach($config['columns'] as $column) {
            $label = htmlspecialchars($column['label'], ENT_QUOTES);
            if(!empty($column['sort'])) {
                $isActive = $column['sort'] === $sort;
                $nextMode = $isActive && $mode === 'asc' ? 'desc' : 'asc';
                $marker = $isActive ? ($mode === 'asc' ? ' &#9650;' : ' &#9660;') : '';
                $table .= '<th><a href="' . $link(['sort' => $column['sort'], 'mode' => $nextMode, 'page' => 1]) . '">' . $label . $marker . '</a></th>';
            }
            else {
                $table .= '<th>' . $label . '</th>';
            }
        }
        $table .= '</tr></thead><tbody>';
        if($models->count() === 0) {
            $table .= '<tr><td colspan="' . count($config['columns']) . '" class="listing-empty">Nothing found.</td></tr>';
        }
        foreach($models as $model) {
            $table .= '<tr>';
            foreach($config['columns'] as $column) {
                $table .= '<td>' . $column['render']($model) . '</td>';
            }
            $table .= '</tr>';
        }
        $table .= '</tbody></table>';

        // Pagination: previous / windowed page numbers / next.
        $pagination = '<div class="listing-pagination"><span>' . $total . ' result' . ($total === 1 ? '' : 's') . '</span>';
        if($pages > 1) {
            if($page > 1) {
                $pagination .= ' <a href="' . $link(['page' => $page - 1]) . '">&laquo; Prev</a>';
            }
            for($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++) {
                $pagination .= $i === $page
                    ? ' <span class="current-page">' . $i . '</span>'
                    : ' <a href="' . $link(['page' => $i]) . '">' . $i . '</a>';
            }
            if($page < $pages) {
                $pagination .= ' <a href="' . $link(['page' => $page + 1]) . '">Next &raquo;</a>';
            }
        }
        $pagination .= '</div>';

        // Flash messages carried across a POST -> redirect via msg=/err= URL variables.
        $alerts = '';
        if(($msg = $state->url->getVariable('msg')) !== null) {
            $alerts .= '<div class="alert alert-success">' . htmlspecialchars((string)$msg, ENT_QUOTES) . '</div>';
        }
        if(($err = $state->url->getVariable('err')) !== null) {
            $alerts .= '<div class="alert alert-danger">' . htmlspecialchars((string)$err, ENT_QUOTES) . '</div>';
        }

        $layout = $view->getViewLayout('admin' . DIRECTORY_SEPARATOR . 'listing.html');
        $layout->populateHtmlFromFile();
        $layout->injectHtml('listing_alerts', $alerts);
        $layout->injectHtml('listing_title', htmlspecialchars($config['title'], ENT_QUOTES));
        $layout->injectHtml('listing_actions', (string)($config['actions'] ?? ''));
        $layout->injectHtml('listing_search', $search);
        $layout->injectHtml('listing_table', $table);
        $layout->injectHtml('listing_pagination', $pagination);
        $view->setPage($layout->get('html'), [], $config['title']);
    }

    /**
     * Parses standard listing parameters from POST data with URL variable fallback.
     *
     * Extracts query, filters, page, limit, sort, mode, and search_mode from
     * the POST body, falling back to URL variables for missing values. Validates
     * and clamps limit to 1-100, defaults sort to 'updated_at' and mode to
     * 'desc'. search_mode defaults to 'exact' (valid: 'exact', 'all', 'any').
     *
     * @param mixed  $state       Application state object.
     * @param string $defaultSort Default sort column (default: 'updated_at').
     * @return array{query: string, filters: array, page: int, limit: int, sort: string, mode: string, offset: int, search_mode: string}
     */
    protected function parsePostListingParams($state, string $defaultSort = 'updated_at'): array {
        $data = (object)$this->getPostData([
            'query' => FILTER_SANITIZE_STRING,
            'filters' => FILTER_UNSAFE_RAW,
            'page' => FILTER_SANITIZE_NUMBER_INT,
            'limit' => FILTER_SANITIZE_NUMBER_INT,
            'sort' => FILTER_SANITIZE_STRING,
            'mode' => FILTER_SANITIZE_STRING,
            'search_mode' => FILTER_SANITIZE_STRING,
            'search_scope' => FILTER_SANITIZE_STRING,
        ]);
        $filters = !empty($data->filters) ? json_decode($data->filters) : [];
        $query = !empty($data->query) ? $data->query : $state->url->getVariable('query');
        $page = !empty($data->page) ? (int)$data->page : 1;
        $limit = !empty($data->limit) ? (int)$data->limit : 25;
        $sort = !empty($data->sort) ? $data->sort : $defaultSort;
        $mode = !empty($data->mode) ? $data->mode : 'desc';
        $searchMode = !empty($data->search_mode) && in_array($data->search_mode, ['all', 'any']) ? $data->search_mode : 'exact';
        if ($limit > 100) { $limit = 100; }
        elseif ($limit < 1) { $limit = 1; }
        return [
            'query' => $query ?: '',
            'filters' => is_array($filters) ? $filters : [],
            'page' => $page,
            'limit' => $limit,
            'sort' => $sort,
            'mode' => $mode,
            'offset' => ($page - 1) * $limit,
            'search_mode' => $searchMode,
        ];
    }

    /**
     * Executes a paginated, filtered, and searched Eloquent query.
     *
     * Starts from a base query (whereNotNull('uuid') for most models, or a
     * fallback).  Applies class-specific scopes (Metadata distinct), filters
     * via filterRows(), and full-text search via searchAllColumns().  Returns
     * the total count before pagination plus the paginated result set.
     *
     * @param string     $class        Fully-qualified Eloquent model class name.
     * @param string     $search       Full-text search string (empty to skip).
     * @param array      $filters      Parsed filter tuples from createListingObject.
     * @param int        $offset       Row offset for pagination.
     * @param int        $limit        Maximum rows to return.
     * @param string     $sort         Column to order by.
     * @param string     $order        Direction: 'ASC' or 'DESC'.
     * @param array      $searchScope  Columns to restrict search to (empty = all).
     * @param string     $tzdata_id    Time zone ID for date filter conversion.
     * @param mixed|null $state        Application state; uses State::get() if null.
     * @param string     $searchMode   Search mode: 'exact' (default), 'all' (AND words), 'any' (OR words).
     * @return array{total_models: int, queried_models: \Illuminate\Support\Collection}
     */
    protected static function getListing($class, $search, $filters = [], $offset = 0, $limit = 50, $sort = 'updated_at', $order = 'DESC', $searchScope = [], $tzdata_id = 'UTC', $state = null, $searchMode = 'exact') {
        $model = new $class();
        $columns = $model->getColumns();
        // Choose the base query based on which identifying column the model has
        $query = (in_array('uuid', $columns) ? $class::whereNotNull('uuid') : (in_array('parent_id', $columns) ? $class::whereNotNull('parent_id') : $class::where('id', '>', 0)));
        $limited = false;
        if(!empty($filters)) {
            // Check if the 'limited' flag is set in filters to restrict search scope
            foreach($filters as $filter) {
                if($filter[0] === 'limited' && (int)$filter[1] === 1) {
                    $limited = true;
                    break;
                }
            }
        }

        if($state === null) {
            $state = State::get();
        }

        // Metadata models use distinct queries to avoid duplicates
        if($class == \RSCD\Model\Object\Metadata::class) {
            $query = $class::distinct('metakey');
        }

        if(!empty($filters)) {
            static::filterRows($class, $query, $filters, $tzdata_id, $limited);
        }
        if(!empty($search)) {
            static::searchAllColumns($class, $query, $columns, $search, $searchScope, $limited, $searchMode);
        }

        return [
            'total_models' => $query->count(),
            'queried_models' => $query->orderBy($sort, $order)->offset($offset)->limit($limit)->get()
        ];
    }

    /**
     * Applies filter tuples to an Eloquent query builder.
     *
     * Translates the parsed filter array into where/orWhere/whereHas clauses.
     * Special virtual column names are handled before the generic column path:
     * - 'start' / 'end': map to created_at >= / <= with midnight/23:59:59 in $tzdata_id
     * - 'event.uuid': whereHas('event')
     * - 'user_id' (Event listings): whereHas('users') via the user_event pivot
     * - 'tag_id' / 'tag_name': whereHas('tags')
     *
     * Multiple values for the same column are combined with OR within a WHERE group.
     *
     * @param string $class      Fully-qualified model class name (unused, reserved).
     * @param mixed  &$query     Eloquent query builder (modified in-place).
     * @param array  $filters    Parsed filter tuples: [[column, value], [column, value, op], ...].
     * @param string $tzdata_id  Time zone ID for date range conversion.
     * @param bool   $limited    Whether the 'limited' flag is active (unused here).
     * @return void
     */
    protected static function filterRows($class, &$query, $filters, $tzdata_id = 'UTC', $limited = false) {
        $dateTime = new \DateTime();
        $dateTime->setTimezone(new \DateTimeZone($tzdata_id));
        $groups = [];

        // Group filter values by column name so multiple values for the same
        // column can be combined as OR conditions in a single WHERE group
        foreach($filters as $filter) {
            if($filter[0] === 'limited') {
                continue;
            }
            if($filter[0] == 'start') {
                // 'start' filter: convert YYYY-MM-DD to midnight UTC timestamp
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
                // 'end' filter: convert YYYY-MM-DD to end-of-day UTC timestamp
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
                // Virtual column: filter by related event UUID
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
            if($column === 'user_id' && $class === \RSCD\Model\Object\Event::class) {
                $array = self::getAsArray($values);
                foreach($array as $value) {
                    $query->whereHas('users', function($q) use($value) { $q->where('user.id', $value); });
                }
                continue;
            }
            if($column === 'tag_id') {
                // Virtual column: filter by tag id via pivot
                $array = self::getAsArray($values);
                foreach($array as $value) {
                    $query->whereHas('tags', function($q) use($value) {
                        $q->where('tag.id', $value);
                    });
                }
                continue;
            }
            if($column === 'tag_name') {
                // Virtual column: filter by tag name via pivot
                $array = self::getAsArray($values);
                foreach($array as $value) {
                    $query->whereHas('tags', function($q) use($value) {
                        $q->where('tag.name', $value);
                    });
                }
                continue;
            }

            // Generic column filter: wrap multiple values in an OR group
            $query->where(function($query) use($column, $values) {
                foreach($values as $value) {
                    if($column === 'created_at') {
                        // Date range filters use AND (not OR) to support start+end together
                        if(is_array($value) && isset($value[1])) {
                            $query->where($column, $value[0], $value[1]);
                        }
                        else {
                            $query->where($column, $value);
                        }
                    }
                    else {
                        if(is_array($value) && $value[0] === 'NOT' && $value[1] === null) {
                            $query->orWhereNotNull($column);
                        }
                        else if(is_array($value) && isset($value[1]) && $value[0] === 'NOT') {
                            $query->orWhereNot($column, $value[1]);
                        }
                        else if(is_array($value) && isset($value[1]) && $value[1] === null) {
                            $query->orWhereNull($column);
                        }
                        else if(is_array($value) && isset($value[1])) {
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
     * Applies full-text search across all model columns and key relationships.
     *
     * Supports three search modes via $searchMode:
     *   - 'exact' (default): treats the entire search string as one phrase
     *   - 'all': splits on whitespace, every word must match (AND)
     *   - 'any': splits on whitespace, at least one word must match (OR)
     *
     * Class-specific relationship searches are added before the column loop:
     * - Metadata: restricted to metakey prefix match
     * - User: also searches tags, metadata, and contact.name
     *
     * When $searchScope is non-empty, only columns in that array are searched.
     *
     * @param string $class        Fully-qualified model class name.
     * @param mixed  &$query       Eloquent query builder (modified in-place).
     * @param array  $columns      All column names for the model.
     * @param string $search       The search string.
     * @param array  $searchScope  Optional column whitelist; empty = search all columns.
     * @param bool   $limited      When true, skip relationship searches and use column-only.
     * @param string $searchMode   'exact', 'all', or 'any'.
     * @return void
     */
    protected static function searchAllColumns($class, &$query, $columns, $search, $searchScope = [], $limited = false, $searchMode = 'exact') {

        if($class == \RSCD\Model\Object\Metadata::class) {
            $query->where('metakey', 'LIKE', $search . '%');
            return;
        }

        $terms = [$search];
        if(in_array($searchMode, ['all', 'any'])) {
            $split = preg_split('/\s+/', trim($search), -1, PREG_SPLIT_NO_EMPTY);
            if(count($split) > 1) { $terms = $split; } else { $searchMode = 'exact'; }
        }

        $buildClause = function($q, $term) use($class, $columns, $searchScope, $limited) {
            if($limited) {
                foreach($columns as $column) {
                    if(!empty($searchScope) && !in_array($column, $searchScope)) { continue; }
                    $q->orWhere($column, 'LIKE', '%' . $term . '%');
                }
                return;
            }

            if($class == \RSCD\Model\Object\User::class) {
                $q->orWhereHas('tags', function ($q) use($term) {
                    $q->where('name', 'LIKE', $term . '%');
                });
                $q->orWhereHas('metadata', function ($q) use($term) {
                    $q->where('metakey', 'LIKE', $term . '%');
                    $q->orWhere('metavalue', 'LIKE', '%' . $term . '%');
                });
                $q->orWhereHas('contact', function ($q) use($term) {
                    $q->where('name', 'LIKE', $term . '%');
                });
            }
            foreach($columns as $column) {
                if(!empty($searchScope) && !in_array($column, $searchScope)) { continue; }
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
