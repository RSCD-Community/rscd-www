<?php

namespace RSCD\Model\Routing;

use RSCD\Model\Routing\RouteDefaults;

/**
 * Represents a single URL route definition.
 *
 * A route has a scope (wildcard URL pattern), an HTTP method pattern, and a
 * RouteDefaults object that specifies which controller and action to invoke
 * when the route matches. Routes are typically created from JSON route files
 * via createFromJson() or createFromObject().
 */
class Route {
    // constants

    // properties

    protected $scope;
    protected $method;
    protected $defaults;

    // constructor / destructor

    /**
     * Initialise a Route from a plain object.
     *
     * @param object|null $route Object with optional scope, method, and defaults properties.
     */
    public function __construct($route = null) {
        $this->set('scope', isset($route->scope) ? $route->scope : null);
        $this->set('method', isset($route->method) ? $route->method : null);
        $this->set('defaults', isset($route->defaults) ? $route->defaults : new RouteDefaults());
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
     * Create a Route from a plain object, hydrating the defaults into a RouteDefaults instance.
     *
     * @param  object|null $routeObject Plain route object (e.g. decoded from JSON).
     * @return Route
     */
    public static function createFromObject($routeObject = null) {
        if(isset($routeObject->defaults)) {
            $routeObject->defaults = RouteDefaults::createFromObject($routeObject->defaults);
        }

        return new Route($routeObject);
    }

    /**
     * Create a Route by decoding a JSON string and delegating to createFromObject().
     *
     * @param  string|null $routeJson JSON-encoded route definition.
     * @return Route
     */
    public static function createFromJson($routeJson = null) {
        return Route::createFromObject(json_decode($routeJson));
    }

    // static protected methods
}
