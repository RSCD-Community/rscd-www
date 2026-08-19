<?php

namespace RSCD\Model\Routing;

/**
 * Default controller and action for a route.
 *
 * Holds the fully-qualified controller class name and the action method name
 * that the Router should invoke when a route is matched. Typically created
 * by Route::createFromObject() when hydrating a JSON route definition.
 */
class RouteDefaults {
    // constants

    // properties

    protected $controller;
    protected $action;

    // constructor / destructor

    /**
     * Initialise defaults from a plain object.
     *
     * @param object|null $defaults Object with optional controller and action properties.
     */
    public function __construct($defaults = null) {
        $this->set('controller', isset($defaults->controller) ? $defaults->controller : null);
        $this->set('action', isset($defaults->action) ? $defaults->action : null);
    }

    // public methods

    /**
     * Return a property value by name.
     *
     * @param  string|null $property Property name.
     * @return mixed The property value, or null if it does not exist.
     */
    public function get($property = null) {
        if(property_exists($this, $property)) {
            return $this->$property;
        }

        return null;
    }

    /**
     * Set a property value by name.
     *
     * @param  string|null $property Property name.
     * @param  mixed       $value    Value to assign.
     * @return $this
     */
    public function set($property = null, $value = null) {
        if(property_exists($this, $property)) {
            $this->$property = $value;
        }

        return $this;
    }

    // protected methods

    // static public methods

    /**
     * Create a RouteDefaults instance from a plain object.
     *
     * @param  object|null $defaultsObject Plain defaults object (e.g. decoded from JSON).
     * @return RouteDefaults
     */
    public static function createFromObject($defaultsObject = null) {
        return new RouteDefaults($defaultsObject);
    }

    /**
     * Create a RouteDefaults instance by decoding a JSON string.
     *
     * @param  string|null $defaultsJson JSON-encoded defaults definition.
     * @return RouteDefaults
     */
    public static function createFromJson($defaultsJson = null) {
        return RouteDefaults::createFromObject(json_decode($defaultsJson));
    }

    // static protected methods
}
