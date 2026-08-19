<?php

namespace RSCD\Model\Routing;

use \RSCD\Model\Routing\Route;
use \RSCD\Model\Routing\RouteCollection;
use \RSCD\Util\Strings;

/**
 * HTTP router that resolves the current request URL to a matching Route.
 *
 * On construction the router loads all JSON route files from the configured
 * storage directory (recursively). getRoute() compares each route's scope
 * (wildcard pattern) and HTTP method against the current request, collects
 * all matching routes, and returns the most specific one (longest scope
 * string). Falls back to a 403 error controller when no route matches.
 */
class Router {
    // constants

    const DEFAULT_STORAGE_DIR = 'routes' . DIRECTORY_SEPARATOR;

    // properties

    protected $app;
    protected $storageDirectory;
    protected $collection;

    // constructor / destructor

    /**
     * Initialise the router with an application container reference.
     *
     * @param object $app The application container (must expose get('config')).
     */
    public function __construct($app) {
        $this->set('app', $app);
        $this->set('storageDirectory',
            (defined('__ROUTER_DIR__') ? __ROUTER_DIR__
                : __ROOTS__ . Router::DEFAULT_STORAGE_DIR));
        $this->set('collection', new RouteCollection());
    }

    // public methods

    /**
     * Resolve the current request to the most specific matching Route.
     *
     * Loads routes on first call if the collection is empty. Returns a
     * fallback 403 Errors controller route when nothing matches.
     *
     * @return Route The best-matching route for the current request.
     */
    public function getRoute() {
        if(empty($this->get('collection')->get('routes'))) {
           $this->registerRoutes();
        }

        $routes = $this->get('collection')->get('routes');
        $eligibleRoutes = [];

        foreach($routes as $route) {
            if(! Strings::wildcardCompare($route->get('method'), $this->get('app')->get('config')->getProperty('url')->get('method'))) {
                continue;
            }

            if(! Strings::wildcardCompare($route->get('scope'), $this->get('app')->get('config')->getProperty('url')->get('uri'))) {
                continue;
            }

            $eligibleRoutes[] = [$route, strlen($route->get('scope'))];
        }

        $eligibleRouteCount = count($eligibleRoutes);

        if($eligibleRouteCount >= 1) {
            $mostPreciseRoute = $eligibleRoutes[0];

            foreach($eligibleRoutes as $eligibleRoute) {
                if($eligibleRoute[1] > $mostPreciseRoute[1]) {
                    $mostPreciseRoute = $eligibleRoute;
                }
            }

            return $mostPreciseRoute[0];
        } else {
            return Route::createFromObject((object)[
                'scope' => '%',
                'method' => '%',
                'defaults' => (object)[
                    'controller' => '\\RSCD\\Controller\\Errors',
                    'action' => 'throwHttpError403'
                ]
            ]);
        }
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

    /**
     * Load all JSON route files from the storage directory and register them.
     *
     * @return void
     */
    protected function registerRoutes() {
        $definedRoutes = $this->getRoutesFromJsonDirectory($this->get('storageDirectory'));

        $this->get('collection')->mergeRoutes(RouteCollection::createFromObject($definedRoutes));
    }

    /**
     * Recursively scan a directory for JSON route files and return all Route objects found.
     *
     * @param  string|null $directory  Directory path to scan.
     * @param  bool        $recursive  When true, subdirectories are scanned recursively.
     * @return array Array of Route objects.
     */
    protected function getRoutesFromJsonDirectory($directory = null, $recursive = true) {
        $fileExtension = '.json';
        $routes = [];

        if(!file_exists($directory)) {
            return $routes;
        }

        if(! empty($directory) && ($handler = opendir($directory)) !== false) {
            while(($fileName = readdir($handler)) !== false) {
                if($fileName == '.' || $fileName == '..') {
                    continue;
                }

                if(is_dir($directory . DIRECTORY_SEPARATOR . $fileName) && $recursive === true) {
                    $routes = array_merge($routes,
                        $this->getRoutesFromJsonDirectory($directory
                                . DIRECTORY_SEPARATOR . $fileName, $recursive));
                } else if(strpos($fileName, $fileExtension) !== false) {
                    $jsonRoutes = json_decode(file_get_contents($directory
                        . DIRECTORY_SEPARATOR . $fileName));

                    if(! empty($jsonRoutes) && is_array($jsonRoutes)) {
                        $routes = array_merge($routes, $jsonRoutes);
                    }
                }
            }
            closedir($handler);
        }

        $routesLength = count($routes);

        for($i = 0; $i < $routesLength; $i++) {
            $routes[$i] = Route::createFromObject($routes[$i]);
        }

        return $routes;
    }

    // static public methods

    // static protected methods

    /**
     * Determine whether the given class is abstract using reflection.
     *
     * @param  string|null $class Fully-qualified class name.
     * @return bool True if the class is abstract; false otherwise.
     */
    protected static function isAbstractClass($class = null) {
        if(! empty($class) && class_exists('ReflectionClass')) {
            $class = new \ReflectionClass($class);
            return $class->isAbstract();
        }

        return false;
    }
}
