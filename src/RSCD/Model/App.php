<?php
namespace RSCD\Model;

use RSCD\Model\Notify;
use RSCD\Model\State;
use RSCD\Model\Routing\Router;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Events\Dispatcher;

/**
 * Application entry point for rscd-community.org.
 *
 * Extends the Roots framework App base class with the minimal boot the
 * community site needs: test-database switching, the Notify mailer, and
 * the State singleton. All hostname handling is config-driven — the site
 * serves exactly the domains listed in the `domains` config property.
 *
 * Two top-level entry modes are supported:
 *  - run()  — HTTP request handling (routes → controller → view)
 *  - cron() — CLI/background job execution
 */
class App extends \RSCD\Model\AppBase {

    /** Application name used in logging and config lookups. */
    const NAME = 'RSCD';

    /** Asset cache-buster query value on live requests. Bump on release. */
    const VERSION = '1.0.0';

    /** Asset version off-live; a random suffix is appended so nothing caches. */
    const TEST_VERSION = '0.0.0';

    /**
     * Boot and execute a CLI cron job.
     *
     * Reads config, runs the minimal setup path (no URL parsing or access-file
     * validation), instantiates the requested cron class by fully-qualified
     * name, seeds the State singleton, then calls execute().
     *
     * Calls stop() on success or on any uncaught exception.
     *
     * @param  array  $argv  CLI argument vector forwarded to the cron's execute().
     * @return void
     */
    public function cron($argv) {
        try {
            $this->readConfigFromFile();
            $this->argv = $argv;
            $this->basicSetup();
            $class = $this->get('cron');
            if(!class_exists($class)) {
               throw new \Exception('class does not exist: ' . $class);
            }
            $cron = new $class($this, $argv);
            // Seed the global State singleton so cron jobs can call State::get().
            State::instantiate($this);
            $cron->execute($argv);
        }
        catch(\Exception $e) {
            $this->stop($e->getMessage());
        }
        $this->stop();
    }

    /**
     * Boot and handle an inbound HTTP request.
     *
     * Full boot sequence: config → setup (URL parsing, access file) → route
     * resolution → controller instantiation → optional initialize() hook →
     * processAction() → view output().
     *
     * Overrides the parent's run() to inject the State singleton (with
     * controller context) between initialize() and processAction(), and to
     * refuse requests for hostnames the site is not configured to serve.
     *
     * @return void
     * @throws \Exception If routing, controller, or view contracts are not met.
     */
    public function run() {
        try {
            $this->readConfigFromFile()->setup();
            $router = new Router($this);
            $route = $router->getRoute();

            // Route defaults should be an object (Mutator); receiving an array
            // means the route file has a misconfiguration.
            if(is_array(($defaults = $route->get('defaults')))) {
                throw new \Exception('invalid route defaults');
            }
            $class = $defaults->get('controller');

            if(!class_exists($class)) {
                throw new \Exception('route controller does not exist: ' . $class);
            }

            $controller = new $class($this);

            if(!method_exists($controller , 'processAction')) {
                throw new \Exception('controller missing method processAction(): '. get_class($controller));
            }

            // actionRef is set by the router when the URL contains an explicit
            // action segment; otherwise fall back to the route default action.
            $actionRef = $this->get('config')->getProperty('url')->get('actionRef');
            $action = empty($actionRef) ? $defaults->get('action') : $actionRef;

            $controller->set('action' , $action);
            // Seed State with controller so snapshot() can expose view/action context.
            State::instantiate($this, $controller);

            // Refuse requests whose Host header is not a domain this site serves.
            $state = State::get();
            if(empty($state->domain)) {
                http_response_code(421);
                $this->showMisconfigured($state);
                return;
            }

            if(method_exists($controller , 'initialize')) {
                $controller->initialize();
                // initialize() may set the view on the controller; invalidate the
                // cached snapshot so processAction() sees the updated view reference.
                State::invalidate();
            }
            $controller->processAction();

            if(!method_exists($controller , 'get')) {
                throw new \Exception('controller missing method get("view"): '. get_class($controller));
            }

            $view = $controller->get('view');

            if(!method_exists($view , 'output')) {
                throw new \Exception('view missing method output(): '. get_class($view));
            }

            $view->output();
        }
        catch(\Exception $e) {
            $this->stop($e->getMessage());
        }
        $this->stop();
    }

    /**
     * Minimal shared setup used by both cron() and (indirectly) run().
     *
     * Runs after config is read. Switches to the test database when not on
     * live/staging, then delegates to the parent for encoder/encrypter/digest
     * instantiation. After the parent runs, wires the Notify mailer.
     *
     * @return void
     */
    protected function basicSetup() {
        $this->setupTestDatabaseConnection();
        parent::basicSetup();
        Notify::setApp($this);
    }

    /**
     * Full HTTP-request setup.
     *
     * @return void
     */
    protected function setup() {
        $this->setupTestDatabaseConnection();
        parent::setup();
        $this->basicSetup();
    }

    /**
     * Redirect the database config to the test database on non-live/staging environments.
     *
     * When neither __LIVE__ nor __STAGING__ is true and the config contains a
     * `database.test` sub-object, that sub-object replaces the top-level
     * `database` config property so all subsequent DB connections use the test
     * database. This prevents accidental writes to the live database during
     * development and test runs.
     *
     * @return void
     */
    protected function setupTestDatabaseConnection() {
        if(__LIVE__ !== true && __STAGING__ !== true) {
            $database = $this->get('config')->getProperty('database');
            if(isset($this->argv) && in_array('--live', $this->argv)) {
                // --live on dev: connect to the live DB via the dev-accessible host.
                if(!empty($database->live)) {
                    $this->config->setProperty('database', $database->live);
                }
            } elseif(!empty($database->test)) {
                // Overwrite the database config in-place so openDatabaseConnection()
                // reads the test credentials without needing any other changes.
                $this->config->setProperty('database', $database->test);
            }
        }
    }

    /**
     * Open the Eloquent/Capsule MySQL database connection.
     *
     * Reads driver, host, name, user, pass, charset, and collation from the
     * already-resolved `database` config property (which may have been swapped
     * to test credentials by setupTestDatabaseConnection()). Boots Eloquent as
     * a global singleton and stores the Capsule instance under 'connection'.
     *
     * @return $this
     * @throws \Exception If database config is empty or the connection fails.
     */
    protected function openDatabaseConnection() {
        if(empty(($config = $this->get('config')->getProperty('database')))) {
            throw new \Exception('unable to establish database connection');
        }
        try {
            $capsule = new DB;
            $settings = [
                'driver' => $config->driver,
                'host' => $config->host,
                'database' => $config->name,
                'username' => $config->user,
                'password' => $config->pass,
                'charset' => $config->charset,
                'collation' => $config->collation,
                'prefix' => '',
                // Fail fast when the database is unreachable. Without this PDO
                // waits on the OS connect timeout -- twenty-odd seconds during
                // which an Apache worker is pinned per request, so a database
                // that has gone away takes the whole site down with it instead
                // of showing an error page.
                'options' => [\PDO::ATTR_TIMEOUT => 5],
            ];
            $capsule->addConnection($settings);

            /*
             * The game's tables are a second connection, named 'game'.
             *
             * The game server owns rscd_players and everything keyed on it;
             * this site is a second reader and writer of somebody else's
             * schema, and the two do not have to live in the same database --
             * though sharing one schema is the default and the simplest setup.
             *
             * Naming the connection is what makes that safe. Capsule resolves
             * an unqualified table name against the default connection, so
             * every rscd_* query the site made was silently answered by a
             * same-named table in the site's own schema -- reads returned
             * nothing the game had written, and writes (a password reset, a
             * new character) landed somewhere the login server never looks.
             * It fails quietly in both directions, which is the worst way for
             * an authentication path to fail.
             *
             * `database.game` is the game schema's name. Leave it out and the
             * game connection is the site's own database, which is the right
             * default for anyone running both out of one schema.
             */
            $settings['database'] = empty($config->game) ? $config->name : $config->game;
            $capsule->addConnection($settings, 'game');

            $capsule->setEventDispatcher(new Dispatcher(new Container));
            $capsule->setAsGlobal();
            $capsule->bootEloquent();
            $this->set('connection', $capsule);
        } catch(\Exception $e) {
             throw new \Exception('unable to establish database connection:' . $e->getMessage());
        }
        return $this;
    }

    /**
     * Render the domain-misconfigured error page and send it to the browser.
     *
     * Called from run() when the request hostname is not in the configured
     * `domains` list. Passes the unrecognised hostname as [{hostname}] into
     * misconfigured.html.
     *
     * @param  object  $state  The current State snapshot (domain is null here).
     * @return void
     */
    protected function showMisconfigured($state) {
        $html = file_get_contents(__HTML_DIR__ . 'misconfigured.html');
        $html = str_replace('[{hostname}]', htmlspecialchars($state->url->get('domain')), $html);
        header('Content-Type: text/html; charset=utf-8');
        $this->stop($html);
    }

}
