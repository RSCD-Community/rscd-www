<?php

namespace RSCD\Controller\Admin;

use RSCD\Model\Object\Event;
use RSCD\Model\Object\Session;
use RSCD\Model\Object\User;
use RSCD\Util\Dates;
use RSCD\View\AdminView;

/**
 * Admin console entry point.
 *
 * Renders the server-side dashboard (user/session counts and the most recent
 * events). Object management lives in the sibling controllers (Users, Roles,
 * AccessPolicies, Files, Events, Tags).
 */
class DefaultController extends \RSCD\Controller\Admin\Common\ObjectController {

    /**
     * Returns the access condition slugs for the DefaultController module.
     *
     * @return string[]
     */
    public static function __conditions() {
        return [
            '_AdminConsole_View',
            '_AdminConsole_Dashboard_View'
        ];
    }

    /**
     * Bootstraps the controller: sets the AdminView, enforces authentication,
     * and requires _AdminConsole_View.
     *
     * @return void
     */
    public function initialize() {
        $this->set('view' , new AdminView($this->get('app')));
        $this->authorizeOrRedirect();
        $this->isAllowedOrRedirect('_AdminConsole_View');
    }

    /**
     * Redirects the default URL to the dashboard page.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    public function processDefaultAction($state) {
        $state->request->action = 'dashboard';
        $state->request->method = 'GET';
        return $this->httpGetDashboard($state);
    }

    /* GET Request Handlers */

    /**
     * Renders the admin dashboard with server-side stats: registered user
     * totals broken down by status, active session count for a rough
     * "signed in now" figure, and the most recent events.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpGetDashboard($state) {
        $this->isAllowedOrRedirect('_AdminConsole_Dashboard_View');
        $view = $this->get('view');
        $base = htmlspecialchars($state->url->getBaseUrl(), ENT_QUOTES);

        $stats = [
            'Registered users' => User::count(),
            'Active users' => User::where('status', User::STATUS_ACTIVE)->count(),
            'Pending confirmation' => User::where('status', User::STATUS_PENDING_CONFIRMATION)->count(),
            'Active sessions' => Session::where('status', Session::STATUS_ACTIVE)->count()
        ];
        $cards = '';
        foreach($stats as $label => $count) {
            $cards .= '<div class="stat-card"><span class="stat-value">' . (int)$count . '</span>'
                    . '<span class="stat-label">' . htmlspecialchars($label, ENT_QUOTES) . '</span></div>';
        }

        $typeNames = [
            Event::TYPE_NOTICE => 'Notice',
            Event::TYPE_ERROR => 'Error',
            Event::TYPE_WARNING => 'Warning'
        ];
        $rows = '';
        foreach(Event::orderBy('created_at', 'desc')->limit(10)->get() as $event) {
            $rows .= '<tr>'
                   . '<td>' . htmlspecialchars($typeNames[(int)$event->type] ?? (string)$event->type, ENT_QUOTES) . '</td>'
                   . '<td>' . htmlspecialchars((string)$event->message, ENT_QUOTES) . '</td>'
                   . '<td>' . Dates::display($event->created_at ? $event->created_at->getTimestamp() : 0, 'j M Y H:i') . '</td>'
                   . '</tr>';
        }
        if($rows === '') {
            $rows = '<tr><td colspan="3" class="listing-empty">No events recorded.</td></tr>';
        }
        $events = '<table class="listing-table"><thead><tr><th>Type</th><th>Message</th><th>When</th></tr></thead>'
                . '<tbody>' . $rows . '</tbody></table>'
                . '<p><a href="' . $base . 'admin/events/list/">All events</a></p>';

        $home = $view->getViewLayout('admin' . DIRECTORY_SEPARATOR . 'dashboard.html');
        $home->populateHtmlFromFile();
        $home->injectHtml('stat_cards', $cards);
        $home->injectHtml('recent_events', $events);
        $view->setPage($home->get('html') , [], 'Dashboard');
    }

}
