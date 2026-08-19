<?php

namespace RSCD\View\Common;

use RSCD\Model\App;
use RSCD\Model\Object\User;
use RSCD\Model\State;
use RSCD\Model\ViewLayout;
use RSCD\View\Common\View;
use RSCD\Controller\RuleManager;
use RSCD\Util\Arrays;
use RSCD\Util\Strings;

/**
 * Base page view — assembles a complete HTML page from layout parts.
 *
 * Template variable injection pattern:
 * 1. Controllers call $view->setPage($html, $variables, ...) with page-specific vars.
 * 2. setPage() builds global vars (active_user, app.*, gtag, production_settings.*,
 *    URL variables) and merges them with the caller's $variables.
 * 3. All variables are injected into the page wrapper HTML via
 *    $pageWrapper->injectHtml($key, $value) using [{key}] placeholder syntax.
 * 4. The assembled HTML is stored via $this->set('content', ...) for output.
 *
 * The page wrapper (page-wrapper.html) is the outermost HTML shell.
 * Header and footer are assembled separately by subclasses (ShopView, AdminPageView)
 * and concatenated with the page body before delegating to parent::setPage().
 *
 * Variable injection for users:
 * - If logged in: active_user = JSON(User::toObject()), plus flattened
 *   active_user.* keys via Arrays::mergeAndFlatten().
 * - If guest: active_user = '{}' with safe empty defaults.
 */
class PageView extends View {

    /**
     * Assemble a complete page and store it as the view content.
     *
     * Injects global template variables into the page wrapper, including:
     * - title, description, keywords (prefixed with page-specific values)
     * - active_user (JSON) and active_user.* flattened keys
     * - root_user.id — top-level ancestor user ID (-1 for guests)
     * - active_user.is_shop_as — 1 if admin is browsing as a customer
     * - gtag — Google Analytics script tag (live only, and only when gtagId is configured)
     * - app.version, app.icon, app.logo, app.name, app.short_name, app.timezone
     * - app.assets — stylesheet cache-busting stamp (see assetVersion())
     * - domain.* — all columns from the Domain record (root, api, webhook, name, etc.)
     * - url.base, url.domain, url.protocol, url.method, url.uri — current request URL parts
     * - URL variables from the router (rawurldecoded)
     * - is_live — "true" or "false" string
     *
     * @param  string $html         Raw page body HTML (already includes header/footer from subclass).
     * @param  array  $variables    Template variables from the controller / subclass.
     * @param  string $name         Page title prefix (appended with app title).
     * @param  string $description  Meta description prefix (appended with app description).
     * @param  string $keywords     Meta keywords prefix (appended with app keywords).
     * @return static
     */
    public function setPage($html = '', $variables = [], $name = '', $description = '', $keywords = '') {
        $state = $this->getState();
        $title = (!empty($name) ? $name . ' - ' : '') . $this->app->get('config')->getProperty('title');
        // Use the page-specific description or meta text alone — do not append the
        // app-level description as a suffix, which creates redundant 200+ char strings.
        $description = !empty($description) ? $description : $this->app->get('config')->getProperty('description');
        $keywords    = !empty($keywords)    ? $keywords    : $this->app->get('config')->getProperty('keywords');

        // Build the canonical URL from the current request (strip query string so
        // paginated/filtered variants don't compete with the canonical page).
        $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host      = $_SERVER['HTTP_HOST'] ?? '';
        $path      = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        $canonical = $protocol . '://' . $host . $path;

        // Default OG/Twitter share image — individual pages may override via $variables['og_image'].
        $defaultOgImage = $this->app->get('config')->getProperty('logoUrl') ?? '';

        $pageWrapper = $this->getPageWrapper();
        $pageWrapper->injectHtml('is_live', __LIVE__ === true ? 'true' : 'false');
        // Page names can carry user-supplied text (a forum topic's title, for
        // one), and these land in <title> and meta/og attributes — escape here
        // so no caller can forget to. displayText rather than htmlspecialchars
        // because a title is injected before the app/user variables and must
        // not be able to open a [{...}] token for that later pass to fill.
        $pageWrapper->injectHtml('title', Strings::displayText($title));
        $pageWrapper->injectHtml('description', Strings::displayText($description));
        $pageWrapper->injectHtml('keywords', Strings::displayText($keywords));
        $pageWrapper->injectHtml('canonical', $canonical);
        $pageWrapper->injectHtml('og_image', $defaultOgImage);
        $pageWrapper->injectHtml('jsonld', '');
        $pageWrapper->injectHtml('page', $html);
        if(!empty($state->activeUser->id)) {
            $state->activeUser->load(['session','contact','contacts']);
            $activeUserMap = User::toObject($state->activeUser);
            $activeUserMap->allowed_conditions = json_encode(RuleManager::getAllowedConditions());
            // JSON_HEX_* so a name/email containing </script>, quotes, or & can
            // never break out of the <script> block this is embedded in
            // (admin-page-wrapper.html: var activeUser = [{active_user}];).
            $variables['active_user'] = json_encode($activeUserMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
            $variables = Arrays::mergeAndFlatten($variables, $activeUserMap, '%s.%s', 'active_user', false);
            // The flattened active_user.* keys (unlike the JSON blob above) are
            // injected as raw HTML by ViewLayout -- injectHtml() does a plain
            // string substitution with no escaping of its own. active_user.name
            // is a user-supplied display name rendered directly into the admin
            // header (admin-page-header-active.html), so it needs the same
            // displayText() treatment as title/description/keywords above.
            $variables['active_user.name'] = Strings::displayText((string)($activeUserMap->name ?? ''));

            $variables['root_user.id'] = $state->activeUser->getRoot()->id;
        }
        else {
            $variables['active_user'] = '{}';
            $variables['active_user.allowed_conditions'] = '[]';
            $variables['active_user.id'] = '';
            $variables['root_user.id'] = '-1';
        }
        
        if(!empty($state->activeUser->session->id)) {
            $variables = Arrays::mergeAndFlatten($variables, json_decode(json_encode($state->activeUser->session)), '%s.%s', 'active_user.session', false);
        }
        
        $variables['active_user.is_shop_as'] = empty($state->activeUser->session->is_shop_as) ? 0 : 1;
        // Analytics only render when a measurement ID is configured (gtagId in
        // app.json) — nothing is hardcoded and nothing loads by default.
        $gtagId = $state->config->getProperty('gtagId');
        $variables['gtag'] = (__LIVE__ && !empty($gtagId)) ? '
            <script async src="https://www.googletagmanager.com/gtag/js?id=' . htmlspecialchars($gtagId, ENT_QUOTES) . '"></script>
            <script>
              window.dataLayer = window.dataLayer || [];
              function gtag(){dataLayer.push(arguments);}
              gtag("js", new Date());

              gtag("config", "' . htmlspecialchars($gtagId, ENT_QUOTES) . '");
            </script>' : '';
        // The staging banner is rendered server-side — the public pages carry
        // no JavaScript, so the wrapper cannot make this decision client-side.
        $variables['staging_warning'] = __LIVE__ ? ''
            : '<div class="staging-warning"><b>YOU ARE CONNECTED TO THE STAGING SERVER</b></div>';
        $variables['app.version'] = __LIVE__ ? App::VERSION : App::TEST_VERSION . '&s=' . random_int(1000000000, 9999999999);
        // Separate from app.version on purpose. app.version says which release
        // this is and belongs in the meta tag; app.assets exists only to make a
        // browser re-fetch a stylesheet that changed. Tying the second to the
        // first meant that once live, every CSS fix after 1.0.0 was invisible
        // to anyone who had already loaded the site -- the URL never changed,
        // so the cached copy stood. See assetVersion().
        $variables['app.assets'] = __LIVE__ ? self::assetVersion() : random_int(1000000000, 9999999999);
        $variables['app.icon'] = $state->config->getProperty('iconUrl');
        $variables['app.logo'] = $state->config->getProperty('logoUrl');
        $variables['app.name'] = $state->config->getProperty('name');
        $variables['app.short_name'] = $state->config->getProperty('shortName');
        $variables['app.timezone'] = !empty($state->defaultTimeZone->tzdata_id) ? $state->defaultTimeZone->tzdata_id : '';

        if(!empty($state->domain)) {
            // The domain descriptor is a plain object built by State::getDomain().
            $variables = Arrays::mergeAndFlatten($variables, (array)$state->domain, '%s.%s', 'domain', false);
        }

        $variables = Arrays::mergeAndFlatten($variables, [
            'base'     => $state->url->getBaseUrl(),
            'domain'   => $state->url->get('domain'),
            'protocol' => $state->url->get('protocol'),
            'method'   => $state->url->get('method'),
            'uri'      => $state->url->get('uri'),
        ], '%s.%s', 'url', false);

        $vars = $state->url->get('variables');
        foreach($vars as $key => $value) {
            $variables[$key] = rawurldecode($value);
        }
        foreach($variables as $key => $value) {
            $pageWrapper->injectHtml($key, $value);
        }
        $this->set('content', $pageWrapper->get('html'));
        return $this;
    }
    
    /**
     * Cache-busting stamp for the stylesheets: the release version plus the
     * newest modification time in ui/css.
     *
     * Deploying a stylesheet is then the same action as invalidating the
     * cached copy of it, which is the point — nobody has to remember to bump a
     * constant, and forgetting cannot silently ship a fix that no returning
     * visitor ever sees.
     *
     * One stamp covers every stylesheet, so touching one re-fetches the others
     * too. That costs a few kilobytes on the deploys we actually do, and it
     * saves threading a separate stamp through every template.
     *
     * If ui/css cannot be read the stamp falls back to the release version —
     * the previous behaviour, so a page still renders.
     *
     * @return string
     */
    private static function assetVersion() {
        static $stamp = null;
        if($stamp !== null) {
            return $stamp;
        }

        $newest = 0;
        $pattern = __ROOTS__ . 'ui' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . '*.css';
        foreach(glob($pattern) ?: [] as $file) {
            $mtime = @filemtime($file);
            if($mtime !== false && $mtime > $newest) {
                $newest = $mtime;
            }
        }

        $stamp = $newest > 0 ? App::VERSION . '.' . $newest : App::VERSION;
        return $stamp;
    }

    /**
     * Load the base page wrapper layout from page-wrapper.html.
     * Subclasses override this to use an admin-specific wrapper.
     *
     * @return \RSCD\Model\ViewLayout
     */
    protected function getPageWrapper() {
        $pageWrapper = $this->getViewLayout('page-wrapper.html');
        $pageWrapper->populateHtmlFromFile();
        return $pageWrapper;
    }

    /**
     * Return the current State snapshot.
     *
     * @return \RSCD\Model\State
     */
    protected function getState() {
        return State::get();
    }

    /**
     * Instantiate a ViewLayout for the given HTML file under the HTML directory.
     *
     * @param  string|null $file  Relative path to the layout file within the HTML directory.
     * @return \RSCD\Model\ViewLayout
     */
    public function getViewLayout($file = null) {
        return new ViewLayout((object)[
            'storageDirectory' => (defined('__HTML_DIR__') ? __HTML_DIR__ 
                : CreateView::DEFAULT_STORAGE_DIR),
            'file' => $file
        ]);
    }
    
}
