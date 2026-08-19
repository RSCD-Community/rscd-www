<?php

namespace RSCD\Controller\Admin;

use RSCD\Model\Object\Event;
use RSCD\Util\Dates;
use RSCD\View\AdminView;

/**
 * Admin controller for Event listings.
 *
 * Provides a paginated, searchable, filterable JSON listing of events
 * via the standard ObjectController pattern.
 */
class Events extends \RSCD\Controller\Admin\Common\ObjectController {

    /**
     * Bootstrap auth — required so isAllowed() has a populated condition list.
     *
     * @return void
     */
    public function initialize() {
        $this->set('view', new AdminView($this->get('app')));
        $this->authorizeOrRedirect();
    }

    /**
     * @return string[]
     */
    public static function __conditions() {
        return [
            '_AdminConsole_Event_List'
        ];
    }

    /**
     * Default action: show the event listing page.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    public function processDefaultAction($state) {
        $state->request->action = 'list';
        $state->request->method = 'GET';
        return $this->httpGetList($state);
    }

    /**
     * Renders the server-side event listing page (the audit log).
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpGetList($state) {
        $this->isAllowedOrRedirect('_AdminConsole_Event_List');
        $typeNames = [
            Event::TYPE_NOTICE => 'Notice',
            Event::TYPE_ERROR => 'Error',
            Event::TYPE_WARNING => 'Warning'
        ];
        $severityNames = [
            Event::SEVERITY_NEGLIGIBLE => 'Negligible',
            Event::SEVERITY_MARGINAL => 'Marginal',
            Event::SEVERITY_CRITICAL => 'Critical',
            Event::SEVERITY_CATASTROPHIC => 'Catastrophic'
        ];
        $this->renderListingPage($state, [
            'class' => Event::class,
            'title' => 'Events',
            'baseUrl' => 'admin/events/list/',
            'defaultSort' => 'created_at',
            'columns' => [
                ['label' => 'Type', 'sort' => 'type', 'render' => function($event) use ($typeNames) {
                    return htmlspecialchars($typeNames[(int)$event->type] ?? (string)$event->type, ENT_QUOTES);
                }],
                ['label' => 'Severity', 'sort' => 'severity', 'render' => function($event) use ($severityNames) {
                    return htmlspecialchars($severityNames[(int)$event->severity] ?? (string)$event->severity, ENT_QUOTES);
                }],
                ['label' => 'Message', 'sort' => null, 'render' => function($event) {
                    return htmlspecialchars((string)$event->message, ENT_QUOTES);
                }],
                ['label' => 'When', 'sort' => 'created_at', 'render' => function($event) {
                    return Dates::display($event->created_at ? $event->created_at->getTimestamp() : 0, 'j M Y H:i');
                }]
            ]
        ]);
    }

    /**
     * JSON endpoint: returns a paginated, sorted, filtered event listing.
     *
     * @param mixed $state Application state object.
     * @return void Outputs JSON.
     */
    protected function httpPostList($state) {
        $response = $this->getBlankResponse();
        try {
            if(!$this->isAllowed('_AdminConsole_Event_List')) {
                throw new \Exception('403 - Forbidden');
            }

            $data = (object)$this->getPostData([
                'filters' => FILTER_UNSAFE_RAW,
                'query' => FILTER_SANITIZE_STRING,
                'page' => FILTER_SANITIZE_NUMBER_INT,
                'limit' => FILTER_SANITIZE_NUMBER_INT,
                'sort' => FILTER_SANITIZE_STRING,
                'mode' => FILTER_SANITIZE_STRING,
            ]);

            $filters = !empty($data->filters) ? json_decode($data->filters, true) : [];
            $query = !empty($data->query) ? $data->query : '';
            $page = !empty($data->page) ? (int)$data->page : 1;
            $limit = !empty($data->limit) ? min((int)$data->limit, 100) : 25;
            $sort = !empty($data->sort) ? $data->sort : 'created_at';
            $mode = !empty($data->mode) ? $data->mode : 'desc';
            if($page < 1) { $page = 1; }
            if($limit < 1) { $limit = 25; }

            $listing = static::getListing(Event::class, $query, $filters, ($page - 1) * $limit, $limit, $sort, $mode, [], $state->defaultTimeZone->tzdata_id);
            $listing['total_pages'] = $listing['total_models'] > 0 ? ceil($listing['total_models'] / $limit) : 1;

            $response->listing = $listing;
        }
        catch (\Exception $e) {
            $response->errors[] = $this->getError($e);
        }
        return $this->showJson($response);
    }
}
