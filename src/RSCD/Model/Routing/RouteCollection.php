<?php

namespace RSCD\Model\Routing;

/**
 * An ordered collection of Route objects.
 *
 * Routes are stored in the order they are added. The Router queries all
 * routes and selects the most specific match by scope-string length.
 * Collections can be merged together, and a collection can be populated
 * from a JSON array via createFromJson().
 */
class RouteCollection {
    // constants

    // properties

    protected $routes;

    // constructor / destructor

    /**
     * Initialise the collection, optionally pre-populating it with routes.
     *
     * @param array|null $routes Initial array of Route objects, or null for an empty collection.
     */
    public function __construct($routes = null) {
        $this->set('routes', ! empty($routes) ? $routes : []);
    }

    // public methods

    /**
     * Append a single Route to the collection.
     *
     * @param  Route|null $route The route to add.
     * @return $this
     */
    public function addRoute($route = null) {
        $routes = $this->get('routes');

        $routes[] = $route;

        $this->set('routes', $routes);

        return $this;
    }

    /**
     * Merge all routes from another RouteCollection into this one.
     *
     * @param  RouteCollection $routes The collection whose routes are to be merged in.
     * @return $this
     */
    public function mergeRoutes($routes) {
        $this->set('routes', array_merge($this->get('routes'), $routes->get('routes')));

        return $this;
    }

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
     * Create a RouteCollection by hydrating each element of a plain-object array.
     *
     * @param  array|null $routesObject Array of plain route objects (e.g. decoded from JSON).
     * @return RouteCollection
     */
    public static function createFromObject($routesObject = null) {
        $routes = [];

        foreach($routesObject as $routeObject) {
            $routes[] = Route::createFromObject($routeObject);
        }

        return new RouteCollection($routes);
    }

    /**
     * Create a RouteCollection from a JSON array string.
     *
     * @param  string|null $routesJson JSON-encoded array of route definitions.
     * @return RouteCollection
     */
    public static function createFromJson($routesJson = null) {
        return RouteCollection::createFromObject(json_decode($routesJson));
    }

    // static protected methods
}
