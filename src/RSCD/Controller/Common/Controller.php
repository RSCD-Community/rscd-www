<?php

namespace RSCD\Controller\Common;

use RSCD\Model\Authenticator;
use RSCD\Model\Object\User;
use RSCD\Model\State;
use RSCD\View\JSONView;
use RSCD\Controller\RuleManager;
use RSCD\Util\CURL;
use RSCD\Util\Geography;
use RSCD\Util\Strings;

/**
 * Base controller for all RSCD HTTP controllers.
 *
 * Extends the framework's root controller to add application-specific concerns:
 *   - Cookie-based authentication and role/access-policy rule evaluation
 *   - JSON response helpers (showJson, showJsonAndKeepAlive)
 *   - Spreadsheet import parsing (parseSpreadsheet, mapImportObjects)
 *   - Data validation framework (validateObject with type checking, min/max,
 *     length limits, email DNS verification, state/country/timezone validation)
 *   - HTML entity encoding round-trip helpers
 *   - Filter text parsing (getArrayFromFilterText)
 *   - Timezone utilities (getSystemTimeZone, getTimeZone, getTimeZoneOffsetHours)
 *
 * All RSCD controllers should extend this class (directly or indirectly)
 * rather than the framework root to ensure consistent auth and response patterns.
 */
class Controller extends \RSCD\Controller\Common\ControllerBase {

    /**
     * US state and territory abbreviation-to-name map.
     *
     * Used by the 'state_code' validation type in validateObject() to verify
     * that a submitted two-letter code is a valid US state or territory.
     */
    const STATES = [
        'AL'=>"Alabama",
        'AK'=>"Alaska",
        'AZ'=>"Arizona",
        'AR'=>"Arkansas",
        'CA'=>"California",
        'CO'=>"Colorado",
        'CT'=>"Connecticut",
        'DE'=>"Delaware",
        'DC'=>"District Of Columbia",
        'FL'=>"Florida",
        'GA'=>"Georgia",
        'HI'=>"Hawaii",
        'ID'=>"Idaho",
        'IL'=>"Illinois",
        'IN'=>"Indiana",
        'IA'=>"Iowa",
        'KS'=>"Kansas",
        'KY'=>"Kentucky",
        'LA'=>"Louisiana",
        'ME'=>"Maine",
        'MD'=>"Maryland",
        'MA'=>"Massachusetts",
        'MI'=>"Michigan",
        'MN'=>"Minnesota",
        'MS'=>"Mississippi",
        'MO'=>"Missouri",
        'MT'=>"Montana",
        'NE'=>"Nebraska",
        'NV'=>"Nevada",
        'NH'=>"New Hampshire",
        'NJ'=>"New Jersey",
        'NM'=>"New Mexico",
        'NY'=>"New York",
        'NC'=>"North Carolina",
        'ND'=>"North Dakota",
        'OH'=>"Ohio",
        'OK'=>"Oklahoma",
        'OR'=>"Oregon",
        'PA'=>"Pennsylvania",
        'RI'=>"Rhode Island",
        'SC'=>"South Carolina",
        'SD'=>"South Dakota",
        'TN'=>"Tennessee",
        'TX'=>"Texas",
        'UT'=>"Utah",
        'VT'=>"Vermont",
        'VA'=>"Virginia",
        'WA'=>"Washington",
        'WV'=>"West Virginia",
        'WI'=>"Wisconsin",
        'WY'=>"Wyoming",
        'AS'=>"American Samoa",
        'GU'=>"Guam",
        'MH'=>"Marshall Islands",
        'FM'=>"Micronesia",
        'MP'=>"Northern Mariana Islands",
        'PW'=>"Palau",
        'PR'=>"Puerto Rico",
        'VI'=>"Virgin Islands"
    ];

    /**
     * Entry point called by the framework router for every request.
     *
     * Delegates to beforeProcessAction() which handles pre-processing hooks.
     *
     * @return mixed Result of the action handler.
     */
    public function processAction() {
        return $this->beforeProcessAction();
    }

    /**
     * Pre-processing hook executed before action dispatch.
     *
     * Subclasses may override this to inject auth checks or other middleware
     * that must run before the action is resolved.
     *
     * @return mixed Result of findActionToProcess().
     */
    protected function beforeProcessAction() {
        return $this->findActionToProcess();
    }

    /**
     * Resolve and invoke the correct action handler for the current request.
     *
     * Converts the request action and method into a camelCase function name
     * (e.g. GET /foo → httpGetFoo) and calls it if it exists on this controller.
     * Falls back to processDefaultAction() when no matching method is found.
     *
     * @return mixed Result of the resolved action handler.
     */
    protected function findActionToProcess() {
        $state = $this->getState();
        $functionName = $this->getFunctionName($state->request);
        if(method_exists($this, $functionName)) {
            return $this->$functionName($state);
        }
        return $this->processDefaultAction($state);
    }

    /**
     * Authenticate the current request using a session cookie.
     *
     * On success, stores the authenticated user in the config and evaluates
     * access policies from the user's roles and direct policy assignments.
     * If $redirect is truthy and the user is not authenticated or is inactive,
     * redirects to the sign-in page with a URL-encoded ref parameter so the
     * user can be returned to the intended destination after login.
     *
     * @param bool|string $redirect When truthy, redirect unauthenticated/inactive
     *                              users to the sign-in page instead of returning.
     * @return void
     */
    protected function authorize($redirect = false) {
        $state = $this->getState();
        $auth = new Authenticator($state->app);
        $auth->authorizeWithCookie();
        $authorization = $auth->authorized();
        if($authorization === false) {
            if($redirect !== false) {
                // Encode current URI as the ref parameter so we can return here after login.
                $ref = rawurlencode($state->url->get('uri'));
                $state->app->redirect($state->url->getBaseUrl() . 'sign-in/ref%3D' . $ref . '/');
                return;
            }
        }

        $authUser = $auth->user();
        if($authUser->status === User::STATUS_INACTIVE) {
            if($redirect !== false) {
                $ref = rawurlencode($state->url->get('uri'));
                return $state->app->redirect($state->url->getBaseUrl() . 'sign-in/ref%3D' . $ref . '/');
            }
        }
        else if(!empty($authUser->id)) {
            $conditionList = $this->getConditionList();
            $rootUser = $authUser->getRoot();
            // Sub-users inherit root's policy conditions in addition to their own.
            if(!empty($rootUser->id) && $rootUser->id !== $authUser->id) {
                $conditionList = $this->getLimitedConditionList($conditionList, $rootUser);
            }
            $conditionList = $this->getLimitedConditionList($conditionList, $authUser);
            $state->config->setProperty('user', $authUser);
            // Invalidate the cached snapshot so subsequent getState() calls
            // reflect the newly authenticated user.
            State::invalidate();
        }
    }

    /**
     * Apply a user's role and direct access policies to the given condition list.
     *
     * Resets the RuleManager, sets the full condition list, then feeds in each
     * access policy body from the user's roles and direct policies. Returns the
     * subset of conditions the user is allowed to perform.
     *
     * @param array $conditionList All possible permission conditions in the app.
     * @param User  $user          The user whose policies should be applied.
     * @return array Subset of $conditionList that $user is permitted to access.
     */
    protected function getLimitedConditionList($conditionList, $user) {
        RuleManager::reset();
        RuleManager::setConditionList($conditionList);
        if(!empty($user->roles)) {
            foreach($user->roles as $role) {
                foreach($role->accessPolicies as $accessPolicy) {
                    RuleManager::setRulePolicy(Strings::fromHtmlEntities($accessPolicy->value));
                }
            }
        }
        if(!empty($user->accessPolicies)) {
            foreach($user->accessPolicies as $accessPolicy) {
                RuleManager::setRulePolicy(Strings::fromHtmlEntities($accessPolicy->value));
            }
        }
        return RuleManager::getAllowedConditions();
    }

    /**
     * Collect all permission condition strings from every registered controller.
     *
     * Iterates the app's controller class list and calls the static __conditions()
     * method on each class that defines it. The merged, deduplicated, sorted list
     * is used as the baseline when evaluating access policies.
     *
     * @return array Sorted array of unique condition name strings.
     */
    protected function getConditionList() {
        $state = $this->getState();
        $conditions = [];
        $classes = $state->app->getControllerClasses();
        foreach($classes as $class) {
            $methods = get_class_methods($class);
            if(empty($methods)) {
                continue;
            }
            if(!in_array('__conditions', $methods)) {
                continue;
            }
            $conditions = array_unique(array_merge($conditions, $class::__conditions()));
        }
        sort($conditions);
        return $conditions;
    }

    /**
     * Return the root (parent) user ID for the given user.
     *
     * If the user has a parentUser relationship loaded, returns the parent's ID;
     * otherwise returns the user's own ID (they are already the root).
     *
     * @param User $user The user to resolve.
     * @return int Root user ID.
     */
    protected function getRootUserId($user) {
        if(!empty($user->parentUser->id)) {
            return $user->parentUser->id;
        }
        return $user->id;
    }

    /**
     * Return the root (parent) user UUID for the given user.
     *
     * @param User $user The user to resolve.
     * @return string Root user UUID.
     */
    protected function getRootUserUuid($user) {
        if(!empty($user->parentUser->uuid)) {
            return $user->parentUser->uuid;
        }
        return $user->uuid;
    }

    /**
     * Authenticate and redirect to sign-in on failure.
     *
     * Convenience wrapper around authorize() with redirect enabled.
     *
     * @return void
     */
    protected function authorizeOrRedirect() {
        return $this->authorize(true);
    }

    /**
     * Check whether the current user is allowed to perform a named condition.
     *
     * @param string $name Condition name to check (e.g. 'admin.users.edit').
     * @return bool True if allowed, false otherwise.
     */
    protected function isAllowed($name) {
        return RuleManager::isAllowed($name);
    }

    /**
     * Check a named condition and redirect to 403 if not allowed.
     *
     * @param string $name Condition name to check.
     * @return void
     */
    protected function isAllowedOrRedirect($name) {
        $state = $this->getState();
        if(!$this->isAllowed($name)) {
            $state->app->redirect($state->url->getBaseUrl() . '403/');
        }
    }

    /**
     * Stream a file from the local filesystem with the given MIME type header.
     *
     * @param string $file     Absolute path to the file to serve.
     * @param string $mimetype Content-Type header value.
     * @return void
     */
    protected function showDocument($file, $mimetype) {
        header('Content-Type: ' . $mimetype);
        readfile($file);
    }

    /**
     * Stream a PNG file.
     *
     * @param string $file Absolute path to a PNG file.
     * @return void
     */
    protected function showPng($file) {
        return $this->showDocument($file, 'image/png');
    }

    /**
     * Stream a PDF file.
     *
     * @param string $file Absolute path to a PDF file.
     * @return void
     */
    protected function showPdf($file) {
        return $this->showDocument($file, 'application/pdf');
    }

    /**
     * Stream a CSV file.
     *
     * @param string $file Absolute path to a CSV file.
     * @return void
     */
    protected function showCsv($file) {
        return $this->showDocument($file, 'text/csv');
    }

    /**
     * Render a JSON response using the JSONView.
     *
     * @param mixed $json Data object or array to serialise and return.
     * @return mixed The view result (stored in the controller's 'view' slot).
     */
    protected function showJson($json) {
        $view = new JSONView($this->get('app'));
        $view->setContentFromObject($json);
        return $this->set('view', $view);
    }

    /**
     * Flush a JSON response to the client and allow background processing to continue.
     *
     * Sends the JSON payload with a Connection: close header and exact Content-Length
     * so the HTTP client receives and closes the connection, then background PHP
     * execution can continue after this call returns.
     *
     * @param mixed $json Data object or array to serialise and flush.
     * @return void
     */
    protected function showJsonAndKeepAlive($json) {
        // Disable abort detection so PHP keeps running after the client disconnects.
        ignore_user_abort(true);
        ob_start();
        ob_end_clean();

        $content = str_replace('\\/', '/', json_encode($json, JSON_PRETTY_PRINT));

        // Headers that tell the client to close the connection after receiving the body.
        header('Connection: close');
        header("Pragma: public");
        header("Expires: 0");
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        header("Cache-Control: private", false);
        header("Content-Type: text/json");
        header("Content-Length: " . strlen($content));

        print $content;

        // Flush all buffers so the response is actually sent to the client now.
        ob_flush();
        flush();

        while(ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    /**
     * List files in a directory whose names contain all of the given substrings.
     *
     * @param string   $dir     Absolute directory path to scan.
     * @param string[] $matches List of substrings that every matched filename must contain.
     * @return string[] File names (not full paths) that satisfy all match criteria.
     */
    protected function listDirWithMatches($dir, $matches) {
        $handle = opendir($dir);
        $files = [];
        while(($fileName = readdir($handle)) !== false) {
            $ok = true;
            foreach($matches as $match) {
                if(strpos($fileName, $match) === false) {
                    $ok = false;
                }
            }
            if($ok === true) {
                $files[] = $fileName;
            }
        }
        closedir($handle);
        return $files;
    }

    /**
     * Unlink a list of files from the filesystem.
     *
     * @param string[] $files Absolute file paths to delete.
     * @return void
     */
    protected function deleteFiles($files = []) {
        foreach($files as $file) {
            unlink($file);
        }
    }

    /**
     * Trim whitespace from all scalar properties of a data object.
     *
     * Object and array properties are left unchanged; only string/numeric
     * scalars are trimmed. Modifies the object in place and returns it.
     *
     * @param object $data Data object to trim.
     * @return object The same object with string properties trimmed.
     */
    protected function trimData($data) {
        foreach($data as $k => $v) {
            if(is_object($v)) {
                continue;
            }
            if(is_array($v)) {
                continue;
            }
            $data->$k = Strings::trim($v);
        }
        return $data;
    }

    /**
     * Throw an exception if any of the given key-value pairs is empty.
     *
     * @param array $inputs Associative array of field name → value to validate.
     * @return void
     * @throws \Exception When any input value is empty.
     */
    protected function checkForEmptyInputs($inputs) {
        foreach($inputs as $k => $v) {
            if(empty($v)) {
                throw new \Exception('The ' . $k . ' input is required and can\'t be empty');
            }
        }
    }

    /**
     * Parse an XLSX spreadsheet file into an array of row objects.
     *
     * The first row is treated as column headers. Each subsequent row becomes
     * a stdClass object whose property names come from the column map. The $columns
     * parameter maps canonical column names to arrays of accepted alternate header
     * strings; wildcard comparison is used for matching. Columns whose header starts
     * with '$' are treated as relation columns and can appear multiple times (values
     * are collected into arrays). Empty rows are skipped.
     *
     * @param string $file    Absolute path to the .xlsx file.
     * @param array  $columns Map of canonical column name → alternate header names.
     * @return object[] Array of row objects with matched property names.
     * @throws \Exception If the spreadsheet cannot be opened.
     */
    public function parseSpreadsheet($file, $columns = []) {
        $data = [];
        $headers = [];
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $spreadsheet = $reader->load($file);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();
        $len = count($rows);
        if(empty($rows)) {
            throw new \Exception('Unable to open spreadsheet.  Please try again later.');
        }
        for($row = 0; $row < $len; $row++) {

            /* Identify column headers */

            if($row === 0) {
                foreach($rows[$row] as $k => $v) {
                    $trimValue = Strings::trim($v);
                    $cellValue = strtolower($trimValue);
                    $match = null;
                    foreach($columns as $columnName => $alternateNames) {
                        if(!is_array($alternateNames)) {
                            $columnName = $alternateNames;
                            $alternateNames = [];
                        }
                        if(Strings::wildcardCompare(Strings::makeRegexCompatible($columnName), $cellValue)) {
                            $match = $columnName;
                            break;
                        }
                        foreach($alternateNames as $alternateName) {
                             if(Strings::wildcardCompare(Strings::makeRegexCompatible(strtolower($alternateName)), $cellValue)) {
                                $match = $columnName;
                                break;
                            }
                        }
                    }
                    if($match !== null) {
                        // '$'-prefixed column names are relation columns — use the cell value
                        // as the header (possibly with a colon-delimited sub-key preserved).
                        if(Strings::startsWith('$', $match)) {
                            if(($pos = strpos($cellValue, ':')) !== false && $pos + 1 !== strlen($cellValue)) {
                                $headers[] = strtolower(substr($trimValue, 0, $pos)) . substr($trimValue, $pos);
                                continue;
                            }
                            $headers[] = $cellValue;
                            continue;
                        }
                        $headers[] = $match;
                        continue;
                    }
                    $headers[] = null;
                }
            }

            /* Populate row data */

            else {
                for($row = 1; $row < $len; $row++) {
                    $rowData = (object)[];
                    foreach($rows[$row] as $k => $v) {
                        if($headers[$k] === null) {
                            continue;
                        }
                        // When a column appears more than once, convert value to array and append.
                        if(isset($rowData->{$headers[$k]})) {
                            if(!is_array($rowData->{$headers[$k]})) {
                                $rowData->{$headers[$k]} = [$rowData->{$headers[$k]}];
                            }
                            $rowData->{$headers[$k]}[] = Strings::trim($v);
                            continue;
                        }
                        $rowData->{$headers[$k]} = Strings::trim($v);
                    }
                    $allEmpty = true;
                    foreach($rowData as $k => $v) {
                        if(strlen($v) > 0) {
                            $allEmpty = false;
                        }
                    }
                    if(!$allEmpty) {
                        $data[] = $rowData;
                    }
                }
            }
        }
        return $data;
    }

    /**
     * Convert flat spreadsheet rows into structured import objects.
     *
     * Regular column values are set as direct properties. Columns whose names
     * start with '$' become nested relations; a dot after the object name
     * ('$Obj.prop') sets a named property on the sub-object, while bare
     * '$Obj' names collect plain relation values. Pipe-delimited cell values
     * are split into arrays. Each output object gets a '_row' property holding
     * the original 1-based row number (+ 2 for the header row).
     *
     * @param array $data Flat row data from parseSpreadsheet().
     * @return object[] Structured import objects ready for validation.
     */
    protected function mapImportObjects($data) {
        foreach($data as $row => $values) {
            $relations = [];
            $object = (object)[
                '_row' => $row + 2
            ];
            foreach($values as $columnName => $value) {
                if(!Strings::startsWith('$', $columnName)) {
                    $object->$columnName = $value;
                    continue;
                }
                $objectName = (($pos = strpos($columnName, '.')) !== false ? substr($columnName, 1, $pos - 1) : substr($columnName, 1));
                $propertyName = (($pos = strpos($columnName, '.')) !== false ? substr($columnName, $pos + 1) : null);
                $separatedValues = [];
                // Split pipe-delimited values into arrays.
                if(is_array($value) || strpos($value, '|') !== false) {
                    if(!is_array($value)) {
                        $value = [$value];
                    }
                    foreach($value as $v) {
                        if(strpos($v, '|') !== false) {
                            $v = explode('|', $v);
                        }
                        if(!is_array($v)) {
                            $v = [$v];
                        }
                        foreach($v as $v2) {
                            $separatedValues[] = Strings::trim($v2);
                        }
                    }
                }
                else {
                    $separatedValues = $value;
                }
                if(empty($propertyName)) {
                    if(!isset($relations[$objectName])) {
                        $relations[$objectName] = [];
                    }
                    if(empty($relations[$objectName])) {
                        $relations[$objectName] = $separatedValues;
                        continue;
                    }
                    $relations[$objectName] = array_merge(is_array($relations[$objectName]) ? $relations[$objectName] : [$relations[$objectName]], is_array($separatedValues) ? $separatedValues : [$separatedValues]);
                    continue;
                }
                if(!isset($relations[$objectName])) {
                    $relations[$objectName] = (object)[];
                }
                $relations[$objectName]->$propertyName = $separatedValues;
            }
            foreach($relations as $key => $value) {
                $object->$key = $value;
            }
            $objects[] = $object;
        }
        return $objects;
    }



    /**
     * Validate an object against a format spec, appending errors to a log array.
     *
     * On validation failure the offending field is logged but processing continues
     * (rather than throwing). Returns the validated object or false on format error.
     *
     * @param object $object The object to validate.
     * @param array  $format Column format specification array.
     * @param array  &$log   Log array to append error messages to.
     * @return object|false Validated object, or false if $format is invalid.
     */
    protected function validateObjectAndLog($object, $format, &$log) {
        return $this->validateObject($object, $format, $log, false);
    }

    /**
     * Validate an object against a format spec, throwing on the first error.
     *
     * @param object $object The object to validate.
     * @param array  $format Column format specification array.
     * @return object|false Validated object, or false if $format is invalid.
     * @throws \Exception On the first validation failure.
     */
    protected function validateObjectAndThrow($object, $format) {
        $log = [];
        return $this->validateObject($object, $format, $log, true);
    }

    /**
     * Core validation engine for import objects.
     *
     * Iterates the format spec and for each column:
     *   - Normalises phone numbers and strips bare country codes.
     *   - Checks required fields (scalar required=true or array required=[sibling cols]).
     *   - Recursively validates nested child objects via their class COLUMN_FORMAT.
     *   - Casts decimals/integers and enforces min/max bounds.
     *   - Validates string length (minlength/maxlength).
     *   - Validates email format + DNS MX, state codes, country codes, timezone IDs.
     *   - HTML-encodes string-type values before returning.
     *
     * @param object $object  The object to validate.
     * @param array  $format  Column format specification.
     * @param array  &$log    Log array for accumulated error messages.
     * @param bool   $throw   When true, throw on first error instead of logging.
     * @return object|false Validated object with normalised values, or false on spec error.
     */
    protected function validateObject($object, $format, &$log, $throw = false) {
        $validated = (object)[];
        if(empty($format) || !is_array($format)) {
            return false;
        }
        if(!empty($object->_row)) {
            $validated->_row = $object->_row;
        }
        foreach($format as $columnName => $columnFormat) {
            if(is_numeric($columnName)) {
                continue;
            }
            $logMessagePrefix = (!empty($object->_prefix) ? $object->_prefix . '.' : '') . (!empty($columnFormat['name']) ? $columnFormat['name'] : $columnName);
            if(isset($columnFormat['type']) && $columnFormat['type'] === 'phone_number' && !empty($object->$columnName)) {
                // Normalise the phone number and strip values that are only a country code.
                $value = Strings::normalizePhoneNumber(Strings::stripPhoneNumber($object->$columnName));
                $object->$columnName = preg_replace('/^\+[0-9]{1,3}$/i', '', $value);
            }
            if(!isset($object->$columnName) || $this->isEmpty($object->$columnName)) {
                if(isset($columnFormat['required']) && $columnFormat['required'] === true) {
                    $this->addStringToOutputLogOrThrow($log, $logMessagePrefix . ' is required', $object, $throw, true);
                }
                if(isset($columnFormat['class']) && class_exists($columnFormat['class'])) {
                    $validated->$columnName = [];
                }
                else if(in_array($columnName, array_keys((array)$object))) {
                    $validated->$columnName = null;
                }
                continue;
            }
            $validated->$columnName = $object->$columnName;
            if(isset($columnFormat['class']) && class_exists($columnFormat['class'])) {
                // Recursively validate nested child objects using the child class's COLUMN_FORMAT.
                if(!is_array($validated->$columnName)) {
                    $validated->$columnName = [$validated->$columnName];
                }
                $children = [];
                foreach($validated->$columnName as $child) {
                    if(!is_object($child)) {
                        continue;
                    }
                    if(!empty($object->_row)) {
                        $child->_row = $object->_row;
                    }
                    $child->_prefix = (!empty($columnFormat['name']) ? $columnFormat['name'] : $columnName);
                    $children[] = $this->validateObject($child, $columnFormat['class']::COLUMN_FORMAT, $log, $throw);
                }
                $validated->$columnName = $children;
                continue;
            }
            if(isset($columnFormat['type']) && $columnFormat['type'] === 'decimal') {
                $validated->$columnName = (float)preg_replace('/[^\.0-9]+/i', '', $validated->$columnName);
                if(isset($columnFormat['min']) && is_numeric($columnFormat['min']) && $validated->$columnName < $columnFormat['min']) {
                    $this->addStringToOutputLogOrThrow($log, $logMessagePrefix . ' is below min (min=' . $columnFormat['min'] . ')', $object, $throw, true);
                }
                if(isset($columnFormat['max']) && is_numeric($columnFormat['max']) && $validated->$columnName > $columnFormat['max']) {
                    $this->addStringToOutputLogOrThrow($log, $logMessagePrefix . ' is above max (max=' . $columnFormat['max'] . ')', $object, $throw, true);
                }

            }
            else if(isset($columnFormat['type']) && $columnFormat['type'] === 'integer') {
                $validated->$columnName = (int)preg_replace('/[^\.0-9]+/i', '', $validated->$columnName);
                if(isset($columnFormat['min']) && is_numeric($columnFormat['min']) && $validated->$columnName < $columnFormat['min']) {
                    $this->addStringToOutputLogOrThrow($log, $logMessagePrefix . ' is below min (min=' . $columnFormat['min'] . ')', $object, $throw, true);
                }
                if(isset($columnFormat['max']) && is_numeric($columnFormat['max']) && $validated->$columnName > $columnFormat['max']) {
                    $this->addStringToOutputLogOrThrow($log, $logMessagePrefix . ' is above max (max=' . $columnFormat['max'] . ')', $object, $throw, true);
                }
            }

            if(isset($columnFormat['minlength']) && $columnFormat['minlength'] > 0) {
                if(strlen($validated->$columnName) < $columnFormat['minlength']) {
                    $this->addStringToOutputLogOrThrow($log, $logMessagePrefix . ' is too short (min=' . $columnFormat['minlength'] . ')', $object, $throw, true);
                }
            }
            if(isset($columnFormat['maxlength']) && $columnFormat['maxlength'] > 0) {
                if(strlen($validated->$columnName) > $columnFormat['maxlength']) {
                    $this->addStringToOutputLogOrThrow($log, $logMessagePrefix . ' is too long (max=' . $columnFormat['maxlength'] . ')', $object, $throw, true);
                }
            }

            if(isset($columnFormat['type']) && $columnFormat['type'] === 'email_address') {
                // Validate email format with PHP's filter, then verify the domain has an MX record.
                $domain = substr($validated->$columnName, strpos($validated->$columnName, '@') + 1);
                if(!filter_var($validated->$columnName, FILTER_VALIDATE_EMAIL)) {
                    $this->addStringToOutputLogOrThrow($log, $logMessagePrefix . ' = "' . $validated->$columnName . '" appears invalid (malformed)', $object, $throw, false);
                }
                if(!checkdnsrr($domain, 'MX')) {
                    $this->addStringToOutputLogOrThrow($log, $logMessagePrefix . ' = "' . $validated->$columnName . '" domain appears invalid (DNS MX)', $object, $throw, false);
                }
            }
            else if(isset($columnFormat['type']) && $columnFormat['type'] === 'state_code') {
                $validated->$columnName = strtoupper(preg_replace('/[^[a-z]+/i', '', $validated->$columnName));
                if(!isset(static::STATES[strtoupper($validated->$columnName)])) {
                    $this->addStringToOutputLogOrThrow($log, $logMessagePrefix . ' = "' . $validated->$columnName . '" is invalid country 2-digit ISO 3166‑1 abbreviation', $object, $throw, true);
                }
            }
            else if(isset($columnFormat['type']) && $columnFormat['type'] === 'country_code') {
                $validated->$columnName = strtoupper(preg_replace('/[^[a-z]+/i', '', $validated->$columnName));
                if(!isset(Geography::COUNTRIES[$validated->$columnName])) {
                    $this->addStringToOutputLogOrThrow($log, $logMessagePrefix . ' = "' . $validated->$columnName . '" is invalid state 2-digit abbreviation', $object, $throw, true);
                }
            }
            else if(isset($columnFormat['type']) && $columnFormat['type'] === 'timezone_id') {
                // Must be an IANA identifier known to PHP's timezone database.
                try {
                    $zone = new \DateTimeZone((string)$validated->$columnName);
                    $validated->$columnName = $zone->getName();
                } catch(\Exception $e) {
                    $this->addStringToOutputLogOrThrow($log, $logMessagePrefix . ' = "' . $validated->$columnName . '" must be a valid timezone identifier', $object, $throw, true);
                }
            }

            // HTML-encode all string-like types to prevent stored XSS.
            if(isset($columnFormat['type']) && in_array($columnFormat['type'], ['string', 'email_address', 'phone_number', 'country_code', 'state_code', 'timezone_id'])) {
                $validated->$columnName = Strings::toHtmlEntities($validated->$columnName);
            }
        }
        // Second pass: enforce 'required as a group' constraints (at least one sibling must be present).
        $groups = [];
        foreach($format as $columnName => $columnFormat) {
            if(isset($columnFormat['required']) && is_array($columnFormat['required'])) {
            }
            if(!isset($validated->$columnName) || $this->isEmpty($validated->$columnName)) {
                if(isset($columnFormat['required']) && is_array($columnFormat['required'])) {
                    $exists = false;
                    $group = array_merge([$columnName], $columnFormat['required']);
                    foreach($groups as $g) {
                        $matches = 0;
                        foreach($group as $item1) {
                            foreach($g as $item2) {
                                if($item1 === $item2) {
                                    $matches++;
                                }
                            }
                        }
                        if($matches === count($group)) {
                            $exists = true;
                        }
                    }

                    if(!$exists) {
                        $groups[] = $group;
                    }
                }
            }
        }
        foreach($groups as $group) {
            $allEmpty = true;
            $columnNames = [];
            foreach($group as $item) {
                $columnNames[] = (!empty($object->_prefix) ? $object->_prefix . '.' : '') . (isset($format[$item]['name']) ? $format[$item]['name'] : $item);
                if(isset($validated->$item) && !$this->isEmpty($validated->$item)) {
                    $allEmpty = false;
                }
            }
            $columnName = $columnNames[0];
            unset($columnNames[0]);
            if($allEmpty) {
                $this->addStringToOutputLogOrThrow($log, $columnName . ' or one of the following columns is required: ' . implode(', ', $columnNames), $object, $throw, true);
            }
        }
        return $validated;
    }

    /**
     * Determine whether a value is semantically empty.
     *
     * Returns true for: empty arrays, empty objects, and zero-length strings.
     * Returns false for numeric 0, boolean false, and non-empty collections.
     *
     * @param mixed $var Value to test.
     * @return bool True if the value is considered empty.
     */
    protected function isEmpty($var) {
        $isObject = is_object($var);
        $isArray = is_array($var);
        $isEmpty = empty($var);
        return ($isArray && $isEmpty) || ($isObject && $isEmpty) || (!$isArray && !$isObject && strlen($var) === 0);
    }

    /**
     * Append a validation message to the log or throw, depending on the $throw flag.
     *
     * @param array   &$log   Log array to append to.
     * @param string  $string Error message text.
     * @param object  $object Source row object (for row number context).
     * @param bool    $throw  When true and $ignore is true, throws instead of logging.
     * @param bool    $ignore When true, the row is considered ignorable/skippable.
     * @return void
     * @throws \Exception When $ignore is true and $throw is true.
     */
    protected function addStringToOutputLogOrThrow(&$log, $string, $object = null, $throw = false, $ignore = false) {
        if($ignore && $throw) {
            throw new \Exception($string);
        }
        return $this->addStringToOutputLog($log, $string, $object, $ignore);
    }

    /**
     * Append a message string to the output log array.
     *
     * Prepends the row number prefix when the source object has a '_row' property.
     *
     * @param array  &$log    Log array to append to.
     * @param string $string  Message text.
     * @param object $object  Source object (optional, for row number context).
     * @param bool   $ignore  When true, prefixes message with 'Ignoring, '.
     * @param bool   $warning Unused reserved parameter.
     * @return void
     */
    protected function addStringToOutputLog(&$log, $string, $object = null, $ignore = false, $warning = true) {
        if(empty($string)) {
            return;
        }
        if($log === null || !is_array($log)) {
           $log = [];
        }
        $log = array_merge($log, [(!empty($object->_row) ? '[Row ' . $object->_row . '] ' : '') . ($ignore ? 'Ignoring, ' : ($ignore ? 'Warning, ' : '')) . $string]);
    }

    /**
     * Probe the HTTP response code returned by an external URL.
     *
     * Issues a HEAD-equivalent GET request with a browser User-Agent to check
     * whether the URL is reachable and what status code it returns.
     *
     * @param string $url Fully-qualified URL to probe.
     * @return int HTTP response code (e.g. 200, 404, 0 on connection failure).
     */
    protected function probeHttpResponseCode($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 6.0)');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        return $info['http_code'];
    }

    /**
     * Return the global application State singleton.
     *
     * @return State The current request state.
     */
    protected function getState() {
        $state = State::get();
        return $state;
    }

    /**
     * Determine the appropriate redirect URL to return the user to after a flow completes.
     *
     * Checks, in order: the 'ref' URL variable, the POST 'referer' field, and the
     * HTTP_REFERER server header (only accepted when it matches the app's base URL
     * to prevent open-redirect attacks).
     *
     * @param mixed $url Optional URL object; defaults to the state URL.
     * @return string|null The referer path (relative to base URL), or null if none found.
     */
    protected function getReferer($url = null) {
        if(empty($url)) {
            $state = $this->getState();
            $url = $state->url;
        }
        $baseUrl = $url->getBaseUrl();
        $referer = $url->getVariable('ref');
        $postReferer = filter_input(INPUT_POST, 'referer', FILTER_UNSAFE_RAW);
        $httpReferer = filter_input(INPUT_SERVER, 'HTTP_REFERER', FILTER_UNSAFE_RAW);
        if(empty($referer) && strlen($postReferer) > 0) {
            $referer = $postReferer;
        }
        if(empty($referer) && strlen($httpReferer) > 0) {
            // Only trust HTTP_REFERER when it originates from our own domain.
            if(strpos($httpReferer, $baseUrl) !== false) {
                $referer = substr($httpReferer, strlen($baseUrl));
            }
        }
        return $referer;
    }

    /**
     * Return the timezone descriptor for the application's configured default
     * timezone (tzdata_id / tzdata_abbr / utc_offset, built by State from
     * PHP's own timezone database).
     *
     * @param State|null $state Application state; fetched automatically if null.
     * @return object Timezone descriptor.
     */
    public function getSystemTimeZone($state = null) {
        if($state === null) {
            $state = $this->getState();
        }
        return $state->systemTimeZone;
    }

    /**
     * Return a two-element array of [system timezone, user timezone] descriptors.
     *
     * When the active user has a valid personal timezone preference, the second
     * element is their timezone; otherwise both elements are the system timezone.
     *
     * @param State|null $state Application state; fetched automatically if null.
     * @return object[] Two-element array: [system timezone, effective user timezone].
     */
    public function getTimeZone($state = null) {
        if($state === null) {
            $state = $this->getState();
        }
        // State already resolved the user's preference into defaultTimeZone.
        return [$state->systemTimeZone, $state->defaultTimeZone];
    }

    /**
     * Compute the UTC offset in hours for a given TimeZone at the current moment.
     *
     * Compares the UTC epoch timestamp of "now" expressed in the given timezone
     * against the UTC epoch timestamp of the same wall-clock time in UTC to derive
     * the offset.
     *
     * @param TimeZone   $timeZone The timezone to compute the offset for.
     * @param State|null $state    Unused; reserved for future use.
     * @return float Offset in hours (positive = ahead of UTC, negative = behind).
     */
    public function getTimeZoneOffsetHours($timeZone, $state = null) {
        $dateTime = new \DateTime();
        $timestamp1 = strtotime($dateTime->format('Y-m-d H:i:s'));
        $dateTime->setTimezone(new \DateTimeZone($timeZone->tzdata_id));
        $timestamp2 = strtotime($dateTime->format('Y-m-d H:i:s'));
        return ($timestamp2 - $timestamp1) / 3600;
    }

    /**
     * Fetch the raw body content from a URL via HTTP GET.
     *
     * @param string $url Fully-qualified URL to fetch.
     * @return string Response body.
     */
    protected function getUrlContents($url) {
        return (new CURL())->setMode(CURL::MODE_FORMDATA)->setUrl($url)->send()->getResponse();
    }

    /**
     * Parse a semicolon-delimited filter text string into a structured array.
     *
     * Each segment takes the form "column OPERATOR value" where OPERATOR is one
     * of: ':', '!=', '>=', '<=', '>', '<', '='. The result is a nested array:
     *   [ 'column' => [ 'operator_code' => ['value1', 'value2', ...] ] ]
     *
     * The operator codes are: eq, neq, gte, lte, gt, lt.
     *
     * @param string $text Raw filter text (URL-encoded; will be decoded internally).
     * @return array Structured filter array suitable for passing to filterRows().
     */
    protected function getArrayFromFilterText($text) {
        $array = [];
        if(empty($text)) {
            return $array;
        }
        $exploded = explode(';', rawurldecode($text));
        $symbols = [
            ':' => 'eq',
            '!=' => 'neq',
            '>=' => 'gte',
            '<=' => 'lte',
            '>' => 'gt',
            '<' => 'lt',
            '=' => 'eq'
        ];

        foreach($exploded as $string) {
            $symbol = null;
            $pos = null;
            $len = null;

            foreach($symbols as $key => $value) {
                $pos = strpos($string, $key);
                if($pos === false || $pos === 0) {
                    continue;
                }
                $len = strlen($key);
                $symbol = $value;
                break;
            }

            if(empty($symbol) || empty($pos) || empty($len)) {
                continue;
            }

            $k = substr($string, 0, $pos);
            $v = substr($string, $pos + $len);

            $key = is_string($k) ? Strings::trim($k) : $k;
            $value = is_string($v) ? Strings::trim($v) : $v;

            if(!isset($array[$key])) {
                $array[$key] = [];
            }

            if(!isset($array[$key][$symbol])) {
                $array[$key][$symbol] = [];
            }

            $array[$key][$symbol][] = $value;
        }

        return $array;
    }

    /**
     * Generate a random 8-character alphanumeric URI token.
     *
     * Characters are drawn from a set that avoids visually ambiguous glyphs
     * (0, 1, O, I, l, etc.) to improve readability in printed materials.
     *
     * @return string 8-character random URI token.
     */
    protected function generateUri() {
        $uri = '';
        $chars = 'abcdefhkmnrstwxzABCDEFGHJKMNPRSTWXYZ23456789';
        $len = strlen($chars);
        for($i = 0; $i < 8; $i++) {
            $uri .= $chars[rand(0, $len - 1)];
        }
        return $uri;
    }

    /**
     * Output raw file contents as a browser download with appropriate headers.
     *
     * Sets Cache-Control, Content-Type, Content-Disposition (attachment), and
     * Content-Length headers so the browser treats the response as a file download.
     *
     * @param string $filename Suggested download filename for the Content-Disposition header.
     * @param string $contents Raw file bytes to output.
     * @param string $mimetype MIME type string for the Content-Type header.
     * @return void
     */
    protected function outputFileContents($filename, $contents, $mimetype) {
        header("Pragma: public");
        header("Expires: 0");
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        header("Cache-Control: private", false);
        header("Content-Type: " . $mimetype);
        header("Content-Disposition: attachment; filename=\"" . $filename . "\";");
        header("Content-Transfer-Encoding: binary");
        header("Content-Length: " . strlen($contents));

        print $contents;
    }

}
