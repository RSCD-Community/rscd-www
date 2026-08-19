<?php

namespace RSCD\Controller\Admin\Common;

/**
 * Base controller for admin pages that derive the requested action from the URL.
 *
 * Extends the shared Controller base with admin-specific URL parsing.  The
 * action segment is read from URI position [2] (zero-indexed), which matches
 * the admin route pattern: /admin/{module}/{action}/...
 *
 * All standard admin page controllers (ObjectController and its subclasses)
 * extend this class.
 */
class PageController extends \RSCD\Controller\Common\Controller {

    /**
     * Parses the request state and extracts the action from the admin URI.
     *
     * Overrides the parent getState() to populate state->request->action from
     * the third URI segment (index 2), e.g. "list", "view", "create".
     *
     * @return mixed The populated application state object.
     */
    protected function getState() {
        $state = parent::getState();
        // URI segments: [0]='' [1]='admin' [2]=action (e.g. 'list', 'view')
        $uri = explode('/', $state->url->get('uri'));
        $state->request->action = isset($uri[2]) ? $uri[2] : '';
        return $state;
    }

}
