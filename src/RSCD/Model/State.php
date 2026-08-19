<?php
namespace RSCD\Model;

/**
 * Global request/application state container (singleton).
 *
 * State holds a single instance that aggregates the app, the active controller
 * (if any), and all derived context — URL, active user, timezone objects,
 * domain descriptor, and view reference — into one snapshot object consumed by
 * controllers and views.
 *
 * Usage pattern:
 *   State::instantiate($app, $controller);  // called once in App::run() / App::cron()
 *   $state = State::get();                  // called anywhere afterwards
 *
 * Calling State::get() before State::instantiate() throws an exception with
 * the sentinel message '@stateNotInstantiated' so callers can detect it.
 *
 * Unlike the ERP ancestor, timezones are plain PHP DateTimeZone data (no
 * tzdata table) and the domain is resolved from the `domains` config list
 * (no domain table). A request for a hostname not in that list yields a
 * null domain, which App::run() answers with HTTP 421.
 */
class State {

    /** @var static|null  The singleton State instance; null until instantiate() is called. */
    protected static $state;

    /** @var object|null  Cached snapshot; populated on first call to get(), reused thereafter. */
    protected $cachedSnapshot = null;

    /** @var mixed  The App instance provided at instantiation. */
    protected $app;

    /**
     * The active controller for this request, or null in cron context.
     *
     * @var mixed|null
     */
    protected $controller;

    /**
     * Construct a State instance bound to an app and optional controller.
     *
     * Not called directly by application code; use State::instantiate() instead.
     *
     * @param  mixed       $app         The App instance.
     * @param  mixed|null  $controller  The active controller, or null for cron context.
     */
    public function __construct($app, $controller = null) {
        $this->app = $app;
        $this->controller = $controller;
    }

    /**
     * Create (or replace) the global State singleton.
     *
     * Must be called once per request before any call to State::get().
     *
     * @param  mixed       $app         The App instance.
     * @param  mixed|null  $controller  The active controller, or null.
     * @return void
     */
    public static function instantiate($app, $controller = null) {
        static::$state = new State($app, $controller);
    }

    /**
     * Invalidate the cached snapshot so the next State::get() rebuilds it.
     *
     * Call this after mutating config properties (e.g. setting the active user
     * in Controller::authorize()) so subsequent State::get() calls reflect the
     * updated state rather than serving the stale cached snapshot.
     *
     * @return void
     */
    public static function invalidate() {
        if(isset(static::$state)) {
            static::$state->cachedSnapshot = null;
        }
    }

    /**
     * Return the (cached) snapshot of global state.
     *
     * @return object  The state snapshot (see snapshot() for shape).
     * @throws \Exception  With message '@stateNotInstantiated' if not yet seeded.
     */
    public static function get() {
        if(!isset(static::$state)) {
            throw new \Exception('@stateNotInstantiated');
        }
        if(static::$state->cachedSnapshot === null) {
            static::$state->cachedSnapshot = static::$state->snapshot();
        }
        return static::$state->cachedSnapshot;
    }


    /**
     * Build and return a plain-object snapshot of all global request context.
     *
     * Eagerly loads the active user's contact, parentUser.contact, and session
     * relations when a user is present. The default timezone is the user's
     * `timezone` column when it is a valid IANA identifier, else the app-wide
     * config `timeZone`.
     *
     * Snapshot shape:
     *  - request.action   — controller action name
     *  - request.method   — HTTP method
     *  - request.data     — empty object (populated downstream by controllers)
     *  - tmp              — environment tmp path string
     *  - url              — URL Mutator object
     *  - app              — App instance
     *  - config           — ConfigReader instance
     *  - activeUser       — User model (or null)
     *  - domain           — domain descriptor object (or null if host not served)
     *  - systemTimeZone   — timezone descriptor for the app config timezone
     *  - defaultTimeZone  — user's timezone if set and valid, else systemTimeZone
     *  - view             — View instance from controller (or null in cron)
     *
     * @return object  Plain-object snapshot.
     */
    public function snapshot() {
        $config = $this->app->get('config');
        $tmp = $this->getTmp($config);
        $url = $config->getProperty('url');
        $view = !empty($this->controller) ? $this->controller->get('view') : null;
        $activeUser = $config->getProperty('user');

        // Load additional user data

        if(!empty($activeUser->id)) {
            // Eager-load relations needed by views and access control checks.
            $activeUser->load(['contact', 'parentUser.contact', 'session']);
        }

        // Load timezone data

        // Start with the app-wide system timezone (e.g. 'America/New_York').
        $systemTimeZone = $this->getTimeZone($config->getProperty('timeZone'));
        $defaultTimeZone = $systemTimeZone;

        // Override the default with the user's preference if they have one
        // and it is a valid IANA identifier.
        if(!empty($activeUser->timezone)) {
            $userTimeZone = $this->getTimeZone($activeUser->timezone, false);
            if(!empty($userTimeZone)) {
                $defaultTimeZone = $userTimeZone;
            }
        }

        $domain = null;

        if(!empty($url)) {
            $domain = $this->getDomain($config, $url->get('domain'));
        }

        return (object)[
            'request' => (object)[
                'action' => !empty($this->controller) ? $this->controller->get('action') : null,
                'method' => !empty($url) ? $url->get('method') : null,
                'data' => (object)[]
            ],
            'tmp' => $tmp,
            'url' => $url,
            'app' => $this->app,
            'config' => $config,
            'activeUser' => $activeUser,
            'domain' => $domain,
            'systemTimeZone' => $systemTimeZone,
            'defaultTimeZone' => $defaultTimeZone,
            'view' => $view
        ];
    }

    /**
     * Build a timezone descriptor for an IANA timezone identifier.
     *
     * The descriptor keeps the field names the ERP tzdata model exposed
     * (`tzdata_id`, `tzdata_abbr`) so downstream date formatting is unchanged,
     * but is backed purely by PHP's own timezone database.
     *
     * @param  string  $tzId    An IANA timezone identifier (e.g. 'America/Chicago').
     * @param  bool    $strict  When true, throw on an unknown identifier; when
     *                          false, return null instead.
     * @return object|null  Descriptor with tzdata_id, tzdata_abbr, utc_offset.
     * @throws \Exception  If $strict and the identifier is unknown to PHP.
     */
    public function getTimeZone($tzId, $strict = true) {
        try {
            $zone = new \DateTimeZone((string)$tzId);
        } catch(\Exception $e) {
            if($strict) {
                throw new \Exception('Cannot find timezone information for ' . $tzId);
            }
            return null;
        }
        $now = new \DateTime('now', $zone);
        return (object)[
            'tzdata_id' => $zone->getName(),
            'tzdata_abbr' => $now->format('T'),
            'utc_offset' => $now->format('P')
        ];
    }

    /**
     * Resolve the current hostname against the configured domain list.
     *
     * Config contract: `domains` is an array of hostnames this site serves
     * (e.g. ["rscd-community.org", "www.rscd-community.org"]). When absent,
     * `primaryDomain` alone is served. localhost / 127.0.0.1 are always
     * accepted off-live so `php -S` development works without config edits.
     *
     * @param  mixed   $config    ConfigReader instance.
     * @param  string  $hostname  Hostname from the current request URL.
     * @return object|null  Descriptor with name (current host) and root
     *                      (primary domain), or null when the host is not served.
     */
    protected function getDomain($config, $hostname) {
        $primary = $config->getProperty('primaryDomain');
        $domains = $config->getProperty('domains');
        $served = [];
        if(!empty($primary)) {
            $served[] = strtolower($primary);
        }
        // ConfigReader decodes JSON arrays to stdClass (numeric keys), so a
        // plain is_array() check would silently skip the whole domains list.
        if($domains instanceof \stdClass) {
            $domains = get_object_vars($domains);
        }
        if(!empty($domains) && (is_array($domains) || $domains instanceof \Traversable)) {
            foreach($domains as $name) {
                $served[] = strtolower($name);
            }
        }
        if(__LIVE__ === false) {
            $served[] = 'localhost';
            $served[] = '127.0.0.1';
        }
        $hostname = strtolower((string)$hostname);
        if(empty($served) || !in_array($hostname, $served, true)) {
            return null;
        }
        return (object)[
            'id' => 1,
            'name' => $hostname,
            'root' => !empty($primary) ? $primary : $hostname
        ];
    }

    /**
     * Resolve the environment-appropriate temporary file directory from config.
     *
     * Checks __LIVE__ and __STAGING__ constants to pick the correct path.
     * Falls back to PHP's system temp dir if no matching config path is set.
     *
     * Priority order: test → staging → live → sys_get_temp_dir().
     *
     * @param  mixed  $config  The ConfigReader instance from the app.
     * @return string          Absolute tmp path, with trailing separator.
     */
    protected function getTmp($config) {
        $paths = $config->getProperty('paths');
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
