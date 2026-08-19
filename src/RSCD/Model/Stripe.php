<?php

namespace RSCD\Model;

use RSCD\Util\CURL;

/**
 * Stripe Checkout, spoken to directly over its HTTP API.
 *
 * The site takes exactly one kind of payment — a one-off donation of an
 * amount the donor picks — so this is deliberately not the Stripe SDK. The
 * SDK is a few megabytes and a permanent dependency to make one POST to one
 * endpoint, and the endpoint is a plain form-encoded request that the site's
 * own CURL client already knows how to make.
 *
 * Nothing sensitive touches this server. The donor is redirected to Stripe's
 * own checkout page, enters their card there, and comes back; no card number,
 * expiry or CVC is ever seen by, posted to, or logged on rscd-community.org.
 * The only secret involved is the API key, which lives in app/app.json (mode
 * 640, git-ignored) and is never rendered into a page.
 *
 * Configure it in app.json under "stripe", with the same key names php-href
 * uses so the values are interchangeable:
 *
 *     "stripe": {
 *         "testPublicKey": "pk_test_...",
 *         "testSecretKey": "sk_test_...",
 *         "livePublicKey": "pk_live_...",
 *         "liveSecretKey": "sk_live_..."
 *     }
 *
 * The redirect flow needs only the secret key; the public keys are read by
 * nothing here and are kept in the shape purely so one block of config can be
 * copied between projects. __LIVE__ picks which pair is in force, so a test
 * deployment cannot take a real payment by accident.
 *
 * If no key is configured the donate page says donations are not set up yet
 * and offers no form. That is the shipped default: a fresh clone of this
 * repository asks nobody for money.
 */
class Stripe {

    /** Stripe's API root. Versioned per-request by the header below. */
    const API_BASE = 'https://api.stripe.com/v1/';

    /**
     * The API version this code was written against. Pinning it means Stripe
     * rolling their API forward cannot change the shape of the response out
     * from under us.
     */
    const API_VERSION = '2024-06-20';

    /** Smallest and largest donation, in whole dollars. */
    const MIN_DOLLARS = 1;
    const MAX_DOLLARS = 10000;

    /**
     * Read the secret key for the current environment.
     *
     * @param  object|null $config The "stripe" block from app.json.
     * @return string|null         The key, or null when donations are not configured.
     */
    public static function getSecretKey($config) {
        if(empty($config)) {
            return null;
        }
        $key = __LIVE__ === true
            ? (isset($config->liveSecretKey) ? $config->liveSecretKey : null)
            : (isset($config->testSecretKey) ? $config->testSecretKey : null);
        return !empty($key) ? $key : null;
    }

    /**
     * True when this deployment can actually take a donation.
     *
     * @param  object|null $config The "stripe" block from app.json.
     * @return bool
     */
    public static function isConfigured($config) {
        return static::getSecretKey($config) !== null;
    }

    /**
     * Turn a submitted amount into cents, or throw with a message the donor
     * can act on.
     *
     * @param  mixed $amount Raw input, in dollars.
     * @return int           Amount in cents.
     * @throws \Exception    When the amount is missing or out of range.
     */
    public static function getAmountInCents($amount) {
        $dollars = filter_var($amount, FILTER_VALIDATE_FLOAT);
        if($dollars === false || $dollars < static::MIN_DOLLARS || $dollars > static::MAX_DOLLARS) {
            throw new \Exception('Please enter an amount between $'
                . number_format(static::MIN_DOLLARS) . ' and $'
                . number_format(static::MAX_DOLLARS) . '.');
        }
        return (int)round($dollars * 100);
    }

    /**
     * Create a Checkout session and return the URL to send the donor to.
     *
     * @param  string $secretKey  Stripe secret key.
     * @param  int    $amountCents Amount to charge, in cents.
     * @param  string $name       Line item name shown on Stripe's page.
     * @param  string $description Line item description shown on Stripe's page.
     * @param  string $successUrl Where Stripe returns a donor who paid.
     * @param  string $cancelUrl  Where Stripe returns a donor who backed out.
     * @return string             Absolute URL of the hosted checkout page.
     * @throws \Exception         When Stripe refuses or answers with anything unexpected.
     */
    public static function createCheckoutSession($secretKey, $amountCents, $name, $description, $successUrl, $cancelUrl) {
        $fields = [
            'mode'        => 'payment',
            // Stripe relabels the pay button "Donate" and drops the shipping
            // and tax language from the receipt.
            'submit_type' => 'donate',
            'success_url' => $successUrl,
            'cancel_url'  => $cancelUrl,
            'line_items'  => [
                [
                    'quantity'   => 1,
                    'price_data' => [
                        'currency'     => 'usd',
                        'unit_amount'  => $amountCents,
                        'product_data' => [
                            'name'        => $name,
                            'description' => $description,
                        ],
                    ],
                ],
            ],
        ];

        $curl = (new CURL())
            ->setMethod(CURL::HTTP_POST)
            ->setMode(CURL::MODE_FORMDATA)
            ->setUrl(static::API_BASE . 'checkout/sessions')
            ->setHeaders([
                'Authorization: Bearer ' . $secretKey,
                'Stripe-Version: ' . static::API_VERSION,
            ])
            // A pre-built query string, not an array: an array here would be
            // sent as multipart, which this endpoint does not accept.
            ->setParams(http_build_query($fields))
            ->send();

        $code = $curl->getHttpResponseCode();
        $body = json_decode((string)$curl->getResponse());
        if($code !== 200 || empty($body->url)) {
            // A rejected key is our problem, not the donor's, and Stripe's
            // message for it quotes part of the key back. Everything else is
            // about the request itself and is worth showing verbatim.
            $ours = $code === 401 || $code === 403;
            throw new \Exception(!$ours && !empty($body->error->message)
                ? $body->error->message
                : 'Donations are temporarily unavailable.  Please try again later.');
        }
        return $body->url;
    }

}
