<?php

namespace RSCD\Model;

/**
 * Base class for all RSCD cron/CLI jobs.
 *
 * Provides shared infrastructure — verbosity control, a generic property
 * bag (get/set), a conditional write() helper, and environment-aware tmp
 * directory resolution — so each concrete cron only needs to implement
 * execute().
 *
 * Subclasses are instantiated by App::cron() with ($app, $argv) and are
 * expected to override execute() rather than calling parent::execute().
 *
 * Verbosity is enabled by passing any of the following CLI flags:
 *   --verbose=true  |  -V  |  --verbose
 *
 * Database target can be overridden with:
 *   --live   — connect to the live database regardless of environment setting
 *              (handled by App::setupTestDatabaseConnection() before the cron
 *              is instantiated; no action needed in the subclass)
 */
class Cron {

    /** @var mixed  The application instance injected at construction. */
    protected $app;

    /**
     * Whether verbose output is enabled.
     *
     * Set to true when any recognised verbose flag is present in $argv.
     *
     * @var bool
     */
    protected $verbose;

    /**
     * Initialise shared cron state and parse verbose flags from argv.
     *
     * @param  mixed  $app   The App instance (provides config, DB connection, etc.).
     * @param  array  $argv  CLI argument vector; checked for verbose flags.
     */
    public function __construct($app, $argv) {
        $this->set('app', $app);
        $this->verbose = false;
        // Support three equivalent verbose flag forms for CLI convenience.
        if(in_array('--verbose=true', $argv)) {
            $this->verbose = true;
        }
        if(in_array('-V', $argv)) {
            $this->verbose = true;
        }
        if(in_array('--verbose', $argv)) {
            $this->verbose = true;
        }
    }

    /**
     * Entry point for the cron job logic.
     *
     * Subclasses must override this method. The base implementation exits
     * immediately with an '@Not implemented' message, which signals a developer
     * error (instantiating a cron base class directly).
     *
     * @param  array  $argv  CLI argument vector.
     * @return void
     */
    public function execute($argv) {
        $this->run($argv);
    }

    /**
     * Override this method to implement cron logic.
     *
     * Subclasses may override run() instead of execute(). The runner calls
     * execute(), which delegates here. Subclasses overriding execute() directly
     * bypass run() entirely — both patterns work.
     *
     * @param  array  $argv  CLI argument vector.
     * @return void
     */
    public function run($argv = []) {
        exit('@Not implemented' . PHP_EOL);
    }

    /**
     * Generic property reader.
     *
     * Returns the value of the named property if it is declared on the class,
     * or null if it does not exist. Mirrors the pattern used in App and
     * controllers for consistent API across the framework.
     *
     * @param  string|null  $property  Name of the property to read.
     * @return mixed                   Property value, or null if not declared.
     */
    public function get($property = null) {
        if(property_exists($this, $property)) {
            return $this->$property;
        }
        return null;
    }

    /**
     * Generic property writer.
     *
     * Sets the named property if it is declared on the class, then returns
     * $this for fluent chaining. Silently ignores writes to undeclared
     * properties (prevents accidental dynamic property creation).
     *
     * @param  string|null  $property  Name of the property to set.
     * @param  mixed        $value     Value to assign.
     * @return $this
     */
    public function set($property = null, $value = null) {
        if(property_exists($this, $property)) {
            $this->$property = $value;
        }
        return $this;
    }

    /**
     * Print a line to stdout, but only when verbose mode is active.
     *
     * Used for progress and debug output that should be suppressed during
     * normal automated runs (e.g. Jenkins) but visible during manual
     * invocations with -V.
     *
     * @param  string  $string  The message to print (a newline is appended).
     * @return void
     */
    protected function write($string) {
        if($this->verbose) {
            print $string . PHP_EOL;
        }
    }

    /**
     * Resolve the environment-appropriate temporary file directory.
     *
     * Checks __LIVE__ and __STAGING__ constants to pick the correct path from
     * config (paths.tmp.test / paths.tmp.staging / paths.tmp.live). Falls back
     * to PHP's system temp dir if none of the config paths are set.
     *
     * Priority order: test → staging → live → sys_get_temp_dir().
     *
     * @return string  Absolute path to the tmp directory, with trailing separator.
     */
    protected function getTmp() {
        $app = $this->get('app');
        $config = $app->get('config');
        $paths = $config->getProperty('paths');
        // Test environment: both live and staging must be false.
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

}
