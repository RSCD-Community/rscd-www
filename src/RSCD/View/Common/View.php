<?php

namespace RSCD\View\Common;

use \RSCD\Model\App;

/**
 * Abstract base class for all RSCD views.
 *
 * Provides a property bag (get/set), header management (addHeader/removeHeader),
 * and a two-phase output pipeline (outputHeaders → outputContent). Subclasses
 * override outputHeaders() or outputContent() to customise rendering behaviour
 * (e.g. setting a non-200 HTTP response code or encoding the body as JSON).
 */
abstract class View {
    // constants

    // properties

    protected $app;
    protected $headers;
    protected $content;

    // constructor / destructor

    /**
     * Initialise the view with the app instance and default empty headers/content.
     *
     * @param mixed $app The application object.
     */
    public function __construct($app) {
        $this->set('app', $app);
        $this->set('headers', []);
        $this->set('content', '');
    }

    // abstract public methods

    // abstract protected methods

    // public methods

    /**
     * Send headers and content to the HTTP client.
     *
     * @return static $this
     */
    public function output() {
        return $this->outputHeaders()->outputContent();
    }

    /**
     * Add or remove a header from the header map.
     *
     * If $value is non-empty, the header is added (or updated). If $value is
     * empty and the header already exists, it is removed.
     *
     * @param string|null $name  Header name (e.g. 'Content-Type').
     * @param string|null $value Header value; pass empty to remove an existing header.
     * @return static $this
     */
    public function addHeader($name = null, $value = null) {
        $headers = $this->get('headers');

        if(is_array($headers) && !empty($name)) {
            if(!empty($value)) {
                $headers[$name] = $value;
            } else if(isset($headers[$name])) {
                unset($headers[$name]);
            }
        }

        $this->set('headers', $headers);

        return $this;
    }

    /**
     * Remove a named header from the header map.
     *
     * A no-op if the header does not exist.
     *
     * @param string|null $name Header name to remove.
     * @return static $this
     */
    public function removeHeader($name = null) {
        $headers = $this->get('headers');

        if(empty($name) || !isset($headers[$name])) {
            return $this;
        }

        unset($headers[$name]);

        $this->set('headers', $headers);

        return $this;
    }

    /**
     * Return the value of a named property on this view.
     *
     * @param string|null $property Property name.
     * @return mixed Property value, or null if it does not exist.
     */
    public function get($property = null) {
        if(property_exists($this, $property)) {
            return $this->$property;
        }

        return null;
    }

    /**
     * Set the value of a named property on this view.
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

    // protected methods

    /**
     * Send all registered headers to the HTTP client.
     *
     * @return static $this
     */
    protected function outputHeaders() {
        $headers = $this->get('headers');

        if(empty($headers)) {
            return $this;
        }

        foreach($headers as $key => $value) {
            header($key . ': ' . $value);
        }

        return $this;
    }

    /**
     * Print the view's content string to the HTTP client.
     *
     * @return static $this
     */
    protected function outputContent() {
        $content = $this->get('content');

        if(empty($content)) {
            return $this;
        }

        print $content;

        return $this;
    }

    // static public methods

    // static protected methods
}
