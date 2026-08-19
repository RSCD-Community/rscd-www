<?php

namespace RSCD\View\Common;

use RSCD\Model\Object\User;
use RSCD\Controller\RuleManager;
use RSCD\Util\Arrays;

/**
 * Admin page view — assembles admin-section HTML pages.
 *
 * Extends PageView with admin-specific layout files:
 * - admin/admin-page-wrapper.html — outer shell (<!DOCTYPE>, <html>, <head>, <body>)
 * - admin/admin-page-header[-active].html — nav bar; "-active" variant is used when
 *   a user is logged in (contains user-specific nav links)
 * - admin/admin-page-footer.html — scripts and closing tags
 *
 * setPage() enriches the $variables map with the active user object
 * (serialized as JSON in active_user and flattened as active_user.* keys),
 * parent-user feature flags (check_payment_enabled, gang_pricing_enabled, etc.),
 * session data, current date (yyyy-mm-dd), is_live flag, and URL variables from
 * the router. All variables are injected into the page wrapper HTML via
 * injectHtml() in PageView::setPage().
 */
class AdminPageView extends PageView {

    /**
     * Load the admin page wrapper layout from admin/admin-page-wrapper.html.
     *
     * @return \RSCD\Model\ViewLayout
     */
    protected function getPageWrapper() {
        $pageWrapper = $this->getViewLayout( 'admin' . DIRECTORY_SEPARATOR . 'admin-page-wrapper.html' );
        $pageWrapper->populateHtmlFromFile();
        return $pageWrapper;
    }
    
    /**
     * Load the admin page header layout. Uses the "-active" variant when the
     * active user has a valid session (i.e. is logged in).
     *
     * @return \RSCD\Model\ViewLayout
     */
    protected function getPageHeader() {
        $state = $this->getState();
        $pageHeader = $this->getViewLayout( 'admin' . DIRECTORY_SEPARATOR . 'admin-page-header' . ( ! empty( $state->activeUser->id ) ? '-active' : '' ) . '.html' );
        $pageHeader->populateHtmlFromFile();
        return $pageHeader;
    }

    /**
     * Load the admin page footer layout from admin/admin-page-footer.html.
     *
     * @return \RSCD\Model\ViewLayout
     */
    protected function getPageFooter() {
        $pageFooter = $this->getViewLayout( 'admin' . DIRECTORY_SEPARATOR . 'admin-page-footer.html' );
        $pageFooter->populateHtmlFromFile();
        return $pageFooter;
    }

    /**
     * Assemble a complete admin page and pass it to PageView::setPage().
     *
     * Injects the following template variables before delegating:
     * - active_user (JSON) — full user object or "{}" for guests
     * - active_user.* — flattened user properties
     * - active_user.allowed_conditions — JSON array from RuleManager
     * - active_user.session.* — flattened session properties
     * - yyyy-mm-dd — today's date in the app timezone
     * - is_live — "true" or "false" string
     * - URL variables decoded from the router
     *
     * @param  string $html         Raw page body HTML.
     * @param  array  $variables    Additional template variables from the controller.
     * @param  string $name         Page title prefix.
     * @param  string $description  Meta description prefix.
     * @param  string $keywords     Meta keywords prefix.
     * @return static
     */
    public function setPage($html = '', $variables = [], $name = '', $description = '', $keywords = '') {
        $state = $this->getState();

        if(!empty($state->activeUser->id)) {
            $state->activeUser->load(['contacts']);
            $activeUserMap = User::toObject($state->activeUser);
            $activeUserMap->allowed_conditions = json_encode(RuleManager::getAllowedConditions());

            $variables['active_user'] = json_encode($activeUserMap);
            $variables = Arrays::mergeAndFlatten($variables, $activeUserMap, '%s.%s', 'active_user', false);
        }
        else {
            $variables['active_user'] = '{}';
            $variables['active_user.allowed_conditions'] = '[]';
            $variables['active_user.condition_list'] = '[]';
            $variables['active_user.id'] = '';
        }
        
        if(!empty($state->activeUser->session->id)) {
            $variables = Arrays::mergeAndFlatten($variables, json_decode(json_encode($state->activeUser->session)), '%s.%s', 'active_user.session', false);
        }
        
        $dateTime = new \DateTime();
        $dateTime->setTimezone(new \DateTimeZone($state->defaultTimeZone->tzdata_id));
        
        $variables['yyyy-mm-dd'] = $dateTime->format('Y-m-d');
        $variables['is_live'] = __LIVE__ === true ? 'true' : 'false';
        $vars = $state->url->get('variables');
        foreach($vars as $key => $value) {
            $variables[$key] = rawurldecode($value);
        }
        $header = $this->getPageHeader();
        $footer = $this->getPageFooter();
        $pageHtml = $header->get('html') . $html . $footer->get('html');
        return parent::setPage($pageHtml, $variables, $name, $description, $keywords);
    }
  
}
