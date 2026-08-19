<?php

namespace RSCD\View;

use \RSCD\Model\App;
use \RSCD\View\Common\View;

/**
 * Minimal concrete view used by DefaultController.
 *
 * Sets the content to a "not implemented" sentinel string on construction,
 * which the DefaultController replaces with the action output before rendering.
 */
class GenericView extends View {
    // constants
    // properties
    // constructor / destructor

    /**
     * Initialise the view with a "@not implemented" sentinel content string.
     *
     * @param mixed $app The application object.
     */
    public function __construct($app) {
        parent::__construct($app);

        $this->set('content', '@not implemented');
    }

    // public methods
    // protected methods
    // static public methods
    // static protected methods
}
