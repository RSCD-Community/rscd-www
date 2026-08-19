<?php

namespace RSCD\Model;

use RSCD\Model\Mutator;
use RSCD\Model\Routing\Router;
use RSCD\Model\URL;
use RSCD\Util\Strings;
use Illuminate\Events\Dispatcher;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Framework base application class.
 *
 * Provides the core boot sequence for both HTTP (run()) and CLI (cron())
 * execution paths. Handles config loading, URL resolution, access file
 * validation, database connection management, and encoder/encrypter/digest
 * instantiation. Subclasses (e.g. App) extend this to add project-specific
 * setup steps such as AWS S3 or third-party API credential registration.
 */
class AppBase {
    protected $config;
    protected $connection;
    protected $encoder;
    protected $encrypter;
    protected $messageDigest;
    protected $cron;

    /**
     * Initialise the application container with null properties.
     *
     * @param string|null $cron Fully-qualified class name of the cron job to run, or null for HTTP mode.
     */
    public function __construct($cron = null) {
        $this->set('config', null);
        $this->set('connection', null);
        $this->set('encoder', null);
        $this->set('encrypter', null);
        $this->set('messageDigest', null);
        $this->set('cron', $cron);
    }

    /**
     * Boot and execute a CLI cron job.
     *
     * Reads config, runs basicSetup(), instantiates the cron class by its
     * fully-qualified name, then calls execute() passing the CLI argument vector.
     * Calls stop() on success or on any uncaught exception.
     *
     * @param  array $argv CLI argument vector.
     * @return void
     */
    public function cron($argv) {
        try {
            $this->readConfigFromFile();
            $this->basicSetup();
            $class = $this->get('cron');
            if(! class_exists($class)) {
                throw new \Exception('class does not exist: ' . $class);
            }
            $cron = new $class($this);
            $cron->execute($argv);
        } catch(\Exception $e) {
            $this->stop($e->getMessage());
        }
        $this->stop();
    }

    /**
     * Boot and handle an inbound HTTP request.
     *
     * Full boot sequence: config → setup (URL parsing, access file) → route
     * resolution → controller instantiation → processAction() → view output().
     *
     * @return void
     * @throws \Exception If routing, controller, or view contracts are not satisfied.
     */
    public function run() {
        try {
            $this->readConfigFromFile()->setup();
            $router = new Router($this);
            $route = $router->getRoute();
            if(is_array(($defaults = $route->get('defaults')))) {
                throw new \Exception('invalid route defaults');
            }
            $class = $defaults->get('controller');
            if(! class_exists($class)) {
                throw new \Exception('route controller does not exist: ' . $class);
            }

            $controller = new $class($this);

            if(! empty($controller)) {
                if(method_exists($controller, 'processAction')) {
                    $actionRef = $this->get('config')->getProperty('url')->get('actionRef');
                    $action = empty($actionRef) ? $defaults->get('action') : $actionRef;

                    $controller->set('action', $action);
                    $controller->processAction();
                } else {
                    throw new \Exception('controller missing method processAction(): '. get_class($controller));
                }

                if(method_exists($controller, 'get')) {
                    $view = $controller->get('view');

                    if(method_exists($view, 'output')) {
                        $view->output();
                    } else {
                        throw new \Exception('view missing method output(): '. get_class($view));
                    }
                } else {
                    throw new \Exception('controller missing method get( "view" ): '. get_class($controller));
                }
            } else {
                throw new \Exception('router failed to resolve valid controller: '. $this->get('config')->getProperty('url')->get('uri'));
            }
        } catch(\Exception $e) {
            $this->stop($e->getMessage());
        }
        $this->stop();
    }

    /**
     * Terminate the application, closing the database connection and exiting.
     *
     * @param  string|null $message      Optional message to output on exit.
     * @param  mixed|null  $loggingLevel Optional logging level (currently unused).
     * @return void
     */
    public function stop($message = null, $loggingLevel = null) {
        $this->closeDatabaseConnection();

        if(! empty($loggingLevel)) {
            //Logger::$loggingLevel( ! empty( $message ) ? $message : 'incident occurred, no message supplied' );
        }
        exit("$message");
    }

    /**
     * Issue an HTTP redirect and terminate.
     *
     * @param  string $url The URL to redirect to.
     * @return void
     */
    public function redirect($url) {
        header('Location: ' . $url);
        return $this->stop();
    }

    /**
     * Return all model class names found in the Composer class map.
     *
     * @return array Fully-qualified class names whose paths contain /Model/.
     */
    public function getModelClasses() {
        return array_keys(array_filter($this->getClassMap(), '\\RSCD\\Model\\AppBase::modelNamespaceFilter'));
    }

    /**
     * Return all view class names found in the Composer class map.
     *
     * @return array Fully-qualified class names whose paths contain /View/.
     */
    public function getViewClasses() {
        return array_keys(array_filter($this->getClassMap(), '\\RSCD\\Model\\AppBase::viewNamespaceFilter'));
    }

    /**
     * Return all controller class names found in the Composer class map.
     *
     * Falls back to reading the controller directory when the class map does
     * not have them, which is the normal state of a PSR-4 install: composer
     * only writes application classes into the map for `dump-autoload -o`, and
     * a plain `composer install` leaves it holding six unrelated entries.
     *
     * The fallback is not an optimisation, it is the difference between the
     * site working and not. This list is where the permission system gets the
     * set of conditions that exist at all (Controller::getConditionList), so
     * an empty answer here does not deny one thing — it silently denies
     * everything, to everybody, including the site owner, with no error
     * anywhere. That is a bad failure to hang on whether a deploy step was
     * remembered.
     *
     * @return array Fully-qualified class names whose paths contain /Controller/.
     */
    public function getControllerClasses() {
        $classes = array_keys(array_filter($this->getClassMap(), '\\RSCD\\Model\\AppBase::controllerNamespaceFilter'));

        return !empty($classes) ? $classes : $this->getControllerClassesFromDisk();
    }

    /**
     * Controller class names read from the source tree.
     *
     * PSR-4 makes the mapping mechanical: every .php file under the namespace
     * root is the class of the same name. Nothing is loaded here — the names
     * are handed back and the autoloader resolves the ones that get used.
     *
     * @return array Fully-qualified class names.
     */
    protected function getControllerClassesFromDisk() {
        $root = __ROOTS__ . 'src' . DIRECTORY_SEPARATOR . 'RSCD' . DIRECTORY_SEPARATOR . 'Controller';
        if(!is_dir($root)) {
            return [];
        }

        $classes = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,
            \FilesystemIterator::SKIP_DOTS));
        foreach($files as $file) {
            if(!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            // No leading separator: the class map's keys have none either, and
            // callers compare these names against it.
            $classes[] = 'RSCD\\Controller\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
        }

        sort($classes);
        return $classes;
    }

    /**
     * HTTP-request setup: parse URL and validate the access file, then run basicSetup().
     *
     * @return $this
     */
    protected function setup() {
        $this->getUrlInfo();
        $this->validateAccessFile();
        return $this->basicSetup();
    }

    /**
     * Minimal shared setup used by both cron() and run().
     *
     * Opens the database connection if configured, then instantiates the
     * encoder, encrypter, and message digest from config. Also bootstraps
     * the Mutator static helper.
     *
     * @return $this
     * @throws \Exception If any of the required config keys are absent.
     */
    protected function basicSetup() {
        if(! empty($this->get('config')->getProperty('database'))) {
            $this->openDatabaseConnection();
        }
        if(! empty(($encoder = $this->get('config')->getProperty('encoder')))) {
            $this->set('encoder', new $encoder->class(isset($encoder->params) ? $encoder->params : null));
        } else {
            throw new \Exception('no encoder class defined in config');
        }
        if(! empty(($encrypter = $this->get('config')->getProperty('encrypter')))) {
            $this->set('encrypter', new $encrypter->class(isset($encrypter->params) ? $encrypter->params : null));
        } else {
            throw new \Exception('no encrypter class defined in config');
        }
        if(! empty(($messageDigest = $this->get('config')->getProperty('messageDigest')))) {
            $this->set('messageDigest', new $messageDigest->class(isset($messageDigest->params) ? $messageDigest->params : null));
        } else {
            throw new \Exception('no message digest class defined in config');
        }
        Mutator::setup($this);
        return $this;
    }

    /**
     * Retrieve the Composer class map via the autoloader singleton.
     *
     * @return array|null Associative array of class => file path, or null if unavailable.
     */
    protected function getClassMap() {
        $classes = array_values(array_filter(get_declared_classes(), '\\RSCD\\Model\\AppBase::classloaderNamespaceFilter'));

        if(! empty($classes)) {
            $classloader =  $classes[0]::getLoader();
            return $classloader->getClassMap();
        }

        return null;
    }

    /**
     * Return a property value by name.
     *
     * @param  string|null $property Property name.
     * @return mixed The property value, or null if the property does not exist.
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

    /**
     * Read the application JSON config file and store it as a ConfigReader instance.
     *
     * @return $this
     */
    protected function readConfigFromFile() {
        $this->set('config', new ConfigReader(
                (defined('__CONFIG_FILE__') ? __CONFIG_FILE__
                : __ROOTS__ . ConfigReader::DEFAULT_FILE)));

        return $this;
    }

    /**
     * Parse the current request URL and store it in the config property bag.
     *
     * @return $this
     */
    protected function getUrlInfo() {
        $this->get('config')->setProperty('url', URL::getCurrentUrlWithRefs());

        return $this;
    }

    /**
     * Instantiate AccessReaderWriter and validate the live .htaccess file.
     *
     * @return $this
     */
    protected function validateAccessFile() {
        $accessReaderWriter = new AccessReaderWriter(
            (defined('__TEMPLATE_FILE__') ? __TEMPLATE_FILE__
                : __ROOTS__ . AccessReaderWriter::DEFAULT_TEMPLATE_FILE));

        $accessReaderWriter->validate();

        return $this;
    }

    /**
     * Open the Eloquent/Capsule MySQL database connection.
     *
     * @return $this
     * @throws \Exception If database config is empty or the connection attempt fails.
     */
    protected function openDatabaseConnection() {
        if(empty(($config = $this->get('config')->getProperty('database')))) {
            throw new \Exception('unable to establish database connection');
        }
        try {
            $capsule = new DB;
            $capsule->addConnection([
                'driver' => $config->driver,
                'host' => $config->host,
                'database' => $config->name,
                'username' => $config->user,
                'password' => $config->pass,
                'charset' => 'utf8',
                'collation' => 'utf8_unicode_ci',
                'prefix' => '',
                // See App::openDatabaseConnection() -- without a timeout an
                // unreachable database pins an Apache worker per request.
                'options' => [\PDO::ATTR_TIMEOUT => 5],
            ]);
            $capsule->setEventDispatcher(new Dispatcher(new Container));
            $capsule->setAsGlobal();
            $capsule->bootEloquent();
            $this->set('connection', $capsule);
        } catch(\Exception $e) {
             throw new \Exception('unable to establish database connection:' . $e->getMessage());
        }
        /*
        try
        {
            $string = $connectionConfig['pdoDsn'] . ':host=' . $connectionConfig['pdoHost'] . ';port=' . $connectionConfig['pdoPort'] . ';dbname=' . $connectionConfig['pdoDatabase'];
            $connection = new $connectionConfig['pdoClass']( $string , $connectionConfig['pdoUsername'] , $connectionConfig['pdoPassword'] );
            if ( ! empty( $connectionConfig['pdoOpts'] ) )
            {
                foreach ( $connectionConfig['pdoOpts'] as $key => $value )
                {
                    $connection->setAttribute( $key , $value );
                }
            }
            $this->set( 'connection' , $connection );
        }
        catch( \PDOException $e )
        {
            throw new \Exception( 'unable to establish database connection:' . $e->getMessage() );
        }
        */
        return $this;
    }

    /**
     * Close the database connection by nulling out the stored Capsule instance.
     *
     * @return $this
     */
    protected function closeDatabaseConnection() {
        $this->set('connection', null);

        return $this;
    }

    /**
     * Filter callback: returns true for Composer autoloader init classes.
     *
     * @param  string|null $class Fully-qualified class name to test.
     * @return bool
     */
    protected static function classloaderNamespaceFilter($class = null) {
        return Strings::wildcardCompare('%ComposerAutoloaderInit%', $class);
    }

    /**
     * Filter callback: returns true for classes in the Model namespace.
     *
     * @param  string|null $class Fully-qualified class name to test.
     * @return bool
     */
    protected static function modelNamespaceFilter($class = null) {
        return Strings::wildcardCompare('%/Model/%', $class);
    }

    /**
     * Filter callback: returns true for classes in the View namespace.
     *
     * @param  string|null $class Fully-qualified class name to test.
     * @return bool
     */
    protected static function viewNamespaceFilter($class = null) {
        return Strings::wildcardCompare('%/View/%', $class);
    }

    /**
     * Filter callback: returns true for classes in the Controller namespace.
     *
     * @param  string|null $class Fully-qualified class name to test.
     * @return bool
     */
    protected static function controllerNamespaceFilter($class = null) {
        return Strings::wildcardCompare('%/Controller/%', $class);
    }
}
