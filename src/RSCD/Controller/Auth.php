<?php

namespace RSCD\Controller;

use RSCD\Model\AuthToken;
use RSCD\Model\Authenticator;
use RSCD\Model\Notify;
use RSCD\Model\Object\Session;
use RSCD\Model\Object\User;
use RSCD\Util\Cookies;
use RSCD\Util\CURL;
use RSCD\Util\Strings;
use RSCD\View\ShopView;

/**
 * Handles all public authentication flows for the account manager and forums.
 *
 * Covers:
 *   - Sign-in (GET form, POST credentials, POST AJAX credentials)
 *   - Session activation (GET with short-lived token — two-step sign-in)
 *   - Sign-out (GET, POST)
 *   - Registration (GET form, POST submission, optional Cloudflare Turnstile)
 *   - Email verification (GET with code URL param)
 *   - Forgot-password (GET form, POST request email)
 *   - Password reset (GET form, POST new password, via AuthToken)
 *
 * All authenticated routes delegate to $this->authorize() before rendering.
 * Email notifications (activation, password reset, etc.) are sent via Notify.
 *
 * Two-step sign-in: POST validates credentials and issues a 5-minute
 * AuthToken, then redirects to GET /activate-session/ which sets the auth
 * cookie in a normal page response. Safari iOS does not reliably commit
 * cookies set during an XHR response before the JavaScript redirect fires;
 * setting the cookie on the GET response fixes this.
 */
class Auth extends \RSCD\Controller\Common\Controller {

    /**
     * Initialise the controller with the public-facing page view.
     */
    public function initialize() {
        $this->set('view', new ShopView($this->get('app')));
    }

    /**
     * Default action — redirect unmatched /auth/* requests to the sign-in page.
     *
     * @param object $state Application state.
     */
    public function processDefaultAction($state) {
        $state->request->action = 'sign-in';
        $state->request->method = 'GET';
        return $this->httpGetSignIn($state);
    }

    /**
     * Render a response object's errors/messages/warnings as alert HTML.
     *
     * Shared by the auth page renderers so POST handlers can re-render their
     * form with an outcome notice instead of returning JSON.
     *
     * @param  object|null $response Response object with errors/messages/warnings arrays.
     * @return string                Alert markup, or an empty string.
     */
    protected function buildAlertsHtml($response) {
        $alerts = '';
        // Escaped like the Forum/Account builders — alert text can quote what
        // the user typed. The sign-in "requested resource" notice carries its
        // link because it is built separately in httpGetSignIn, not here.
        if(!empty($response->errors)) {
            $alerts .= '<div class="alert alert-danger" role="alert"><b>The following errors occurred:</b><br />' . Strings::displayText(implode(', ', $response->errors)) . '</div>';
        }
        if(!empty($response->messages)) {
            $alerts .= '<div class="alert alert-success" role="alert">' . Strings::displayText(implode(', ', $response->messages)) . '</div>';
        }
        if(!empty($response->warnings)) {
            $alerts .= '<div class="alert alert-warning" role="alert">' . Strings::displayText(implode(', ', $response->warnings)) . '</div>';
        }
        return $alerts;
    }

    /**
     * Verify an email address using a UUID code passed in the URL.
     *
     * Sets the User status from STATUS_PENDING_CONFIRMATION to STATUS_ACTIVE.
     * After verification, displays the sign-in page with a success message.
     * Already-verified accounts and invalid codes show appropriate messages.
     *
     * @param object $state Application state.
     */
    protected function httpGetVerifyEmail($state) {
        $this->authorize();
        $state = $this->getState();
        // Already signed in — go to the homepage.
        if(!empty($state->activeUser->id)) {
            return $this->redirect($state->url->getBaseUrl());
        }
        $response = $this->getBlankResponse();
        $response->messages = [];
        try {
            // The verification code is the user's UUID, passed as a URL variable.
            $id   = $state->url->getVariable('code');
            $user = !empty($id) ? User::where('uuid', $id)->first() : null;
            if(!empty($user->id) && $user->status == User::STATUS_PENDING_CONFIRMATION) {
                // Activate the account.
                $user->status = User::STATUS_ACTIVE;
                $user->save();
                $response->messages[] = 'Your account has been activated.  Please sign in to continue.';
            }
            else if(!empty($user->id)) {
                throw new \Exception('This account has already been activated.  Please sign in to continue.');
            }
            else {
                throw new \Exception('The verification code supplied was invalid.  Please register to create an account.');
            }
        } catch (\Exception $e) {
            $response->errors[] = $this->getError($e);
        }
        return $this->httpGetSignIn($state, $response);
    }

    /**
     * Render the sign-in page HTML.
     *
     * Displays alerts (errors, warnings, messages) from a prior action when
     * $response is passed.  Shows an "access required" notice if the user was
     * redirected here via a 'ref' URL parameter.  Authenticated users are
     * redirected to the homepage instead.
     *
     * @param object      $state    Application state.
     * @param object|null $response Optional response object with errors/messages/warnings.
     */
    protected function httpGetSignIn($state, $response = null) {
        $this->authorize();
        $state = $this->getState();
        // If already signed in (or just signed in via POST), redirect to referer or homepage.
        if(!empty($state->activeUser->id) || !empty($response->user->id)) {
            return $this->redirect();
        }
        $signIn = $state->view->getViewLayout('auth' . DIRECTORY_SEPARATOR . 'sign-in.html');
        $signIn->populateHtmlFromFile();
        $referer = '';
        $alerts  = '';
        $urlReferer = $state->url->getVariable('ref');
        // Show the requested URL to the user when they were redirected here.
        if(empty($response->errors) && !empty($urlReferer) && $urlReferer != '/') {
            $alerts .= '<div class="alert alert-danger" role="alert"><b>Please sign in or <a href="' . $state->url->getBaseUrl() . 'register/">register</a> to access the requested resource:</b><br />' . $state->url->getBaseUrl() . htmlspecialchars($urlReferer, ENT_QUOTES) .  '</div>';
        }
        $alerts .= $this->buildAlertsHtml($response);
        if(!empty($urlReferer)) {
            $referer = $urlReferer;
        }
        $signIn->injectHtml('alerts', $alerts);
        $signIn->injectHtml('referer', htmlspecialchars($referer, ENT_QUOTES));
        $state->view->setPage($signIn->get('html'), [], 'Sign in to your account');
    }

    /**
     * Sign the current user out via a GET request.
     *
     * Deletes the authentication cookie, marks the session as terminated, and
     * renders the sign-out confirmation page.
     *
     * @param object $state Application state.
     */
    protected function httpGetSignOut($state) {
        // Resolve the active user from the auth cookie first — initialize()
        // deliberately skips authorize(), and without it the DB session would
        // survive sign-out, leaving the cookie value valid if captured.
        $this->authorize();
        $state = $this->getState();
        // Delete the persistent authentication cookie.
        Cookies::delete(Authenticator::COOKIE, $state->url->get('directory'), $state->url->get('domain'));
        if(isset($_COOKIE[Authenticator::COOKIE])) {
            unset($_COOKIE[Authenticator::COOKIE]);
        }
        if(!empty($state->activeUser->id)) {
            // Mark the DB session as terminated before clearing the active user.
            if(!empty($state->activeUser->session->id)) {
                $state->activeUser->session->status = Session::STATUS_TERMINATED;
                $state->activeUser->session->save();
            }
            unset($state->activeUser);
        }
        $view    = $this->get('view');
        $signOut = $view->getViewLayout('auth' . DIRECTORY_SEPARATOR . 'sign-out.html');
        $signOut->populateHtmlFromFile();
        $view->setPage($signOut->get('html'), [], 'Signed out successfully');
    }

    /**
     * Handle AJAX POST sign-in requests (returns JSON instead of re-rendering HTML).
     *
     * @param object $state Application state.
     * @return mixed        JSON response with redirect_url or errors.
     */
    protected function httpPostAjaxSignIn($state) {
        return $this->httpPostSignIn($state, true);
    }

    /**
     * Handle POST sign-in requests.
     *
     * Validates credentials without creating a session. On success, issues a
     * short-lived AuthToken and returns a redirect to /activate-session/ where
     * the session cookie is set on a GET response (see class docblock).
     *
     * @param object $state Application state.
     * @param bool   $ajax  When true, return JSON; when false, re-render the sign-in form.
     * @return mixed        JSON response (AJAX) or re-rendered sign-in page (standard).
     */
    protected function httpPostSignIn($state, $ajax = false) {
        $response = $this->getBlankResponse();
        try {
            $user  = $this->validateCredentials();
            $token = AuthToken::issue($user, AuthToken::TYPE_SESSION_ACTIVATION, [
                'redirect_url' => $this->getReferer($state->url)
            ]);
            if(empty($token)) {
                throw new \Exception('Unable to initiate sign-in.  Please try again.');
            }
            $response->redirect_url = $state->url->getBaseUrl() . 'activate-session/token%3D' . $token . '/';
        }
        catch(\Exception $e) {
            $response->errors[] = $this->getError($e);
        }
        if($ajax) {
            return $this->showJson($response);
        }
        // Non-AJAX: redirect to activate-session on success, re-render form on error.
        if(!empty($response->redirect_url) && empty($response->errors)) {
            return $state->app->redirect($response->redirect_url);
        }
        return $this->httpGetSignIn($state, $response);
    }

    /**
     * Activate a session from a short-lived AuthToken (GET request).
     *
     * The user was redirected here after successful credential verification in
     * httpPostSignIn(). This GET handler creates the auth cookie and session in
     * a normal page response, which Safari iOS commits reliably before the
     * redirect fires — unlike XHR-set cookies.
     *
     * The token is consumed (single use) whether or not activation succeeds.
     * On invalid/expired token, redirects to sign-in.
     *
     * @param object $state Application state.
     * @return mixed        Redirect response.
     */
    protected function httpGetActivateSession($state) {
        $token    = $state->url->getVariable('token');
        $consumed = !empty($token) ? AuthToken::consume($token, AuthToken::TYPE_SESSION_ACTIVATION) : null;

        if(empty($consumed->user->id)) {
            return $state->app->redirect($state->url->getBaseUrl() . 'sign-in/');
        }

        $authenticator = new Authenticator($this->get('app'));
        $authenticator->authorizeWithUser($consumed->user);

        $redirectUrl = !empty($consumed->payload->redirect_url) ? $state->url->getBaseUrl() . ltrim($consumed->payload->redirect_url, '/') : null;
        return $this->redirect($redirectUrl);
    }

    /**
     * Handle POST sign-out requests (AJAX-compatible).
     *
     * Deletes the auth cookie, marks the DB session as terminated, and returns
     * a JSON response.
     *
     * @param object $state Application state.
     * @return mixed        JSON response with errors if any.
     */
    protected function httpPostSignOut($state) {
        $response = $this->getBlankResponse();
        try {
            Cookies::delete(Authenticator::COOKIE, $state->url->get('directory'), $state->url->get('domain'));
            if(isset($_COOKIE[Authenticator::COOKIE])) {
                unset($_COOKIE[Authenticator::COOKIE]);
            }
            if(!empty($state->activeUser->id)) {
                if(!empty($state->activeUser->session->id)) {
                    $state->activeUser->session->status = Session::STATUS_TERMINATED;
                    $state->activeUser->session->save();
                }
                unset($state->activeUser);
            }
        }
        catch(\Exception $e) {
            $response->errors[] = $this->getError($e);
        }
        return $this->showJson($response);
    }

    /**
     * Handle POST forgot-password requests.
     *
     * Looks up the user by email address and, if found and active, sends a
     * password-reset email; unverified accounts get their activation email
     * resent instead.  Deliberately does NOT reveal whether the email is
     * registered (no error when the address is unknown) to prevent account
     * enumeration attacks.
     *
     * @param object $state Application state.
     * @return mixed        Re-rendered forgot-password page with an outcome notice.
     */
    protected function httpPostForgotPassword($state) {
        $response = $this->getBlankResponse();
        try {
            $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
            if(empty($email)) {
                throw new \Exception('No email was provided');
            }
            $user = User::where('email_address', $email)->first();
            if(!empty($user->id)) {
                // Only send an email if the account exists; silently succeed otherwise.
                if($user->status == User::STATUS_PENDING_CONFIRMATION) {
                    $this->sendAccountActivationTo($user);
                }
                else {
                    $this->sendPasswordResetRequestTo($user);
                }
            }
            // The success copy is identical whether or not the address is
            // registered, so responses can't be used to enumerate accounts.
            $response->messages[] = 'If an account exists for that email address, a message has been sent to it.  Please check your inbox and follow the link inside.';
        }
        catch(\Exception $e) {
            $response->errors[] = $this->getError($e);
        }
        return $this->httpGetForgotPassword($state, $response);
    }

    /**
     * Render the forgot-password form.
     *
     * Redirects already-authenticated users away from this page.
     *
     * @param object      $state    Application state.
     * @param object|null $response Optional response object with errors/messages.
     */
    protected function httpGetForgotPassword($state, $response = null) {
        $this->authorize();
        $state = $this->getState();
        if(!empty($state->activeUser->id)) {
            return $this->redirect();
        }
        $forgotPassword = $state->view->getViewLayout('auth' . DIRECTORY_SEPARATOR . 'password-forgot.html');
        $forgotPassword->populateHtmlFromFile();
        $forgotPassword->injectHtml('alerts', $this->buildAlertsHtml($response));
        $state->view->setPage($forgotPassword->get('html'), [], 'Forgot your password?');
    }

    /**
     * Handle POST password-reset form submissions.
     *
     * Validates the AuthToken from the URL 'code' param, enforces password
     * complexity rules, and updates the user's password.  The token is only
     * consumed after the password saves successfully, so a rejected password
     * (too short, mismatch) doesn't burn the reset link.  Sends a "password
     * changed" confirmation email.
     *
     * @param object $state Application state.
     * @return mixed        JSON response with errors if validation fails.
     */
    protected function httpPostResetPassword($state) {
        $response       = $this->getBlankResponse();
        $validationCode = $state->url->getVariable('code');
        try {
            $found = !empty($validationCode) ? AuthToken::find($validationCode, AuthToken::TYPE_PASSWORD_RESET) : null;
            if(empty($found->user->id)) {
                throw new \Exception('The password reset email validation code is invalid or has expired.  Please restart the process.');
            }
            $password             = filter_input(INPUT_POST, 'password',         FILTER_UNSAFE_RAW);
            $passwordConfirmation = filter_input(INPUT_POST, 'confirm_password', FILTER_UNSAFE_RAW);
            if(empty($password) || empty($passwordConfirmation)) {
                throw new \Exception('Both the new password and password confirmation are required');
            }
            if($password !== $passwordConfirmation) {
                throw new \Exception('The new password and password confirmation must match');
            }
            $this->validatePasswordComplexityAndThrow($password);
            $user = $found->user;
            // The User model's mutator will hash the password before storing it.
            $user->password = $password;
            if(!$user->save()) {
                throw new \Exception('Unable to update account.  Please try again later.');
            }
            // Success — burn the token so the link is single use.
            AuthToken::consume($validationCode, AuthToken::TYPE_PASSWORD_RESET);
            $this->sendPasswordChangedTo($user);
            $response->messages[] = 'Your password has been changed successfully.  Please sign in with your new password.';
            return $this->httpGetSignIn($state, $response);
        }
        catch(\Exception $e) {
            $response->errors[] = $this->getError($e);
        }
        // Error — re-render the reset form (the code stays in the URL) so the
        // user can correct the password without burning the reset link.
        return $this->httpGetResetPassword($state, $response);
    }

    /**
     * Render the password-reset form.
     *
     * Validates the AuthToken before showing the form; invalid or expired
     * tokens redirect to the sign-in page with an error.  A 'cancel=true'
     * param allows the user to invalidate the token without setting a new
     * password.
     *
     * @param object      $state    Application state.
     * @param object|null $response Optional response object with errors/messages.
     */
    protected function httpGetResetPassword($state, $response = null) {
        $this->authorize();
        $state = $this->getState();
        if(!empty($state->activeUser->id)) {
            return $this->redirect();
        }
        $response       = $response ?? $this->getBlankResponse();
        $cancel         = $state->url->getVariable('cancel');
        $validationCode = $state->url->getVariable('code');
        $found          = !empty($validationCode) ? AuthToken::find($validationCode, AuthToken::TYPE_PASSWORD_RESET) : null;
        if(empty($found->user->id)) {
            $response->errors[] = 'The password reset email validation code is invalid or has expired.';
            return $this->httpGetSignIn($state, $response);
        }
        if($cancel == 'true') {
            // User chose to cancel the reset — invalidate the token.
            AuthToken::consume($validationCode, AuthToken::TYPE_PASSWORD_RESET);
            $response->messages[] = 'The password reset request has been canceled successfully';
            return $this->httpGetSignIn($state, $response);
        }
        $resetPassword = $state->view->getViewLayout('auth' . DIRECTORY_SEPARATOR . 'password-reset.html');
        $resetPassword->populateHtmlFromFile();
        $resetPassword->injectHtml('alerts', $this->buildAlertsHtml($response));
        $state->view->setPage($resetPassword->get('html'), [], 'Reset your password');
    }

    /**
     * Render the registration form.
     *
     * On a failed POST the previously-entered name and email address are
     * re-injected so the user only has to re-type the passwords.
     *
     * @param object      $state    Application state.
     * @param object|null $response Optional response object with errors/messages.
     */
    protected function httpGetRegister($state, $response = null) {
        $this->authorize();
        $state = $this->getState();
        if(!empty($state->activeUser->id)) {
            return $this->redirect();
        }
        $register = $state->view->getViewLayout('auth' . DIRECTORY_SEPARATOR . 'register.html');
        $register->populateHtmlFromFile();
        $register->injectHtml('alerts', $this->buildAlertsHtml($response));
        $register->injectHtml('prefill_name', htmlspecialchars((string)($response->prefill_name ?? ''), ENT_QUOTES));
        $register->injectHtml('prefill_email', htmlspecialchars((string)($response->prefill_email ?? ''), ENT_QUOTES));
        $siteKey = (string)($state->config->getProperty('cloudflare')->turnstile->siteKey ?? '');
        $widget = '';
        if($siteKey !== '') {
            // Only pull in the Cloudflare challenge script when a site key is configured.
            $widget = '<div class="cf-turnstile" data-sitekey="' . htmlspecialchars($siteKey, ENT_QUOTES) . '"></div>' . "\n"
                    . '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
        }
        $register->injectHtml('turnstile_widget', $widget);
        $state->view->setPage($register->get('html'), [], 'Register');
    }

    /**
     * Handle POST registration form submissions.
     *
     * Validation steps:
     *   1. Verify the Cloudflare Turnstile response, when configured.
     *   2. Check required fields (name, email address, password + confirmation).
     *   3. Enforce password complexity.
     *   4. Reject duplicate email addresses; a duplicate still pending email
     *      confirmation gets its activation email resent instead.
     *
     * On success:
     *   - Creates the User with STATUS_PENDING_CONFIRMATION.
     *   - Attaches the Member role.
     *   - Sends an account activation email with a verification link.
     *
     * @param object $state Application state.
     * @return mixed        JSON response with user or errors.
     */
    protected function httpPostRegister($state) {
        $response = $this->getBlankResponse();
        try {
            $name                 = trim((string)filter_input(INPUT_POST, 'name', FILTER_UNSAFE_RAW));
            $email                = filter_input(INPUT_POST, 'email_address',    FILTER_SANITIZE_EMAIL);
            $password             = filter_input(INPUT_POST, 'password',         FILTER_UNSAFE_RAW);
            $passwordConfirmation = filter_input(INPUT_POST, 'confirm_password', FILTER_UNSAFE_RAW);

            $this->validateTurnstileAndThrow();

            if(empty($name)) {
                throw new \Exception('The display name is required and can\'t be empty');
            }
            if(empty($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new \Exception('A valid email address is required');
            }
            if(empty($password) || empty($passwordConfirmation)) {
                throw new \Exception('Both the password and password confirmation are required');
            }
            if($password !== $passwordConfirmation) {
                throw new \Exception('The password and password confirmation must match');
            }
            $this->validatePasswordComplexityAndThrow($password);

            $existingUser = User::where('email_address', $email)->first();
            if(!empty($existingUser->id)) {
                if($existingUser->status == User::STATUS_PENDING_CONFIRMATION) {
                    // Same address re-registering before verifying — resend the link.
                    $this->sendAccountActivationTo($existingUser);
                    throw new \Exception('This email address is already registered but not yet verified.  We have resent the activation email — please click the link inside to complete the process.');
                }
                throw new \Exception('An account is already registered with the email address provided.  You can reset your password from the sign-in page.');
            }

            $user = User::create([
                'status'        => User::STATUS_PENDING_CONFIRMATION,
                'name'          => $name,
                'email_address' => $email,
                'password'      => $password
            ]);
            if(empty($user->id)) {
                throw new \Exception('Unable to create your account.  Please try again later.');
            }

            // Attach the Member role.
            $memberRole = \RSCD\Model\Object\Role::where('name', 'Member')->first();
            if(!empty($memberRole->id) && !$user->roles()->where('role.id', $memberRole->id)->exists()) {
                $user->roles()->attach($memberRole->id);
            }

            // Send the account activation email with a verification link.
            $this->sendAccountActivationTo($user);

            $response->messages = ['Account created.  We have sent you an email with the subject "Verify your email address" — please click the link inside to activate your account.'];
            return $this->httpGetSignIn($state, $response);
        }
        catch (\Exception $e) {
            $response->errors[] = $this->getError($e);
        }
        // Error — re-render the form with the entered name/email preserved.
        $response->prefill_name  = trim((string)filter_input(INPUT_POST, 'name', FILTER_UNSAFE_RAW));
        $response->prefill_email = (string)filter_input(INPUT_POST, 'email_address', FILTER_SANITIZE_EMAIL);
        return $this->httpGetRegister($state, $response);
    }

    /**
     * Verify a Cloudflare Turnstile response when a secret key is configured.
     *
     * Bot protection is optional: with no cloudflare.turnstile.secretKey in
     * app.json, registration works without a challenge. When configured, the
     * 'cf-turnstile-response' POST field is verified against Cloudflare's
     * siteverify API.
     *
     * @throws \Exception When the challenge is missing or fails verification.
     */
    protected function validateTurnstileAndThrow() {
        $state     = $this->getState();
        $turnstile = $state->config->getProperty('cloudflare')->turnstile ?? null;
        if(empty($turnstile->secretKey)) {
            return;
        }
        $challenge = filter_input(INPUT_POST, 'cf-turnstile-response', FILTER_UNSAFE_RAW);
        if(empty($challenge)) {
            throw new \Exception('Please complete the security challenge to continue.');
        }
        $result = (new CURL())
            ->setMode(CURL::MODE_FORMDATA)
            ->setMethod(CURL::HTTP_POST)
            ->setUrl('https://challenges.cloudflare.com/turnstile/v0/siteverify')
            ->setParams(http_build_query([
                'secret'   => $turnstile->secretKey,
                'response' => $challenge,
                'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
            ]))
            ->send()
            ->getResponse();
        $json = !empty($result) ? json_decode($result) : null;
        if(empty($json->success) || $json->success !== true) {
            throw new \Exception('The security challenge failed.  Please try again.');
        }
    }

    /**
     * Enforce the password complexity policy.
     *
     * @param string $password Candidate password (plain text).
     * @throws \Exception      When the password fails a complexity rule.
     */
    protected function validatePasswordComplexityAndThrow($password) {
        if(preg_match('/[A-Z]/', $password) == 0) {
            throw new \Exception('The password must contain an uppercase letter');
        }
        if(preg_match('/[0-9]/', $password) == 0) {
            throw new \Exception('The password must contain a number');
        }
        if(strlen($password) < 8) {
            throw new \Exception('The password must be at least 8 characters');
        }
    }

    /**
     * Validate credentials from POST without creating a session or cookie.
     *
     * Used by the two-step sign-in flow: credentials are validated here, then
     * an AuthToken is issued. The session and cookie are created in
     * httpGetActivateSession() on a GET response so Safari iOS commits the
     * cookie before the redirect fires.
     *
     * Clears any stale auth cookie before checking credentials.
     *
     * @return object       Verified User model.
     * @throws \Exception   When the account is unconfirmed or credentials are wrong.
     */
    protected function validateCredentials() {
        $state    = $this->getState();
        $email    = filter_input(INPUT_POST, 'email',    FILTER_SANITIZE_EMAIL);
        $password = filter_input(INPUT_POST, 'password', FILTER_UNSAFE_RAW);
        $user     = !empty($email) ? User::where('email_address', $email)->first() : null;

        if(!empty($user->id) && $user->status == User::STATUS_PENDING_CONFIRMATION) {
            throw new \Exception('Account is pending email confirmation.  We have sent you an email with the subject "Verify your email address".  Please click the link inside to complete the process.  Didn\'t get it?  Use the "Forgot password?" link below — for unverified accounts it re-sends the verification email instead.');
        }

        Cookies::delete(Authenticator::COOKIE, $state->url->get('directory'), $state->url->get('domain'));
        if(isset($_COOKIE[Authenticator::COOKIE])) {
            unset($_COOKIE[Authenticator::COOKIE]);
        }

        $verified = User::findWithCredentials($email, $password);
        if(empty($verified->id)) {
            throw new \Exception('Email / password combination is not valid');
        }
        return $verified;
    }

    /**
     * Create an AuthToken and send a password-reset email to the user.
     *
     * The reset URL contains the token as a URL-encoded 'code' parameter. The
     * token expires after 15 minutes, matching the email copy.
     *
     * @param object $user User model to send the reset email to.
     * @throws \Exception  When the email fails to send.
     */
    protected function sendPasswordResetRequestTo($user) {
        $state       = $this->getState();
        $state->smtp = $state->config->getProperty('smtp');
        if(!empty($state->smtp)) {
            $token = AuthToken::issue($user, AuthToken::TYPE_PASSWORD_RESET);
            if(empty($token)) {
                return false;
            }
            $notification = Notify::notification('user-password-reset', [
                'reset_url' => $state->url->getBaseUrl() . 'reset-password/code%3D' . $token . '/'
            ]);
            $response = Notify::send($user, $notification, $state->smtp);
            if(empty($response->email)) {
                throw new \Exception('A system issue occurred and the email failed to send.  Please try again later.');
            }
        }
    }

    /**
     * Send an account activation email with a verification link to a new user.
     *
     * The activation link uses the user's UUID as the verification code, so no
     * separate token record is required.
     *
     * @param object $user User model to activate.
     * @throws \Exception  When the activation email fails to send.
     */
    protected function sendAccountActivationTo($user) {
        $state       = $this->getState();
        $state->smtp = $state->config->getProperty('smtp');
        if(!empty($state->smtp) && !empty($user->id)) {
            $notification = Notify::notification('verify-email', [
                'activation_url' => $state->url->getBaseUrl() . 'verify-email/code%3D' . $user->uuid . '/'
            ]);
            $response = Notify::send($user, $notification, $state->smtp);
            if(empty($response->email)) {
                throw new \Exception('A system issue occurred and the activation email failed to send.  Please try again later.');
            }
        }
    }

    /**
     * Send a "your password was changed" confirmation email to the user.
     *
     * Best-effort: a delivery failure here never blocks the reset itself.
     *
     * @param object $user User model whose password was just changed.
     */
    protected function sendPasswordChangedTo($user) {
        $state       = $this->getState();
        $state->smtp = $state->config->getProperty('smtp');
        if(!empty($state->smtp) && !empty($user->id)) {
            $notification = Notify::notification('password-changed');
            Notify::send($user, $notification, $state->smtp);
        }
    }

    /**
     * Redirect the user after a successful sign-in or sign-out.
     *
     * If a URL is provided, redirects there directly.  Otherwise, uses the 'ref'
     * URL param (or HTTP referer) to redirect back to the originally requested page,
     * skipping auth-related pages (sign-in, register, etc.) to avoid loops.
     *
     * @param string|null $url Optional explicit redirect URL.
     * @return mixed           App redirect response.
     */
    protected function redirect($url = null) {
        $state = $this->getState();
        if(!empty($url)) {
            return $state->app->redirect($url);
        }
        $referer = $this->getReferer($state->url);
        // Avoid redirecting back to auth pages, which would create an infinite loop.
        $authPrefixes = ['sign-in', 'sign-out', 'register', 'forgot-password', 'reset-password', 'verify-email', 'activate-account', 'activate-session'];
        $isAuthPage = false;
        foreach($authPrefixes as $prefix) {
            if(Strings::startsWith($prefix, $referer)) {
                $isAuthPage = true;
                break;
            }
        }
        if(!empty($referer) && !$isAuthPage) {
            return $state->app->redirect($state->url->getBaseUrl() . $referer);
        }
        return $state->app->redirect($state->url->getBaseUrl());
    }

    /**
     * Resolve the post-login redirect URL from multiple possible sources.
     *
     * Priority: URL param 'ref' → POST 'referer' field → HTTP_REFERER header
     * (only if it originates from the same domain).
     *
     * @param object|null $url URL helper object (defaults to state->url).
     * @return string          The relative referer path, or empty string if none found.
     */
    protected function getReferer($url = null) {
        if(empty($url)) {
            $state = $this->getState();
            $url   = $state->url;
        }
        $baseUrl      = $url->getBaseUrl();
        $referer      = $url->getVariable('ref');
        $postReferer  = filter_input(INPUT_POST, 'referer', FILTER_UNSAFE_RAW);
        $httpReferer  = filter_input(INPUT_SERVER, 'HTTP_REFERER', FILTER_UNSAFE_RAW);
        if(empty($referer) && strlen((string)$postReferer) > 0) {
            $referer = $postReferer;
        }
        if(empty($referer) && strlen((string)$httpReferer) > 0) {
            // Only use the HTTP_REFERER if it's from our own domain (same base URL).
            if(strpos($httpReferer, $baseUrl) !== false) {
                $referer = substr($httpReferer, strlen($baseUrl));
            }
        }
        return $referer;
    }
}
