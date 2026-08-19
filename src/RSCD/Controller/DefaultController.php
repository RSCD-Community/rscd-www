<?php

namespace RSCD\Controller;

use \RSCD\Controller\Common\Controller;
use \RSCD\View\GenericView;

/**
 * Fallback controller used when no other controller is matched by the router.
 *
 * Serves as a catch-all that handles unrouted actions with a simple example
 * output pattern. In production use, the router should be configured so this
 * controller is never reached for real traffic.
 */
class DefaultController extends Controller {

    /**
     * Initialise the controller with a GenericView.
     *
     * @param mixed $app The application object.
     */
    public function __construct($app) {
        parent::__construct($app);

        $this->set('view', new GenericView($this->get('app')));
    }

    /**
     * Handle the incoming action, delegating to an example handler.
     *
     * Returns $this immediately when no action is set; otherwise dispatches
     * to handleExampleRandomAction() via the switch statement.
     *
     * @return static $this
     */
    public function processAction() {
        $action = $this->get('action');

       if(empty($action)) {
           return $this;
       }

        switch($action) {
            default:
                $this->handleExampleRandomAction();
        }

        return $this;
    }

    /**
     * Example stub handler that sets the view content to the action name + " output".
     *
     * @return void
     */
    protected function handleExampleRandomAction() {
        $this->get('view')->set('content', $this->get('action') . ' output');
    }
}
