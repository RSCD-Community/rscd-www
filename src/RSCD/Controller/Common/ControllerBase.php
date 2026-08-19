<?php

namespace RSCD\Controller\Common;

use RSCD\Util\Strings;

/**
 * Abstract base for all RSCD controller classes.
 *
 * Provides the minimal scaffolding shared by every controller in the framework:
 * property bag (get/set), action dispatch (processAction → findActionToProcess),
 * request state assembly (getState), environment-aware tmp directory resolution
 * (getTmp), and a blank JSON response stub (getBlankResponse).
 *
 * Concrete controllers extend Controller (which extends this class) and
 * implement HTTP handler methods named httpGet*, httpPost*, etc.
 */
abstract class ControllerBase extends \RSCD\Controller\Common\InputHandler {

    protected $app;
    protected $action;
    protected $view;

    /**
     * Initialise the controller with an app instance and null action/view.
     *
     * @param mixed $app The application object (or null for testing).
     */
    public function __construct($app = null) {
        $this->set('app', $app);
        $this->set('action', null);
        $this->set('view', null);
    }

    /**
     * Entry point for the framework router; delegates to findActionToProcess().
     *
     * @return mixed Result of the resolved action handler.
     */
    public function processAction() {
        return $this->findActionToProcess();
    }

    /**
     * Resolve and invoke the action handler for the current request.
     *
     * Builds the method name from the HTTP method and action string, then calls
     * it if it exists; otherwise falls back to processDefaultAction().
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
     * Fallback action handler; subclasses may override to provide default behaviour.
     *
     * @param mixed $state Application state object.
     * @return mixed Result of app->stop().
     */
    protected function processDefaultAction($state) {
        return $state->app->stop('@notImplemented');
    }

    /**
     * Return a blank response stub with an empty errors array.
     *
     * @return object stdClass with property `errors` set to an empty array.
     */
    protected function getBlankResponse() {
        return (object)[
            'errors' => []
        ];
    }

    /**
     * Convert an exception to a plain error message string.
     *
     * @param \Exception $e The exception to convert.
     * @return string The exception message.
     */
    protected function getError(\Exception $e) {
        return $e->getMessage();
    }

    /**
     * Build the camelCase handler method name from the HTTP method and action.
     *
     * Example: GET request for action "some-action" → "httpGetSomeAction".
     *
     * @param object $request Request stub with `method` and `action` properties.
     * @return string Camelcase method name (e.g. "httpGetFoo").
     */
    protected function getFunctionName($request) {
        return 'http' . ucwords(strtolower($request->method)) . \RSCD\Util\Strings::alphanumeric(ucwords(strtolower($request->action), " \t\r\n\f\v_-"));
    }

    /**
     * Assemble the current application state object.
     *
     * Returns a stdClass containing the app, config, URL, view, and a request
     * sub-object with the current action and HTTP method.
     *
     * @return object State object with properties: request, app, config, url, view.
     */
    protected function getState() {
        $app = $this->getApp();
        $config = $app->get('config');
        $url = $config->getProperty('url');
        $view = $this->get('view');
        return (object)[
            'request' => (object)[
                'action' => $this->get('action'),
                'method' => $url->get('method')
            ],
            'app' => $app,
            'config' => $config,
            'url' => $url,
            'view' => $view
        ];
    }


    /**
     * Return the environment-aware temporary directory path.
     *
     * Priority: test → staging → live → sys_get_temp_dir().
     * The returned string always ends with DIRECTORY_SEPARATOR.
     *
     * @return string Absolute path to the tmp directory, including trailing separator.
     */
    protected function getTmp() {
        $app = $this->getApp();
        $config = $app->get('config');
        $paths = $config->getProperty('paths');
        if(__LIVE__ === false && __STAGING__ === false && !empty($paths->tmp->test)) {
            return $paths->tmp->test;
        }
        else if(__STAGING__ !== false && !empty($paths->tmp->staging)) {
            return $paths->tmp->staging;
        }
        else if(__LIVE__ !== false && !empty($paths->tmp->live)) {
            return $paths->tmp->live;
        }
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR;
    }


    /**
     * Return the application object stored on this controller.
     *
     * @return mixed The application object.
     */
    public function getApp() {
        return $this->app;
    }

    /**
     * Return the action string stored on this controller.
     *
     * @return string|null The current action string, or null if not set.
     */
    public function getAction() {
        return $this->action;
    }

    /**
     * Return the view object stored on this controller.
     *
     * @return mixed The current view object, or null if not set.
     */
    public function getView() {
        return $this->view;
    }

    /**
     * Return the value of a named property on this controller.
     *
     * @param string|null $property Property name.
     * @return mixed Property value, or null if the property does not exist.
     */
    public function get($property = null) {
        if(property_exists($this, $property)) {
            return $this->$property;
        }
        return null;
    }

    /**
     * Set the value of a named property on this controller.
     *
     * @param string|null $property Property name.
     * @param mixed       $value    Value to assign.
     * @return static $this for method chaining.
     */
    public function set($property = null, $value = null) {
        if(property_exists($this, $property)) {
            $this->$property = $value;
        }
        return $this;
    }

}
