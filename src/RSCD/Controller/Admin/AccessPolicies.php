<?php

namespace RSCD\Controller\Admin;

use RSCD\View\AdminView;
use RSCD\Model\Object\JsonPolicy;
use RSCD\Util\Dates;
use RSCD\Util\Strings;

/**
 * Admin controller for managing JSON access policies.
 *
 * Access policies define named rule sets with collections of ALLOW/DENY
 * conditions that are evaluated to determine whether a user is permitted to
 * perform a given action. The whole policy document — including its display
 * name — is stored as structured JSON in the json_policy.value column.
 * Policies attach to roles via role_json_policy (see the Roles controller)
 * or directly to a user via json_policy.user_id.
 */
class AccessPolicies extends \RSCD\Controller\Admin\Common\ObjectController {

    /**
     * Returns the access-policy condition slugs required to use this controller.
     *
     * These slugs are checked by the framework's authorization layer to decide
     * which tabs and actions the active user's roles allow.
     *
     * @return string[]
     */
    public static function __conditions() {
        return [
            '_AdminConsole_AccessPolicy_List',
            '_AdminConsole_AccessPolicy_View',
            '_AdminConsole_AccessPolicy_Create',
            '_AdminConsole_AccessPolicy_Edit',
            '_AdminConsole_AccessPolicy_Delete'
        ];
    }

    /**
     * Bootstraps the controller: injects an AdminView and requires the user to
     * be authenticated.  Unauthenticated visitors are redirected to the login page.
     *
     * @return void
     */
    public function initialize() {
        $this->set('view', new AdminView($this->get('app')));
        $this->authorizeOrRedirect();
    }

    /**
     * Redirects the default (no explicit action) GET request to the listing page.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    public function processDefaultAction($state) {
        $state->request->action = 'list';
        $state->request->method = 'GET';
        return $this->httpGetList($state);
    }

    /**
     * Renders the access-policy listing HTML page (server-rendered).
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpGetList($state) {
        $this->isAllowedOrRedirect('_AdminConsole_AccessPolicy_List');
        $base = $state->url->getBaseUrl();
        $actions = '';
        if($this->isAllowed('_AdminConsole_AccessPolicy_Create')) {
            $actions = '<a class="btn" href="' . htmlspecialchars($base, ENT_QUOTES) . 'admin/access-policies/create/">Create access policy</a>';
        }
        $canView = $this->isAllowed('_AdminConsole_AccessPolicy_View');
        $typeNames = [
            JsonPolicy::TYPE_RULE => 'Rule',
            JsonPolicy::TYPE_RESOURCE => 'Resource'
        ];
        $this->renderListingPage($state, [
            'class' => JsonPolicy::class,
            'title' => 'Access Policies',
            'baseUrl' => 'admin/access-policies/list/',
            'defaultSort' => 'updated_at',
            'actions' => $actions,
            'columns' => [
                ['label' => 'Name', 'sort' => null, 'render' => function($policy) use ($base, $canView) {
                    $name = htmlspecialchars(static::getPolicyName($policy), ENT_QUOTES);
                    if(!$canView) {
                        return $name;
                    }
                    return '<a href="' . htmlspecialchars($base, ENT_QUOTES) . 'admin/access-policies/view/uuid=' . htmlspecialchars((string)$policy->uuid, ENT_QUOTES) . '/">' . $name . '</a>';
                }],
                ['label' => 'Type', 'sort' => 'type', 'render' => function($policy) use ($typeNames) {
                    return htmlspecialchars($typeNames[(int)$policy->type] ?? (string)$policy->type, ENT_QUOTES);
                }],
                ['label' => 'Created', 'sort' => 'created_at', 'render' => function($policy) {
                    return Dates::display($policy->created_at ? $policy->created_at->getTimestamp() : 0, 'j M Y H:i');
                }],
                ['label' => 'Updated', 'sort' => 'updated_at', 'render' => function($policy) {
                    return Dates::display($policy->updated_at ? $policy->updated_at->getTimestamp() : 0, 'j M Y H:i');
                }]
            ]
        ]);
    }

    /**
     * Builds the shared access-policy create/edit form.
     *
     * The policy document is edited as raw JSON in a textarea; the document's
     * name field is exposed as its own input for convenience.
     *
     * @param mixed           $state        Application state object.
     * @param JsonPolicy|null $accessPolicy Existing policy for the edit form, null for create.
     * @return string Safe HTML.
     */
    private function buildPolicyForm($state, $accessPolicy = null) {
        $base = htmlspecialchars($state->url->getBaseUrl(), ENT_QUOTES);
        $html = '';
        if(($msg = $state->url->getVariable('msg')) !== null) {
            $html .= '<div class="alert alert-success">' . htmlspecialchars((string)$msg, ENT_QUOTES) . '</div>';
        }
        if(($err = $state->url->getVariable('err')) !== null) {
            $html .= '<div class="alert alert-danger">' . htmlspecialchars((string)$err, ENT_QUOTES) . '</div>';
        }
        $name = !empty($accessPolicy->id) ? static::getPolicyName($accessPolicy) : '';
        $value = !empty($accessPolicy->id) ? (string)$accessPolicy->value : "{\n    \"name\": \"\",\n    \"collections\": [\n        {\n            \"name\": \"Rule Collection #1\",\n            \"rules\": [\n                {\n                    \"action\": \"ALLOW\",\n                    \"conditions\": []\n                }\n            ]\n        }\n    ]\n}";
        $action = $base . 'admin/access-policies/' . (!empty($accessPolicy->id) ? 'edit' : 'create') . '/';
        $html .= '<form method="POST" action="' . $action . '">';
        if(!empty($accessPolicy->id)) {
            $html .= '<input type="hidden" name="uuid" value="' . htmlspecialchars((string)$accessPolicy->uuid, ENT_QUOTES) . '" />';
        }
        $html .= '<div class="form-group"><label for="policy-name">Name</label>'
               . '<input class="form-control" type="text" id="policy-name" name="name" maxlength="255" value="' . htmlspecialchars($name, ENT_QUOTES) . '" required /></div>';
        $html .= '<div class="form-group"><label for="policy-value">Policy document (JSON)</label>'
               . '<textarea class="form-control code-editor" id="policy-value" name="value" rows="24" spellcheck="false" required>'
               . htmlspecialchars($value, ENT_QUOTES) . '</textarea></div>';
        $html .= '<div class="form-actions"><button class="btn btn-primary" type="submit">' . (!empty($accessPolicy->id) ? 'Save changes' : 'Create access policy') . '</button>'
               . ' <a href="' . $base . 'admin/access-policies/list/">Back to access policies</a></div>';
        $html .= '</form>';
        if(!empty($accessPolicy->id) && $this->isAllowed('_AdminConsole_AccessPolicy_Delete')) {
            $html .= '<form class="danger-zone" method="POST" action="' . $base . 'admin/access-policies/delete/">'
                   . '<input type="hidden" name="uuid" value="' . htmlspecialchars((string)$accessPolicy->uuid, ENT_QUOTES) . '" />'
                   . '<button class="btn-danger" type="submit">Delete this access policy</button>'
                   . '</form>';
        }
        return $html;
    }

    /**
     * Renders the single access-policy view/edit page.
     *
     * Looks up the policy by UUID from the URL.  Redirects to the listing if the
     * UUID is missing or the record does not exist.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpGetView($state) {
        $this->isAllowedOrRedirect('_AdminConsole_AccessPolicy_View');
        $view = $this->get('view');
        $uuid = $state->url->getVariable('uuid');
        $accessPolicy = JsonPolicy::whereNotNull('uuid')->where('uuid', $uuid)->first();

        if(empty($accessPolicy->id)) {
            return $state->app->redirect($state->url->getBaseUrl() . 'admin/access-policies/list/');
        }

        $page = $view->getViewLayout('admin' . DIRECTORY_SEPARATOR . 'single-page.html');
        $page->populateHtmlFromFile();
        $page->injectHtml('page_title', htmlspecialchars(static::getPolicyName($accessPolicy), ENT_QUOTES));
        $page->injectHtml('page_content', $this->buildPolicyForm($state, $accessPolicy));
        $view->setPage($page->get('html'), [], static::getPolicyName($accessPolicy));
    }

    /**
     * Renders the create-access-policy form page.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpGetCreate($state) {
        $this->isAllowedOrRedirect('_AdminConsole_AccessPolicy_Create');
        $view = $this->get('view');
        $page = $view->getViewLayout('admin' . DIRECTORY_SEPARATOR . 'single-page.html');
        $page->populateHtmlFromFile();
        $page->injectHtml('page_title', 'Create a new access policy');
        $page->injectHtml('page_content', $this->buildPolicyForm($state, null));
        $view->setPage($page->get('html'), [], 'Create a new access policy');
    }

    /**
     * JSON endpoint: returns a paginated, filtered, sorted list of access policies.
     *
     * Accepts POST parameters: query (search string), filters (JSON array),
     * page, limit (capped at 100), sort column, and sort mode (asc/desc).
     * Calculates and appends `total_pages` to the listing result.
     *
     * @param mixed $state Application state object.
     * @return void Outputs JSON.
     */
    /**
     * Form endpoint: creates a new access policy, then redirects.
     *
     * Validates that `value` is valid JSON and contains at least one rule
     * collection with at least one rule and one condition, and that every
     * rule action is ALLOW or DENY.  Auto-names unnamed rule collections.
     *
     * @param mixed $state Application state object.
     * @return void Redirects.
     */
    protected function httpPostCreate($state) {
        $base = $state->url->getBaseUrl();
        try {
            if(!$this->isAllowed('_AdminConsole_AccessPolicy_Create')) {
                throw new \Exception('403 - Forbidden');
            }
            $post = (object)$this->getPostData([
                'name' => FILTER_UNSAFE_RAW,
                'value' => FILTER_UNSAFE_RAW
            ]);

            // validatePolicyDocumentAndThrow() fully validates and re-encodes the
            // document; it must be stored raw because json_policy.value is a JSON
            // column (entity-encoded text would be rejected by MySQL).
            $object = new \stdClass();
            $object->value = static::validatePolicyDocumentAndThrow(
                isset($post->value) ? $post->value : null,
                isset($post->name) ? Strings::trim($post->name) : null
            );

            $log = [];
            $accessPolicy = $this->createOrUpdateFromObject($log, $object, null, false, true);

            if(empty($accessPolicy->id)) {
                throw new \Exception('Unable to create access policy in database');
            }

            return $state->app->redirect($base . 'admin/access-policies/view/uuid=' . rawurlencode($accessPolicy->uuid) . '/?' . http_build_query(['msg' => 'Access policy created.']));
        }
        catch (\Exception $e) {
            return $state->app->redirect($base . 'admin/access-policies/create/?' . http_build_query(['err' => $this->getError($e)]));
        }
    }

    /**
     * Form endpoint: updates an existing access policy, then redirects back
     * to its view page.
     *
     * Applies the same document-validation rules as httpPostCreate().
     *
     * @param mixed $state Application state object.
     * @return void Redirects.
     */
    protected function httpPostEdit($state) {
        $base = $state->url->getBaseUrl();
        $uuid = null;
        try {
            if(!$this->isAllowed('_AdminConsole_AccessPolicy_Edit')) {
                throw new \Exception('403 - Forbidden');
            }
            $post = (object)$this->getPostData([
                'uuid' => FILTER_UNSAFE_RAW,
                'name' => FILTER_UNSAFE_RAW,
                'value' => FILTER_UNSAFE_RAW
            ]);

            if(empty($post->uuid)) {
                throw new \Exception('Access Policy.UUID is required');
            }
            $uuid = $post->uuid;

            $accessPolicy = JsonPolicy::where('uuid', $post->uuid)->first();
            if(empty($accessPolicy->id)) {
                throw new \Exception('Access Policy not found: 404');
            }

            // Stored raw for the same reason as in httpPostCreate().
            $object = new \stdClass();
            $object->value = static::validatePolicyDocumentAndThrow(
                isset($post->value) ? $post->value : null,
                isset($post->name) ? Strings::trim($post->name) : null
            );

            $log = [];
            $accessPolicy = $this->createOrUpdateFromObject($log, $object, $accessPolicy, true, true);
            if(empty($accessPolicy->id)) {
                throw new \Exception('Unable to update access policy in database');
            }

            return $state->app->redirect($base . 'admin/access-policies/view/uuid=' . rawurlencode($accessPolicy->uuid) . '/?' . http_build_query(['msg' => 'Access policy saved.']));
        }
        catch (\Exception $e) {
            if(!empty($uuid)) {
                return $state->app->redirect($base . 'admin/access-policies/view/uuid=' . rawurlencode($uuid) . '/?' . http_build_query(['err' => $this->getError($e)]));
            }
            return $state->app->redirect($base . 'admin/access-policies/list/?' . http_build_query(['err' => $this->getError($e)]));
        }
    }

    /**
     * Form endpoint: deletes an access policy by UUID, then redirects to the
     * listing.
     *
     * Detaches the policy from all roles before deleting the policy row.
     *
     * @param mixed $state Application state object.
     * @return void Redirects.
     */
    protected function httpPostDelete($state) {
        $base = $state->url->getBaseUrl();
        try {
            if(!$this->isAllowed('_AdminConsole_AccessPolicy_Delete')) {
                throw new \Exception('403 - Forbidden');
            }
            $post = (object)$this->getPostData([
                'uuid' => FILTER_UNSAFE_RAW
            ]);

            $accessPolicy = JsonPolicy::where('uuid', $post->uuid)->first();
            if(empty($accessPolicy->id)) {
                throw new \Exception('Access Policy not found: 404');
            }

            $accessPolicy->roles()->detach();
            $accessPolicy->delete();
            return $state->app->redirect($base . 'admin/access-policies/list/?' . http_build_query(['msg' => 'Access policy deleted.']));
        }
        catch (\Exception $e) {
            return $state->app->redirect($base . 'admin/access-policies/list/?' . http_build_query(['err' => $this->getError($e)]));
        }
    }

    /**
     * Upserts an access policy.
     *
     * When $accessPolicy is null a new record is inserted; otherwise the
     * existing record's columns are overwritten from $object.
     *
     * @param array           &$log         Accumulates human-readable log messages.
     * @param object          $object       Validated data object.
     * @param JsonPolicy|null $accessPolicy Existing model to update, or null to create.
     * @param bool            $unlink       Unused; kept for interface compatibility.
     * @param bool            $throw        Unused; reserved for interface compatibility.
     * @return JsonPolicy
     */
    public function createOrUpdateFromObject(&$log, $object, $accessPolicy = null, $unlink = false, $throw = false) {
        if(!empty($accessPolicy->id)) {
            $columns = (new JsonPolicy())->getColumns();
            foreach($columns as $column) {
                if(in_array($column, ['id', 'uuid', 'created_at', 'updated_at'])) {
                    continue;
                }
                if(in_array($column, array_keys((array)$object))) {
                    $accessPolicy->$column = $object->$column;
                }
            }
            $accessPolicy->save();
            $this->addStringToOutputLog($log, 'Access Policy "' . $accessPolicy->uuid . '" updated', $object, false, false);
        }
        else {
            $accessPolicy = JsonPolicy::create([
                'user_id' => isset($object->user_id) ? $object->user_id : null,
                'type' => isset($object->type) ? $object->type : JsonPolicy::TYPE_RULE,
                'value' => isset($object->value) ? $object->value : null
            ]);
            $this->addStringToOutputLog($log, 'Access Policy "' . $accessPolicy->uuid . '" created', $object, false, false);
        }
        return $accessPolicy;
    }

    /**
     * Validate a rule-policy JSON document and return it re-encoded.
     *
     * Requires at least one collection containing at least one rule with an
     * ALLOW/DENY action and at least one non-empty condition. Unnamed
     * collections are auto-named; $name (when given) is written into the
     * document's name field so the display name always travels with the
     * document itself.
     *
     * @param  string|null  $document  The raw JSON policy document.
     * @param  string|null  $name      Optional display name to set on the document.
     * @return string                  The validated, pretty-printed document.
     * @throws \Exception              When the document is malformed or empty of rules.
     */
    protected static function validatePolicyDocumentAndThrow($document, $name = null) {
        $json = json_decode((string)$document);

        if($json === null || !is_object($json)) {
            throw new \Exception('Access Policy.Value is malformed.  Please use proper JSON syntax.');
        }

        if(!empty($name)) {
            $json->name = $name;
        }
        if(empty($json->name)) {
            throw new \Exception('Access Policy.Name is required');
        }

        if(empty($json->collections)) {
            throw new \Exception('Access Policy.Value must contain at least one rule collection');
        }

        $rules = 0;
        $conditions = 0;
        foreach($json->collections as $i => $collection) {
            if(empty($collection->name)) {
                $collection->name = 'Rule Collection #' . ($i + 1);
            }
            if(empty($collection->rules)) {
                continue;
            }
            foreach($collection->rules as $rule) {
                if(empty($rule->action)) {
                    throw new \Exception('Access Policy.Value contains a rule without an action.  Rule action must be ALLOW or DENY.');
                }
                if(!in_array(strtoupper($rule->action), ['ALLOW', 'DENY'])) {
                    throw new \Exception('Access Policy.Value contains an invalid rule action.  Rule action must be ALLOW or DENY.');
                }
                if(empty($rule->conditions)) {
                    continue;
                }
                $rules++;
                foreach($rule->conditions as $condition) {
                    if(!empty($condition)) {
                        $conditions++;
                    }
                }
            }
        }

        if($rules === 0 || $conditions === 0) {
            throw new \Exception('Access Policy.Value must contain at least one rule and condition');
        }

        return json_encode($json, JSON_PRETTY_PRINT);
    }

    /**
     * Extract the display name from a policy's JSON document.
     *
     * @param  JsonPolicy  $accessPolicy  The policy to name.
     * @return string                     The document's name, or the UUID as fallback.
     */
    protected static function getPolicyName($accessPolicy) {
        $json = json_decode((string)$accessPolicy->value);
        return !empty($json->name) ? $json->name : (string)$accessPolicy->uuid;
    }

}
