<?php

namespace RSCD\Model;

use RSCD\Model\Email;
use RSCD\Model\URL;
use RSCD\Util\Strings;

/**
 * Notify — transactional email dispatcher.
 *
 * Sends templated email for account lifecycle events: password reset,
 * password changed, email verification. Email is the only channel.
 *
 * Notification definitions live in the NOTIFICATIONS map (slug → subject)
 * rather than a database table; the slug doubles as the template name under
 * app/email/<slug>.tpl. Build one with Notify::notification() and hand it to
 * send() (registered user) or sendEmail() (arbitrary address).
 *
 * Design decisions:
 *   - Static API — no instantiation required; the app reference is injected
 *     once via `setApp()` and stored for the request lifetime.
 *   - SMTP config is passed by the caller (from app.json), never read from
 *     global state here, so test and live mail settings stay swappable.
 *
 * Template system:
 *   - Email templates are loaded from disk by `Email::setBodyFromTemplate()`.
 *   - Template variables are interpolated via `%%key%%` placeholders.
 *
 * Standard template params automatically injected on every send:
 *   - `app.name`                — application display name
 *   - `app.logo`                — absolute URL to app logo image
 *   - `app.icon`                — absolute URL to app icon image
 *   - `app.base_url`            — base URL of the current request
 *   - `app.root`                — primary domain from config
 *   - `app.supportEmailAddress` — support address from config
 */
class Notify {

    /**
     * All notifications this application can send: slug => subject line.
     *
     * The slug is also the template filename (app/email/<slug>.tpl).
     * Subjects support the same %%key%% interpolation as template bodies.
     *
     * @var array<string, string>
     */
    const NOTIFICATIONS = [
        'user-password-reset' => 'Reset your %%app.name%% password',
        'user-password-set' => 'Set your %%app.name%% password',
        'password-changed' => 'Your %%app.name%% password was changed',
        'verify-email' => 'Verify your %%app.name%% email address'
    ];

    /**
     * Singleton-style app container reference.
     * Set once via `setApp()` before any notification is dispatched.
     *
     * @var object|null
     */
    protected static $app;

    /**
     * Store the app container for later retrieval by email helpers.
     *
     * Idempotent: once set, subsequent calls are silently ignored.
     *
     * @param  object|null  $app  Application container (must expose `get('config')`).
     * @return void
     */
    public static function setApp($app) {
        if(empty($app)) {
            return;
        }
        if(isset(static::$app)) {
            return;
        }
        static::$app = $app;
    }

    /**
     * Return the stored app container.
     *
     * @return object|null
     */
    public static function getApp() {
        return static::$app;
    }

    /**
     * Build a notification object from a registered slug.
     *
     * @param  string  $slug    A key of the NOTIFICATIONS map.
     * @param  array   $params  Template parameters for %%key%% interpolation.
     * @return object           Notification with slug, subject, and params.
     * @throws \Exception       When the slug is not registered.
     */
    public static function notification($slug, $params = []) {
        if(!isset(self::NOTIFICATIONS[$slug])) {
            throw new \Exception('unknown notification slug: ' . $slug);
        }
        return (object)[
            'slug' => $slug,
            'subject' => self::NOTIFICATIONS[$slug],
            'params' => $params
        ];
    }

    /**
     * Dispatch a notification email to a registered user.
     *
     * The recipient address is the user's own email_address column, falling
     * back to the default contact's address when the column is empty.
     *
     * @param  object        $user          User model.
     * @param  object        $notification  From Notify::notification().
     * @param  object|false  $smtp          SMTP config object, or false to skip email.
     * @return object  Result with `email` (0|1) and `errors` (array).
     */
    public static function send($user, $notification, $smtp = false) {
        $return = (object)[
            'email' => 0,
            'errors' => []
        ];

        if($smtp === false) {
            return $return;
        }

        $to = !empty($user->email_address) ? $user->email_address : ($user->contact->email_address ?? null);

        if(empty($to)) {
            $return->errors[] = (object)[
                'type' => 'generic',
                'code' => -1,
                'message' => 'User has no email address on file'
            ];
            return $return;
        }

        if(self::sendEmail($notification, $to, $smtp)) {
            $return->email = 1;
        }
        else {
            $return->errors[] = self::getUnknownError()->errors[0];
        }

        return $return;
    }

    /**
     * Send a templated email to an arbitrary address.
     *
     * Validates SMTP config completeness, injects standard app-level template
     * params, interpolates the subject line, then builds and sends the email.
     *
     * @param  object        $notification  From Notify::notification().
     * @param  string        $to            Recipient email address.
     * @param  object|false  $smtp          SMTP config with host, port, user, pass,
     *                                      security, name fields.
     * @param  array         $attachments   Optional attachment definitions passed
     *                                      through to `Email`.
     * @return bool  True if the email was populated and sent; false otherwise.
     */
    public static function sendEmail($notification, $to, $smtp = false, $attachments = []) {
        if(empty($smtp->host) || empty($smtp->port) || empty($smtp->user) || empty($smtp->pass) || empty($smtp->security)) {
            return false;
        }

        $template = $notification->slug;
        $subject = $notification->subject;
        $params = ! empty($notification->params) ? $notification->params : [];
        $config = static::getApp()->get('config');
        $url = URL::getCurrentUrlWithRefs();

        // Inject standard app-level template variables.
        $params['app.name'] = $config->getProperty('name');
        $baseUrl = static::resolvePublicBaseUrl($url, $config);
        $logoUrl = str_replace('[{url.base}]', $baseUrl, $config->getProperty('logoUrl') ?? '');
        if(!empty($logoUrl) && strpos($logoUrl, 'http') === false) { $logoUrl = rtrim($baseUrl, '/') . '/' . ltrim($logoUrl, '/'); }
        $params['app.logo'] = $logoUrl;
        $iconUrl = str_replace('[{url.base}]', $baseUrl, $config->getProperty('iconUrl') ?? '');
        if(!empty($iconUrl) && strpos($iconUrl, 'http') === false) { $iconUrl = rtrim($baseUrl, '/') . '/' . ltrim($iconUrl, '/'); }
        $params['app.icon'] = $iconUrl;
        $params['app.base_url'] = $baseUrl;
        $params['app.root'] = $config->getProperty('primaryDomain');
        $params['app.supportEmailAddress'] = $config->getProperty('supportEmailAddress');

        // Interpolate %%key%% placeholders in the subject line. Plain
        // replacement -- str_replace takes the value literally, so escaping
        // it would put stray backslashes in the subject.
        foreach($params as $key => $value) {
            $subject = str_replace('%%' . $key . '%%', $value, $subject);
        }

        $email = new Email($smtp, (object)[
            'from' => [
                $smtp->user,
                $smtp->name
            ],
            'to' => [
                $to
            ],
            'subject' => $subject,
            'attachments' => $attachments
        ]);

        $populated = $email->setBodyFromTemplate($template, $params);
        if($populated && $email->send()) {
            return true;
        }

        return false;
    }

    /**
     * Resolve the public-facing base URL for use in email templates.
     *
     * In CLI/cron context HTTP_HOST is absent and getBaseUrl() returns a localhost
     * URL that email clients cannot reach. Fall back to primaryDomain from config.
     *
     * @param  object $url    URL instance from getCurrentUrlWithRefs().
     * @param  object $config ConfigReader instance.
     * @return string         Absolute base URL ending with '/'.
     */
    protected static function resolvePublicBaseUrl($url, $config): string {
        $baseUrl = $url->getBaseUrl();
        if(empty($baseUrl) || strpos($baseUrl, 'localhost') !== false) {
            $domain = $config->getProperty('primaryDomain');
            if(!empty($domain)) {
                $baseUrl = 'https://' . rtrim($domain, '/') . '/';
            }
        }
        return $baseUrl;
    }

    /**
     * Return a generic "unknown error" response object.
     *
     * Used as a catch-all when `Email::send()` returns a falsy value without
     * providing a specific error.
     *
     * @return object  Error object with type, code 9000, and user-friendly message.
     */
    protected static function getUnknownError() {
        return (object)[
            'errors' => [
                (object)[
                    'type' => 'generic',
                    'code' => 9000,
                    'message' => 'An unknown error occurred, please try again later!  If problems persist contact support.'
                ]
            ]
        ];
    }

}
