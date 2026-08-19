<?php

namespace RSCD\View;

use RSCD\View\Common\PageView;

/**
 * Shop (customer-facing) view — assembles public storefront HTML pages.
 *
 * Extends PageView with shop-specific layout files:
 * - page-header[-active].html — public nav; "-active" variant when logged in.
 *   Injects the active user id and an "Admin console" link for users whose
 *   policies allow it.
 * - page-footer.html — public footer with scripts and closing tags.
 *
 * setPage() concatenates the header, the page body HTML, and the footer, then
 * delegates to PageView::setPage() which injects all global template variables.
 */
class ShopView extends PageView {

    /**
     * Load the shop page header. Uses the "-active" variant when the user is
     * logged in. Also injects domain, active_user, and admin console link directly
     * into the header layout (before the full variable injection pass in PageView).
     *
     * @return \RSCD\Model\ViewLayout
     */
    protected function getPageHeader() {
        $state = $this->getState();
        $pageHeader = $this->getViewLayout('page-header' . (!empty($state->activeUser->id) ? '-active' : '') . '.html');
        $pageHeader->populateHtmlFromFile();
        $adminConsoleLink = '';
        // The link is permission-driven: it appears only for users whose role
        // policies allow the admin console (the console itself re-checks).
        if(!empty($state->activeUser->id) && in_array('_AdminConsole_View', \RSCD\Controller\RuleManager::getAllowedConditions())) {
            $adminConsoleLink .= ' - <a href="[{url.base}]admin/">Admin console</a>';
        }

        $pageHeader->injectHtml('active_user.id', !empty($state->activeUser->id) ? $state->activeUser->id : -1);
        $pageHeader->injectHtml('adminConsoleLink', $adminConsoleLink);
        return $pageHeader;
    }
    
    /**
     * Load the shop page footer layout from page-footer.html.
     *
     * @return \RSCD\Model\ViewLayout
     */
    protected function getPageFooter() {
        $pageFooter = $this->getViewLayout('page-footer.html');
        $pageFooter->populateHtmlFromFile();
        return $pageFooter;
    }

    /**
     * Assemble a complete shop page and delegate to PageView::setPage().
     *
     * Concatenates the rendered header HTML, the page body $html, and the
     * footer HTML, then passes the combined string to the parent for global
     * variable injection and content storage.
     *
     * @param  string $html         Raw page body HTML from the controller.
     * @param  array  $variables    Template variables from the controller.
     * @param  string $name         Page title prefix.
     * @param  string $description  Meta description prefix.
     * @param  string $keywords     Meta keywords prefix.
     * @return static
     */
    public function setPage($html = '', $variables = [], $name = '', $description = '', $keywords = '') {
        $header = $this->getPageHeader();
        $footer = $this->getPageFooter();
        $pageHtml = $header->get('html') . $html . $footer->get('html');
        return parent::setPage($pageHtml, $variables, $name, $description, $keywords);
    }

    /**
     * Render a standalone page without the shop header or footer.
     *
     * Delegates directly to PageView::setPage() so the page-wrapper (CSS, JS,
     * meta tags) is still included, but no navigation chrome is injected.
     *
     * @param  string $html         Raw page body HTML.
     * @param  array  $variables    Template variables.
     * @param  string $name         Page title prefix.
     * @param  string $description  Meta description.
     * @param  string $keywords     Meta keywords.
     * @return static
     */
    public function setStandalonePage($html = '', $variables = [], $name = '', $description = '', $keywords = '') {
        return parent::setPage($html, $variables, $name, $description, $keywords);
    }

}
