<?php

namespace RSCD\Controller\Admin;

use RSCD\View\AdminView;
use RSCD\Model\Object\Contact;
use RSCD\Model\Object\User;
use RSCD\Util\Dates;
use RSCD\Util\Strings;

/**
 * Admin controller for read-only user lookups.
 *
 * Provides a JSON list endpoint for searching user accounts and a secondary
 * endpoint for listing the non-default contacts that belong to a specific user.
 * Write operations for users are handled by the Employees controller.
 */
class Users extends \RSCD\Controller\Admin\Common\ObjectController {

    /**
     * Returns the condition slugs required for user listing access.
     *
     * @return string[]
     */
    public static function __conditions() {
        return [
            '_AdminConsole_User_List',
            '_AdminConsole_User_View'
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
     * Default action: show the user listing page.
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
     * Renders the server-side user listing page.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpGetList($state) {
        $this->isAllowedOrRedirect('_AdminConsole_User_List');
        $statusNames = [
            User::STATUS_ACTIVE => 'Active',
            User::STATUS_INACTIVE => 'Inactive',
            User::STATUS_PENDING_CONFIRMATION => 'Pending confirmation'
        ];
        $this->renderListingPage($state, [
            'class' => User::class,
            'title' => 'Users',
            'baseUrl' => 'admin/users/list/',
            'defaultSort' => 'created_at',
            'columns' => [
                ['label' => 'Name', 'sort' => 'name', 'render' => function($user) {
                    return Strings::displayText((string)$user->name);
                }],
                ['label' => 'Email address', 'sort' => 'email_address', 'render' => function($user) {
                    return htmlspecialchars((string)$user->email_address, ENT_QUOTES);
                }],
                ['label' => 'Status', 'sort' => 'status', 'render' => function($user) use ($statusNames) {
                    return htmlspecialchars($statusNames[(int)$user->status] ?? ('Unknown (' . (int)$user->status . ')'), ENT_QUOTES);
                }],
                ['label' => 'Last sign-in', 'sort' => 'signed_in_last_at', 'render' => function($user) {
                    return Dates::display($user->signed_in_last_at ? strtotime($user->signed_in_last_at) : 0, 'j M Y H:i', 'Never');
                }],
                ['label' => 'Registered', 'sort' => 'created_at', 'render' => function($user) {
                    return Dates::display($user->created_at ? $user->created_at->getTimestamp() : 0, 'j M Y H:i');
                }]
            ]
        ]);
    }

    /**
     * JSON endpoint: returns a paginated user listing.
     *
     * Each returned user record has its primary contact eager-loaded.
     *
     * @param mixed $state Application state object.
     * @return void Outputs JSON.
     */
    protected function httpPostList($state) {
        $response = $this->getBlankResponse();
        try {
            if(!$this->isAllowed('_AdminConsole_User_List')) {
                throw new \Exception('403 - Forbidden');
            }
            
            $data = (object)$this->getPostData([
                'filters' => FILTER_UNSAFE_RAW,
                'query' => FILTER_SANITIZE_STRING,
                'page' => FILTER_SANITIZE_NUMBER_INT,
                'limit' => FILTER_SANITIZE_NUMBER_INT,
                'sort' => FILTER_SANITIZE_STRING,
                'mode' => FILTER_SANITIZE_STRING,
            ]);
            
            $filters = !empty($data->filters) ? json_decode($data->filters) : [];
            $query = !empty($data->query) ? $data->query : $state->url->getVariable('query');
            $page = !empty($data->sort) ? $data->page : $state->url->getVariable('page');
            $limit = !empty($data->limit) ? $data->limit : $state->url->getVariable('limit');
            $sort = !empty($data->sort) ? $data->sort : $state->url->getVariable('sort');
            $mode = !empty($data->mode) ? $data->mode : $state->url->getVariable('mode');
            
            if($limit > 100) {
                $limit = 100;
            }
            else if($limit < 1) {
                $limit = 1;
            }
            if(empty($sort)) {
                $sort = 'updated_at';
            }
            if(empty($mode)) {
                $mode = 'desc';
            }
            
            $listing = static::getListing(User::class, $query, $filters, ($page - 1) * $limit, $limit, $sort, $mode,  [], $state->defaultTimeZone->tzdata_id);
            if($listing['queried_models'] instanceof \Illuminate\Database\Eloquent\Collection) {
                $listing['queried_models']->load(['contact']);
            }
            $listing['total_pages'] = $listing['total_models'] > 0 ? ceil($listing['total_models'] / $limit) : 1;
            
            $response->listing = $listing;
        } 
        catch (\Exception $e) {
            $response->errors[] = $this->getError($e);
        }
        return $this->showJson($response);
    }
    
    /**
     * JSON endpoint: returns contacts belonging to a specific user.
     *
     * Only returns non-default contacts (is_default = 0) for the given user.
     * Used by modal selectors that need to show a user's saved address book
     * entries without including the primary billing contact.
     *
     * @param mixed $state Application state object.
     * @return void Outputs JSON.
     */
    protected function httpPostContactList($state) {
        $response = $this->getBlankResponse();
        try {
            if(!$this->isAllowed('_AdminConsole_User_List')) {
                throw new \Exception('403 - Forbidden');
            }
            
            $post = (object)$this->getPostData([
                'user_id' => FILTER_SANITIZE_NUMBER_INT,
                'query' => FILTER_SANITIZE_STRING,
                'filters' => FILTER_UNSAFE_RAW,
                'offset' => FILTER_SANITIZE_NUMBER_INT,
                'limit' => FILTER_SANITIZE_NUMBER_INT
            ]);
            $filters = !empty($post->filters) ? json_decode($post->filters) : [];
            $query = !empty($post->query) ? $post->query : $state->url->getVariable('query');
            $listing = static::getListing(Contact::class, $query, [
                [
                    'user_id', $post->user_id,
                ],
                [
                    'is_default', 0,
                ]
            ], $post->offset, $post->limit, 'updated_at', 'DESC',  [], $state->defaultTimeZone->tzdata_id);
            $response->contact_listing = $listing;
        } 
        catch (\Exception $e) {
            $response->errors[] = $this->getError($e);
        }
        return $this->showJson($response);
    }
    
}