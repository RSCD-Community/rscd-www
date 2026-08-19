<?php

namespace RSCD\Controller;

use RSCD\View\ShopView;

/**
 * About — what this project is, what it claims, and what happens if Jagex asks.
 *
 * A static page, deliberately. The argument for running this project in the
 * open already exists in NOTICE and in the history on the landing page, but
 * neither is where somebody looks when they want to know whether this is
 * legitimate — and "somebody" here includes a rights holder. This puts it one
 * click from every page, in plain words, with a named address to write to.
 *
 * The page states that the game's artwork and world data originate with Jagex
 * and that a single email takes any of it down. That is the project's position
 * and it is not decoration: if it is ever acted on, it has to be honoured.
 */
class About extends \RSCD\Controller\ObjectController {

    /**
     * Initialise with the public-facing view.
     */
    public function initialize() {
        $this->set('view', new ShopView($this->get('app')));
    }

    /**
     * Default action — render the about page.
     *
     * @param object $state Application state.
     */
    public function processDefaultAction($state) {
        $this->authorize();
        $state = $this->getState();

        $page = $state->view->getViewLayout('about' . DIRECTORY_SEPARATOR . 'index.html');
        $page->populateHtmlFromFile();
        $state->view->setPage($page->get('html'), [], 'About');
    }

}
