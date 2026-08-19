<?php

namespace RSCD\View;

use \RSCD\Model\App;
use \RSCD\View\Common\View;

/**
 * View that renders an HTTP error response.
 *
 * Extends the base View to override outputHeaders() and emit the HTTP response
 * code stored in the `httpCode` property before sending any custom headers.
 * Defaults to 403 Forbidden on construction.
 */
class ErrorView extends View {
    // constants
    // properties

    protected $httpCode;

    // constructor / destructor

    /**
     * Initialise the error view with a default 403 Forbidden response.
     *
     * @param mixed $app The application object.
     */
    public function __construct($app) {
        parent::__construct($app);

        $this->set('httpCode', 403);
        $this->set('content', '403 Forbidden');
    }

    // public methods

    /**
     * Set the HTTP response code then delegate to the parent header output.
     *
     * @return static $this
     */
    protected function outputHeaders() {
        http_response_code($this->get('httpCode'));

        parent::outputHeaders();

        return $this;
    }

    // protected methods
    // static public methods
    // static protected methods
}
