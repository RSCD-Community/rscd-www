<?php

namespace RSCD\Controller\Admin;

use RSCD\View\AdminView;
use RSCD\Model\Object\Tag;
use RSCD\Util\Dates;
use RSCD\Util\Strings;

/**
 * Admin controller for managing Tags.
 *
 * Tags are simple named labels that can be attached to most domain objects
 * (artworks, orders, tickets, etc.) for categorisation and filtering.  This
 * controller exposes create, edit, delete, and list JSON endpoints consumed by
 * the tag management UI.
 */
class Tags extends \RSCD\Controller\Admin\Common\ObjectController {

    /**
     * Returns the access-policy condition slugs required for tag management.
     *
     * @return string[]
     */
    public static function __conditions() {
        return [
            '_AdminConsole_Tag_List',
            '_AdminConsole_Tag_Create',
            '_AdminConsole_Tag_Edit',
            '_AdminConsole_Tag_Delete'
        ];
    }
    
    /**
     * Bootstraps the controller with an AdminView and enforces authentication.
     *
     * @return void
     */
    public function initialize() {
        $this->set('view', new AdminView($this->get('app')));
        $this->authorizeOrRedirect();
    }

    /**
     * Handles requests with no explicit action.  Currently a no-op (listing
     * redirects are commented out pending implementation).
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
     * Redirect back to the tag listing with a flash message.
     *
     * @param mixed  $state Application state.
     * @param string $key   'msg' for success or 'err' for failure.
     * @param string $text  The flash message text.
     * @return void
     */
    private function redirectToList($state, $key, $text) {
        return $state->app->redirect($state->url->getBaseUrl() . 'admin/tags/list/?' . http_build_query([$key => $text]));
    }

    /**
     * Renders the server-side tag listing page with inline create, rename,
     * and delete forms.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpGetList($state) {
        $this->isAllowedOrRedirect('_AdminConsole_Tag_List');
        $base = $state->url->getBaseUrl();
        $canCreate = $this->isAllowed('_AdminConsole_Tag_Create');
        $canDelete = $this->isAllowed('_AdminConsole_Tag_Delete');
        $actions = '';
        if($canCreate) {
            $actions = '<form class="inline-form" method="POST" action="' . htmlspecialchars($base, ENT_QUOTES) . 'admin/tags/create/">'
                     . '<input type="text" name="name" maxlength="512" placeholder="New tag name" required />'
                     . '<button type="submit">Create tag</button>'
                     . '</form>';
        }
        $this->renderListingPage($state, [
            'class' => Tag::class,
            'title' => 'Tags',
            'baseUrl' => 'admin/tags/list/',
            'defaultSort' => 'name',
            'actions' => $actions,
            'columns' => [
                ['label' => 'Name', 'sort' => 'name', 'render' => function($tag) use ($base, $canDelete) {
                    // Tag names are stored HTML-entity-encoded (see httpPostCreate).
                    $name = htmlspecialchars(Strings::fromHtmlEntities((string)$tag->name), ENT_QUOTES);
                    if(!$canDelete) {
                        return $name;
                    }
                    $uuid = htmlspecialchars((string)$tag->uuid, ENT_QUOTES);
                    return '<form class="inline-form" method="POST" action="' . htmlspecialchars($base, ENT_QUOTES) . 'admin/tags/edit/">'
                         . '<input type="hidden" name="uuid" value="' . $uuid . '" />'
                         . '<input type="text" name="name" maxlength="512" value="' . $name . '" required />'
                         . '<button type="submit">Rename</button>'
                         . '</form>';
                }],
                ['label' => 'Created', 'sort' => 'created_at', 'render' => function($tag) {
                    return Dates::display($tag->created_at ? $tag->created_at->getTimestamp() : 0, 'j M Y H:i');
                }],
                ['label' => '', 'sort' => null, 'render' => function($tag) use ($base, $canDelete) {
                    if(!$canDelete) {
                        return '';
                    }
                    $uuid = htmlspecialchars((string)$tag->uuid, ENT_QUOTES);
                    return '<form class="inline-form" method="POST" action="' . htmlspecialchars($base, ENT_QUOTES) . 'admin/tags/delete/">'
                         . '<input type="hidden" name="uuid" value="' . $uuid . '" />'
                         . '<button type="submit" class="btn-danger">Delete</button>'
                         . '</form>';
                }]
            ]
        ]);
    }
    
    /**
     * Form endpoint: creates a new tag, then redirects back to the listing.
     *
     * The tag name is trimmed and HTML-entity-encoded before uniqueness is
     * checked.
     *
     * @param mixed $state Application state object.
     * @return void Redirects.
     */
    protected function httpPostCreate($state) {
        try {
            if(!$this->isAllowed('_AdminConsole_Tag_Create')) {
                throw new \Exception('403 - Forbidden');
            }
            $post = (object)$this->getPostData([
                'name' => FILTER_UNSAFE_RAW
            ]);
            $name = Strings::toHtmlEntities(Strings::trim($post->name));
            if(empty($name)) {
                throw new \Exception('A tag name is required');
            }
            $tag = Tag::where('name', $name)->first();
            if(!empty($tag->id)) {
                throw new \Exception('That tag already exists');
            }
            $tag = Tag::create([
                'name' => $name
            ]);
            return $this->redirectToList($state, 'msg', 'Tag created.');
        }
        catch (\Exception $e) {
            return $this->redirectToList($state, 'err', $this->getError($e));
        }
    }
    
    /**
     * Form endpoint: renames an existing tag by UUID, then redirects back to
     * the listing.
     *
     * NOTE: The permission check intentionally uses `_AdminConsole_Tag_Delete`
     * rather than `_AdminConsole_Tag_Edit`.  This is an existing behaviour and
     * must not be changed.
     *
     * @param mixed $state Application state object.
     * @return void Redirects.
     */
    protected function httpPostEdit($state) {
        try {
            if(!$this->isAllowed('_AdminConsole_Tag_Delete')) {
                throw new \Exception('403 - Forbidden');
            }
            $post = (object)$this->getPostData([
                'uuid' => FILTER_UNSAFE_RAW,
                'name' => FILTER_UNSAFE_RAW
            ]);
            $tag = Tag::where('uuid', $post->uuid)->first();
            if(empty($tag->id)) {
                throw new \Exception('Tag not found: 404');
            }
            $name = Strings::toHtmlEntities(Strings::trim($post->name));
            if(empty($name)) {
                throw new \Exception('A tag name is required');
            }

            $tag->name = $name;
            $tag->save();

            return $this->redirectToList($state, 'msg', 'Tag renamed.');
        }
        catch (\Exception $e) {
            return $this->redirectToList($state, 'err', $this->getError($e));
        }
    }
    
    /**
     * Form endpoint: deletes a tag by UUID, then redirects back to the listing.
     *
     * The framework's Eloquent relationships handle cascading detaches from
     * pivot tables at the database level.
     *
     * @param mixed $state Application state object.
     * @return void Redirects.
     */
    protected function httpPostDelete($state) {
        try {
            if(!$this->isAllowed('_AdminConsole_Tag_Delete')) {
                throw new \Exception('403 - Forbidden');
            }
            $post = (object)$this->getPostData([
                'uuid' => FILTER_UNSAFE_RAW
            ]);
            $tag = Tag::where('uuid', $post->uuid)->first();
            if(empty($tag->id)) {
                throw new \Exception('Tag not found: 404');
            }
            $tag->delete();
            return $this->redirectToList($state, 'msg', 'Tag deleted.');
        }
        catch (\Exception $e) {
            return $this->redirectToList($state, 'err', $this->getError($e));
        }
    }
    
    /**
     * JSON endpoint: returns a paginated, sorted, filtered tag listing.
     *
     * @param mixed $state Application state object.
     * @return void Outputs JSON.
     */
    protected function httpPostList($state) {
        $response = $this->getBlankResponse();
        try {
            if(!$this->isAllowed('_AdminConsole_Tag_List')) {
                throw new \Exception('403 - Forbidden');
            }
            
            $params = $this->parsePostListingParams($state);

            $listing = static::getListing(Tag::class, $params['query'], $params['filters'], $params['offset'], $params['limit'], $params['sort'], $params['mode'], [], $state->defaultTimeZone->tzdata_id);
            $listing['total_pages'] = $listing['total_models'] > 0 ? ceil($listing['total_models'] / $params['limit']) : 1;
            
            $response->listing = $listing;
        } 
        catch (\Exception $e) {
            $response->errors[] = $this->getError($e);
        }
        return $this->showJson($response);
    }
    
}