<?php
namespace RSCD\Model;

use RSCD\Model\App;
use RSCD\Model\Object\Session;
use RSCD\Model\Object\User;
use RSCD\Util\Cookies;
use RSCD\Util\Networking;
use RSCD\Util\Strings;

/**
 * Handles user authentication via credentials or a persistent encrypted cookie.
 *
 * Two authentication paths are provided:
 *
 *  1. authorizeWithCredentials() — traditional email + password login. On
 *     success a new Session row is created and a signed/encrypted cookie is
 *     written to the browser.
 *
 *  2. authorizeWithCookie() — "remember me" / session-resumption path. Reads
 *     the encrypted cookie, hashes the seed to find the matching Session row,
 *     and loads the associated User.
 *
 * Security model:
 *  - The cookie value is: base64(encrypt(32-byte random seed)).
 *  - The session serial stored in the DB is: hash(seed). The seed itself is
 *    never stored server-side, so a DB breach alone does not reveal the cookie.
 *  - Cookie domain is set to '.example.com' (leading dot = all subdomains)
 *    except on localhost where no domain restriction is applied.
 *
 * All public methods return $this for fluent chaining.
 */
class Authenticator {

    /** Name of the authentication cookie stored in the browser. */
    const COOKIE = 'x-rscd-auth';

    /** @var App  The application instance used to access config, encoder, and encrypter. */
    protected $app;

    /**
     * The authenticated User model, or an empty User instance if not yet authorized.
     *
     * @var User
     */
    protected $user;

    /**
     * Whether a successful authorization has been completed in this request.
     *
     * @var bool
     */
    protected $authorized;

    /**
     * Initialise the authenticator with an empty User and unauthorized state.
     *
     * @param  App  $app  The application instance.
     */
    public function __construct(App $app) {
        $this->app = $app;
        // Default to an empty (unauthenticated) user so callers never receive null.
        $this->user = new User();
        $this->authorized = false;
    }

    /**
     * Return the currently authenticated User model.
     *
     * Returns an empty User instance (id = null) if no authorization has
     * succeeded yet.
     *
     * @return User
     */
    public function user() {
        return $this->user;
    }

    /**
     * Return whether a successful authorization has been completed.
     *
     * @return bool  True after a successful authorizeWith*() call.
     */
    public function authorized() {
        return $this->authorized;
    }

    /**
     * Attempt to authorize a user by email and password.
     *
     * Looks up a matching User record via User::findWithCredentials(). On
     * success, generates a 32-byte random seed, writes the encrypted cookie,
     * creates a new Session row in the database, and marks this instance as
     * authorized.
     *
     * No-ops (returns $this unchanged) if:
     *  - $email or $password is empty
     *  - already authorized
     *  - no matching user is found
     *  - cookie creation fails
     *
     * @param  string  $email     The user's email address.
     * @param  string  $password  The user's plain-text password (verified by User::findWithCredentials).
     * @return $this
     */
    public function authorizeWithCredentials($email, $password) {
        if(empty($email) || empty($password) || $this->authorized()) {
            return $this;
        }

        // User::findWithCredentials() validates credentials and returns the
        // matching User model or null on failure.
        $user = User::findWithCredentials($email, $password);

        if(! empty($user)) {
            // Generate a cryptographically random seed; this is the shared
            // secret between browser (in the cookie) and server (hashed in DB).
            if($this->createCookie(($seed = Strings::generate(32)))) {
                $session = new Session();

                $session->user_id = $user->id;
                $session->status = Session::ACTIVE;
                // Store the hash of the seed, not the seed itself.
                $session->serial = Session::findSerialWithSeed($seed);
                $session->ip_address = Networking::ipAddress();

                $session->save();

                $this->user = $user;
                // Attach the new session to the user for downstream access.
                $this->user->session = $session;
                $this->authorize();
            }
        }

        return $this;
    }

    /**
     * Create a session and auth cookie for an already-verified User.
     *
     * Used by the two-step sign-in activation flow: credentials are verified in
     * the POST handler, then an AuthToken activation token is issued. The GET handler
     * (/activate-session/) calls this method to create the session cookie on a
     * normal page response, which Safari iOS commits reliably before redirecting.
     *
     * @param  User  $user  Authenticated user to create a session for.
     * @return $this
     */
    public function authorizeWithUser(User $user) {
        if(empty($user->id) || $this->authorized()) {
            return $this;
        }
        if($this->createCookie(($seed = Strings::generate(32)))) {
            $session = new Session();
            $session->user_id = $user->id;
            $session->status  = Session::ACTIVE;
            $session->serial  = Session::findSerialWithSeed($seed);
            $session->ip_address = Networking::ipAddress();
            $session->save();
            $this->user = $user;
            $this->user->session = $session;
            $this->authorize();
        }
        return $this;
    }

    /**
     * Attempt to authorize a user from an existing authentication cookie.
     *
     * Decrypts and decodes the cookie to recover the random seed, hashes it
     * to find the matching Session record, then loads the associated User.
     * Optionally touches (updates) the session timestamp to extend its life.
     *
     * No-ops (returns $this unchanged) if:
     *  - already authorized
     *  - the cookie is absent or the seed cannot be decrypted
     *  - no matching Session is found for the derived serial
     *  - the session has no valid user_id
     *
     * Note: session pruning and periodic ID regeneration logic is commented
     * out in this method. See inline comments for details.
     *
     * @param  bool  $touch  If true, call touch() on the session to update its timestamp.
     * @return $this
     */
    public function authorizeWithCookie($touch = true) {
        if($this->authorized()) {
            return $this;
        }

        $seed = $this->findSeedWithCookie();

        if(! empty($seed)) {
            // Commented-out: session timeout pruning was previously supported
            // but is currently disabled. The $sessionTimeout config key is
            // still read in some routes but not enforced here.
            //$timeout = $this->app->get('config')->getProperty('sessionTimeout');
            //if(! ($timeout >= 1))  {
            //    $timeout = null;
            //}
            //Session::prune($timeout);

            // Hash the seed to get the serial used as the DB lookup key.
            $session = Session::findWithSerial(Session::findSerialWithSeed($seed));

            if(empty($session->id)) {
                return $this;
            }

            // Commented-out: periodic session ID regeneration (every 60 seconds)
            // was planned but never activated. The code is preserved here for
            // future reference if CSRF/session-fixation hardening is needed.
            /*if(!isset($_SESSION['timestamp'])) {
                $_SESSION['timestamp'] = time();
            }*/
            /*if(time() - $_SESSION['timestamp'] >= 60) {
                session_regenerate_id(true);
                $_COOKIE['PHPSESSID'] = session_id();
                $_SESSION['timestamp'] = time();
                $session->serial = Session::findSerialWithSeed($seed);
                $session->save();
            }
            else */if($touch) {
                // Update the session's updated_at timestamp to keep it alive.
                $session->touch();
            }

            if($session->user_id > 0) {
                $this->user = User::find($session->user_id);
                $this->user->session = $session;
                $this->authorize();
            }


        }

        return $this;
    }

    // protected methods

    /**
     * Set the authorized flag to true.
     *
     * Called internally after a successful credential or cookie authentication.
     *
     * @return void
     */
    protected function authorize() {
        $this->authorized = true;
    }

    /**
     * Extract and decrypt the authentication seed from the request cookie.
     *
     * Reads the COOKIE constant ('x-rscd-auth') via filter_input(), then
     * base64-decodes and decrypts the value using the app's encoder and
     * encrypter services to recover the original random seed.
     *
     * Returns null if the cookie is absent or empty.
     *
     * @return string|null  The decrypted seed, or null if no valid cookie exists.
     */
    protected function findSeedWithCookie() {
        $cookie = filter_input(INPUT_COOKIE, Authenticator::COOKIE, FILTER_DEFAULT);

        $encoder = $this->app->get('encoder');
        $encrypter = $this->app->get('encrypter');

        // Decode first (base64), then decrypt to recover the seed.
        return ! empty($cookie) ?
            $encrypter->decrypt($encoder->decode($cookie)) : null;
    }

    /**
     * Encrypt a seed value and write it as a signed authentication cookie.
     *
     * The seed is encrypted then base64-encoded before being stored in the
     * cookie. The cookie domain is set to '.<domain>' (leading dot covers all
     * subdomains) unless the domain is 'localhost', in which case no domain
     * restriction is applied (passing false to Cookies::create()).
     *
     * @param  string  $seed  The 32-byte random seed to store.
     * @return bool           True if the cookie was written successfully.
     */
    protected function createCookie($seed) {
        $encoder = $this->app->get('encoder');
        $encrypter = $this->app->get('encrypter');
        $url = $this->app->get('config')->getProperty('url');
        // Prepend '.' so the cookie is valid across all subdomains.
        $domain = '.' . $url->get('domain');
        return Cookies::create(Authenticator::COOKIE,
            $encoder->encode($encrypter->encrypt($seed)),
            // Pass false as domain for localhost to avoid cookie rejection.
            $url->get('directory'), $domain == '.localhost' ? false : $domain);
    }
}
