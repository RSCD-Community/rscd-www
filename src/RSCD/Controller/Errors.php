<?php

namespace RSCD\Controller;

use RSCD\View\ShopView;

/**
 * Renders HTTP error pages (403, 404, and 500).
 *
 * Loaded by the application framework when a route is not found (404) or when
 * access is denied (403).  Serves the corresponding static HTML layout from
 * the view directory (ui/html/403.html, ui/html/404.html).
 */
class Errors extends \RSCD\Controller\Common\Controller {

    /**
     * Initialise the controller with the public site view — an error page
     * should carry the same header and footer navigation as every other
     * public page, so a dead link is a detour rather than a dead end.
     */
    public function initialize() {
        $this->set('view', new ShopView($this->get('app')));
        $this->authorize();
    }

    /**
     * Default action — render the 404 Not Found page.
     *
     * Overrides the action/method on the request object so that the controller
     * routing logic dispatches to httpGet404().
     *
     * @param object $state Application state.
     */
    public function processDefaultAction($state) {
        $state->request->action = '404';
        $state->request->method = 'GET';
        return $this->httpGet404($state);
    }

    /**
     * Render the 403 Forbidden page.
     *
     * @param object $state Application state.
     */
    protected function httpGet403($state) {
        $home = $state->view->getViewLayout('403.html');
        $home->populateHtmlFromFile();
        $state->view->setPage($home->get('html'), [], 'Access denied');
    }

    /**
     * Render the 404 Not Found page.
     *
     * @param object $state Application state.
     */
    protected function httpGet404($state) {
        $home = $state->view->getViewLayout('404.html');
        $home->populateHtmlFromFile();
        $state->view->setPage($home->get('html'), [], 'Page not found');
    }

    /**
     * Render the 500 Internal Server Error page.
     *
     * Falls back to 404 layout if no dedicated 500.html template exists.
     *
     * @param object $state Application state.
     */
    protected function httpGet500($state) {
        $view = $state->view;
        try {
            $page = $view->getViewLayout('500.html');
            $page->populateHtmlFromFile();
            $view->setPage($page->get('html'), [], 'Something went wrong');
        } catch (\Exception $e) {
            $this->httpGet404($state);
        }
    }

}
