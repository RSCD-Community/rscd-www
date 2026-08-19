<?php

namespace RSCD\Controller\Admin;

use RSCD\View\AdminView;
use RSCD\Model\Object\JsonPolicy;
use RSCD\Model\Object\Role;
use RSCD\Util\Dates;
use RSCD\Util\Strings;

/**
 * Admin controller for managing user Roles.
 *
 * Roles are named permission bundles that aggregate JSON access policies.
 * Assigning a role to a user determines which admin console features they
 * can access. The role table itself only carries a name; all rule content
 * lives in the attached JsonPolicy rows (role_json_policy pivot).
 *
 * All pages are server-rendered plain-HTML forms; POST handlers redirect
 * back with msg=/err= flash variables.
 */
class Roles extends \RSCD\Controller\Admin\Common\ObjectController {

    /**
     * Returns the condition slugs required for role management.
     *
     * @return string[]
     */
    public static function __conditions() {
        return [
            '_AdminConsole_Role_List',
            '_AdminConsole_Role_View',
            '_AdminConsole_Role_Create',
            '_AdminConsole_Role_Edit',
            '_AdminConsole_Role_Delete'
        ];
    }

    /**
     * Bootstraps the controller and requires authentication.
     *
     * @return void
     */
    public function initialize() {
        $this->set('view', new AdminView($this->get('app')));
        $this->authorizeOrRedirect();
    }

    /**
     * Redirects the default request to the roles listing page.
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
     * Human-readable label for a JsonPolicy: the "name" field of its JSON
     * document, falling back to the UUID.
     *
     * @param JsonPolicy $policy
     * @return string
     */
    private static function policyLabel($policy) {
        $document = json_decode((string)$policy->value);
        return !empty($document->name) ? (string)$document->name : (string)$policy->uuid;
    }

    /**
     * Renders the roles listing HTML page.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpGetList($state) {
        $this->isAllowedOrRedirect('_AdminConsole_Role_List');
        $base = $state->url->getBaseUrl();
        $actions = '';
        if($this->isAllowed('_AdminConsole_Role_Create')) {
            $actions = '<a class="btn" href="' . htmlspecialchars($base, ENT_QUOTES) . 'admin/roles/create/">Create role</a>';
        }
        $canView = $this->isAllowed('_AdminConsole_Role_View');
        $this->renderListingPage($state, [
            'class' => Role::class,
            'title' => 'Roles',
            'baseUrl' => 'admin/roles/list/',
            'defaultSort' => 'name',
            'load' => ['accessPolicies'],
            'actions' => $actions,
            'columns' => [
                ['label' => 'Name', 'sort' => 'name', 'render' => function($role) use ($base, $canView) {
                    $name = htmlspecialchars(Strings::fromHtmlEntities((string)$role->name), ENT_QUOTES);
                    if(!$canView) {
                        return $name;
                    }
                    return '<a href="' . htmlspecialchars($base, ENT_QUOTES) . 'admin/roles/view/uuid=' . htmlspecialchars((string)$role->uuid, ENT_QUOTES) . '/">' . $name . '</a>';
                }],
                ['label' => 'Access policies', 'sort' => null, 'render' => function($role) {
                    $labels = [];
                    foreach($role->accessPolicies as $policy) {
                        $labels[] = htmlspecialchars(self::policyLabel($policy), ENT_QUOTES);
                    }
                    return implode(', ', $labels);
                }],
                ['label' => 'Created', 'sort' => 'created_at', 'render' => function($role) {
                    return Dates::display($role->created_at ? $role->created_at->getTimestamp() : 0, 'j M Y H:i');
                }],
                ['label' => 'Updated', 'sort' => 'updated_at', 'render' => function($role) {
                    return Dates::display($role->updated_at ? $role->updated_at->getTimestamp() : 0, 'j M Y H:i');
                }]
            ]
        ]);
    }

    /**
     * Builds the shared role create/edit form.
     *
     * @param mixed     $state Application state object.
     * @param Role|null $role  Existing role for the edit form, null for create.
     * @return string Safe HTML.
     */
    private function buildRoleForm($state, $role = null) {
        $base = htmlspecialchars($state->url->getBaseUrl(), ENT_QUOTES);
        $attached = [];
        if(!empty($role->id)) {
            foreach($role->accessPolicies as $policy) {
                $attached[] = $policy->uuid;
            }
        }
        $html = '';
        if(($msg = $state->url->getVariable('msg')) !== null) {
            $html .= '<div class="alert alert-success">' . htmlspecialchars((string)$msg, ENT_QUOTES) . '</div>';
        }
        if(($err = $state->url->getVariable('err')) !== null) {
            $html .= '<div class="alert alert-danger">' . htmlspecialchars((string)$err, ENT_QUOTES) . '</div>';
        }
        $action = $base . 'admin/roles/' . (!empty($role->id) ? 'edit' : 'create') . '/';
        $html .= '<form method="POST" action="' . $action . '">';
        if(!empty($role->id)) {
            $html .= '<input type="hidden" name="uuid" value="' . htmlspecialchars((string)$role->uuid, ENT_QUOTES) . '" />';
        }
        $html .= '<div class="form-group"><label for="role-name">Name</label>'
               . '<input class="form-control" type="text" id="role-name" name="name" maxlength="255" value="'
               . htmlspecialchars(Strings::fromHtmlEntities((string)($role->name ?? '')), ENT_QUOTES) . '" required /></div>';
        $html .= '<fieldset class="form-group"><legend>Access policies</legend>';
        foreach(JsonPolicy::orderBy('id', 'asc')->get() as $policy) {
            $uuid = htmlspecialchars((string)$policy->uuid, ENT_QUOTES);
            $checked = in_array($policy->uuid, $attached, true) ? ' checked' : '';
            $html .= '<label class="checkbox-label"><input type="checkbox" name="access_policies[]" value="' . $uuid . '"' . $checked . ' /> '
                   . htmlspecialchars(self::policyLabel($policy), ENT_QUOTES) . '</label>';
        }
        $html .= '</fieldset>';
        $html .= '<div class="form-actions"><button class="btn btn-primary" type="submit">' . (!empty($role->id) ? 'Save changes' : 'Create role') . '</button>'
               . ' <a href="' . $base . 'admin/roles/list/">Back to roles</a></div>';
        $html .= '</form>';
        if(!empty($role->id) && $this->isAllowed('_AdminConsole_Role_Delete')) {
            $html .= '<form class="danger-zone" method="POST" action="' . $base . 'admin/roles/delete/">'
                   . '<input type="hidden" name="uuid" value="' . htmlspecialchars((string)$role->uuid, ENT_QUOTES) . '" />'
                   . '<button class="btn-danger" type="submit">Delete this role</button>'
                   . '</form>';
        }
        return $html;
    }

    /**
     * Renders the single role view/edit page.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpGetView($state) {
        $this->isAllowedOrRedirect('_AdminConsole_Role_View');
        $view = $this->get('view');
        $uuid = $state->url->getVariable('uuid');
        $role = Role::whereNotNull('uuid')->where('uuid', $uuid)
                ->with(['accessPolicies'])->first();

        if(empty($role->id)) {
            return $state->app->redirect($state->url->getBaseUrl() . 'admin/roles/list/');
        }

        $page = $view->getViewLayout('admin' . DIRECTORY_SEPARATOR . 'single-page.html');
        $page->populateHtmlFromFile();
        $page->injectHtml('page_title', htmlspecialchars(Strings::fromHtmlEntities((string)$role->name), ENT_QUOTES));
        $page->injectHtml('page_content', $this->buildRoleForm($state, $role));
        $view->setPage($page->get('html'), [], Strings::fromHtmlEntities((string)$role->name));
    }

    /**
     * Renders the create-role form page.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpGetCreate($state) {
        $this->isAllowedOrRedirect('_AdminConsole_Role_Create');
        $view = $this->get('view');
        $page = $view->getViewLayout('admin' . DIRECTORY_SEPARATOR . 'single-page.html');
        $page->populateHtmlFromFile();
        $page->injectHtml('page_title', 'Create a new role');
        $page->injectHtml('page_content', $this->buildRoleForm($state, null));
        $view->setPage($page->get('html'), [], 'Create a new role');
    }

    /**
     * Reads the plain-form role fields from POST.
     *
     * @return object {name, access_policies: [{uuid}, ...], uuid?}
     */
    private function getRoleObjectFromPost() {
        $object = (object)[
            'name' => Strings::trim((string)filter_input(INPUT_POST, 'name', FILTER_UNSAFE_RAW)),
            'access_policies' => []
        ];
        $uuid = filter_input(INPUT_POST, 'uuid', FILTER_UNSAFE_RAW);
        if(!empty($uuid)) {
            $object->uuid = $uuid;
        }
        $policies = filter_input(INPUT_POST, 'access_policies', FILTER_UNSAFE_RAW, FILTER_REQUIRE_ARRAY);
        if(is_array($policies)) {
            foreach($policies as $policyUuid) {
                if(is_string($policyUuid) && $policyUuid !== '') {
                    $object->access_policies[] = (object)['uuid' => $policyUuid];
                }
            }
        }
        return $object;
    }

    /**
     * Form endpoint: creates a new role, then redirects to its edit page.
     *
     * @param mixed $state Application state object.
     * @return void Redirects.
     */
    protected function httpPostCreate($state) {
        $base = $state->url->getBaseUrl();
        try {
            if(!$this->isAllowed('_AdminConsole_Role_Create')) {
                throw new \Exception('403 - Forbidden');
            }
            $object = $this->getRoleObjectFromPost();

            if(empty($object->name)) {
                throw new \Exception('The role name is required');
            }

            $object->name = Strings::toHtmlEntities($object->name);
            if(strlen($object->name) > 255) {
                throw new \Exception('The role name is too long');
            }

            $role = Role::where('name', $object->name)->first();
            if(!empty($role->id)) {
                throw new \Exception('A role with that name already exists');
            }

            $log = [];
            $role = $this->createOrUpdateFromObject($log, $object, null, false, true);

            if(empty($role->id)) {
                throw new \Exception('Unable to create role in database');
            }

            return $state->app->redirect($base . 'admin/roles/view/uuid=' . rawurlencode($role->uuid) . '/?' . http_build_query(['msg' => 'Role created.']));
        }
        catch (\Exception $e) {
            return $state->app->redirect($base . 'admin/roles/create/?' . http_build_query(['err' => $this->getError($e)]));
        }
    }

    /**
     * Form endpoint: updates an existing role, then redirects to its edit page.
     *
     * Delegates to createOrUpdateFromObject() which handles access policy
     * synchronisation.
     *
     * @param mixed $state Application state object.
     * @return void Redirects.
     */
    protected function httpPostEdit($state) {
        $base = $state->url->getBaseUrl();
        try {
            if(!$this->isAllowed('_AdminConsole_Role_Edit')) {
                throw new \Exception('403 - Forbidden');
            }
            $object = $this->getRoleObjectFromPost();

            if(empty($object->name)) {
                throw new \Exception('The role name is required');
            }
            if(empty($object->uuid)) {
                throw new \Exception('Role not found: 404');
            }

            $role = Role::where('uuid', $object->uuid)->first();
            if(empty($role->id)) {
                throw new \Exception('Role not found: 404');
            }

            $object->name = Strings::toHtmlEntities($object->name);
            if(strlen($object->name) > 255) {
                throw new \Exception('The role name is too long');
            }

            $log = [];
            $role = $this->createOrUpdateFromObject($log, $object, $role, true, true);
            if(empty($role->id)) {
                throw new \Exception('Unable to update role in database');
            }

            return $state->app->redirect($base . 'admin/roles/view/uuid=' . rawurlencode($role->uuid) . '/?' . http_build_query(['msg' => 'Role updated.']));
        }
        catch (\Exception $e) {
            $uuid = (string)filter_input(INPUT_POST, 'uuid', FILTER_UNSAFE_RAW);
            $target = !empty($uuid)
                ? $base . 'admin/roles/view/uuid=' . rawurlencode($uuid) . '/?' . http_build_query(['err' => $this->getError($e)])
                : $base . 'admin/roles/list/?' . http_build_query(['err' => $this->getError($e)]);
            return $state->app->redirect($target);
        }
    }

    /**
     * Form endpoint: deletes a role by UUID, then redirects to the listing.
     *
     * Detaches access policies and user assignments before deleting the
     * role row.
     *
     * @param mixed $state Application state object.
     * @return void Redirects.
     */
    protected function httpPostDelete($state) {
        $base = $state->url->getBaseUrl();
        try {
            if(!$this->isAllowed('_AdminConsole_Role_Delete')) {
                throw new \Exception('403 - Forbidden');
            }
            $uuid = filter_input(INPUT_POST, 'uuid', FILTER_UNSAFE_RAW);

            $role = Role::where('uuid', $uuid)->first();
            if(empty($role->id)) {
                throw new \Exception('Role not found: 404');
            }

            $role->accessPolicies()->detach();
            $role->users()->detach();
            $role->delete();
            return $state->app->redirect($base . 'admin/roles/list/?' . http_build_query(['msg' => 'Role deleted.']));
        }
        catch (\Exception $e) {
            return $state->app->redirect($base . 'admin/roles/list/?' . http_build_query(['err' => $this->getError($e)]));
        }
    }

    /**
     * Upserts a role and synchronises its attached JSON access policies.
     *
     * When $role is null a new record is created; otherwise the existing
     * record's columns are updated. Policies are synced by UUID: policies
     * absent from $object->access_policies are detached, present ones are
     * looked up and attached. Policies themselves are never created or
     * modified here — that is the AccessPolicies controller's job.
     *
     * @param array     &$log   Accumulates human-readable log messages.
     * @param object    $object Validated data object.
     * @param Role|null $role   Existing model to update, or null to create.
     * @param bool      $unlink Unused; kept for interface compatibility.
     * @param bool      $throw  Unused; reserved for interface compatibility.
     * @return Role
     */
    public function createOrUpdateFromObject(&$log, $object, $role = null, $unlink = false, $throw = false) {
        if(!empty($role->id)) {
            $columns = (new Role())->getColumns();
            foreach($columns as $column) {
                if(in_array($column, array_keys((array)$object))) {
                    $role->$column = $object->$column;
                }
            }
            $role->save();
            $this->addStringToOutputLog($log, 'Role "' . $object->name . '" updated', $object, false, false);
        }
        else {
            $role = Role::create([
                'name' => isset($object->name) ? $object->name : null
            ]);
            $this->addStringToOutputLog($log, 'Role "' . $object->name . '" created', $object, false, false);
        }
        if(empty($object->access_policies)) {
            $object->access_policies = [];
        }
        foreach($role->accessPolicies as $accessPolicy) {
            $match = false;
            foreach($object->access_policies as $obj) {
                if(empty($obj->uuid) || $obj->uuid !== $accessPolicy->uuid) {
                    continue;
                }
                $match = true;
            }
            if(!$match) {
                $role->accessPolicies()->detach($accessPolicy->id);
            }
        }
        foreach($object->access_policies as $obj) {
            if(empty($obj->uuid)) {
                continue;
            }
            $accessPolicy = JsonPolicy::where('uuid', $obj->uuid)->first();
            if(empty($accessPolicy->id)) {
                continue;
            }
            if(!$role->accessPolicies()->where('uuid', $obj->uuid)->exists()) {
                $role->accessPolicies()->attach($accessPolicy->id);
                $this->addStringToOutputLog($log, 'Access Policy "' . $obj->uuid . '" added to Role.Access Policies', $object, false, false);
            }
        }
        return $role;
    }

}
