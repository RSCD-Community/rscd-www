<?php

namespace RSCD\Controller;

use RSCD\Model\Stripe;
use RSCD\Util\Strings;
use RSCD\View\ShopView;

/**
 * Donate — the one page on this site that asks for money, and the only one.
 *
 * There is nothing to buy: no membership, no bonds, no cosmetics, and no
 * donation changes anything inside the game. That is a design decision and
 * not a temporary state of affairs, and the page says so plainly, because a
 * preservation project that sells advantage stops being a preservation
 * project. What donations pay for is the hosting bill.
 *
 * The flow is Stripe's hosted Checkout, which means the donor leaves this
 * site to pay and comes back afterwards. No card details are posted to,
 * handled by, or logged on this server at any point — see RSCD\Model\Stripe.
 *
 * Pages:
 *   GET  /donate/            the blurb and the amount form
 *   POST /donate/checkout/   create a Checkout session, redirect to Stripe
 *   GET  /donate/thanks/     Stripe's success_url
 *   GET  /donate/cancelled/  Stripe's cancel_url
 *
 * With no key in app.json the form is not rendered at all and the page says
 * donations are not set up yet, so a fresh clone of this repository cannot
 * accidentally point anyone at somebody else's Stripe account.
 */
class Donate extends \RSCD\Controller\ObjectController {

    /** Buttons offered before the "other amount" box, in dollars. */
    const PRESETS = [5, 10, 25, 50];

    /** Preselected on load. */
    const DEFAULT_AMOUNT = 10;

    /**
     * Initialise with the public-facing view.
     */
    public function initialize() {
        $this->set('view', new ShopView($this->get('app')));
    }

    /**
     * Default action — the donate page.
     *
     * @param object      $state    Application state.
     * @param object|null $response Optional response with errors/messages.
     */
    public function processDefaultAction($state, $response = null) {
        $this->authorize();
        $state = $this->getState();

        $config = $state->config->getProperty('stripe');
        $configured = Stripe::isConfigured($config);

        $page = $state->view->getViewLayout('donate' . DIRECTORY_SEPARATOR . 'index.html');
        $page->populateHtmlFromFile();
        $page->injectHtml('alerts', $this->buildAlertsHtml($state, $response));
        $page->injectHtml('form', $configured ? $this->buildFormHtml($state) : $this->buildUnavailableHtml());
        $state->view->setPage($page->get('html'), [], 'Donate');
    }

    /**
     * Create a Stripe Checkout session and send the donor to it.
     *
     * On any failure the donor lands back on the donate page with the reason,
     * rather than on a stack trace or a blank Stripe error.
     *
     * @param object $state Application state.
     */
    protected function httpPostCheckout($state) {
        $this->authorize();
        $state = $this->getState();
        $response = $this->getBlankResponse();
        try {
            $config = $state->config->getProperty('stripe');
            $secretKey = Stripe::getSecretKey($config);
            if(empty($secretKey)) {
                throw new \Exception('Donations are not set up on this site yet.');
            }

            // "other" means read the free-text box instead of the radio — and
            // so does a filled-in box on its own. Without JavaScript, typing
            // an amount does not move the radio off its preset, and the donor
            // who typed 25 must not be charged the preset 10.
            $amount = filter_input(INPUT_POST, 'amount');
            $otherAmount = trim((string)filter_input(INPUT_POST, 'other_amount'));
            if($amount === 'other' || $otherAmount !== '') {
                $amount = $otherAmount;
            }
            $amountCents = Stripe::getAmountInCents($amount);

            $base = $state->url->getBaseUrl();
            $name = $state->config->getProperty('name');
            $url = Stripe::createCheckoutSession(
                $secretKey,
                $amountCents,
                'Donation to ' . $name,
                'Helps pay for hosting.  Gives no in-game advantage of any kind.',
                $base . 'donate/thanks/',
                $base . 'donate/cancelled/'
            );
            return $state->app->redirect($url);
        }
        catch(\Exception $e) {
            $response->errors[] = $this->getError($e);
        }
        return $this->processDefaultAction($state, $response);
    }

    /**
     * Stripe's success_url. Stripe has taken the payment by the time this is
     * reached; there is nothing to fulfil, so this only says thank you.
     *
     * @param object $state Application state.
     */
    protected function httpGetThanks($state) {
        $this->authorize();
        $state = $this->getState();

        $page = $state->view->getViewLayout('donate' . DIRECTORY_SEPARATOR . 'thanks.html');
        $page->populateHtmlFromFile();
        $state->view->setPage($page->get('html'), [], 'Thank you');
    }

    /**
     * Stripe's cancel_url — the donor closed the checkout page. Nothing was
     * charged, and saying so is the whole point of this action.
     *
     * @param object $state Application state.
     */
    protected function httpGetCancelled($state) {
        $this->authorize();
        $state = $this->getState();
        $response = $this->getBlankResponse();
        $response->messages = ['No payment was taken.  You can close this page, or pick an amount below if you would like to try again.'];
        return $this->processDefaultAction($state, $response);
    }

    /**
     * The amount form: preset radios, an "other" box, and one submit.
     *
     * Deliberately a plain HTML form posting to this site. There is no
     * JavaScript, no Stripe script tag, and no iframe, so the page works with
     * scripting off and nothing on it can read what the donor types.
     *
     * @param  object $state Application state.
     * @return string        HTML.
     */
    protected function buildFormHtml($state) {
        $options = '';
        foreach(static::PRESETS as $dollars) {
            $id = 'amount-' . $dollars;
            $options .= '<label class="donate-option" for="' . $id . '">'
                . '<input type="radio" id="' . $id . '" name="amount" value="' . $dollars . '"'
                . ($dollars === static::DEFAULT_AMOUNT ? ' checked="checked"' : '') . ' /> '
                . '$' . $dollars . '</label>';
        }
        $options .= '<label class="donate-option" for="amount-other">'
            . '<input type="radio" id="amount-other" name="amount" value="other" /> Other</label>';

        return '<form name="donate" action="' . htmlspecialchars($state->url->getBaseUrl(), ENT_QUOTES) . 'donate/checkout/" method="POST">'
            . '<div class="form-group">'
            . '<label>How much would you like to give?</label>'
            . '<div class="donate-options">' . $options . '</div>'
            . '</div>'
            . '<div class="form-group">'
            . '<label for="other-amount">Other amount (US dollars)</label>'
            . '<input class="form-control" type="number" id="other-amount" name="other_amount"'
            . ' min="' . Stripe::MIN_DOLLARS . '" max="' . Stripe::MAX_DOLLARS . '" step="1"'
            . ' placeholder="e.g. 15" />'
            . '</div>'
            . '<div class="form-actions">'
            . '<button class="btn btn-primary" type="submit">Continue to Stripe</button>'
            . '</div>'
            . '<p class="auth-hint">You will be taken to Stripe to pay, then brought back here.</p>'
            . '</form>';
    }

    /**
     * Shown instead of the form when no Stripe key is configured.
     *
     * @return string HTML.
     */
    protected function buildUnavailableHtml() {
        return '<p class="auth-hint">Donations are not set up on this site yet.  Nothing to do here for now &mdash; thank you for looking.</p>';
    }

    /**
     * Render flash parameters and any accumulated response as alert markup.
     *
     * Same shape as the account and forum pages so the notices look identical
     * wherever they appear.
     *
     * @param  object      $state    Application state.
     * @param  object|null $response Optional response with errors/messages.
     * @return string                Alert markup, or an empty string.
     */
    protected function buildAlertsHtml($state, $response = null) {
        $alerts = '';
        $errors   = !empty($response->errors) ? $response->errors : [];
        $messages = !empty($response->messages) ? $response->messages : [];
        if(!empty($errors)) {
            $alerts .= '<div class="alert alert-danger" role="alert">' . Strings::displayText(implode(', ', $errors)) . '</div>';
        }
        if(!empty($messages)) {
            $alerts .= '<div class="alert alert-success" role="alert">' . Strings::displayText(implode(', ', $messages)) . '</div>';
        }
        return $alerts;
    }

}
