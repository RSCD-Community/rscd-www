<?php

namespace RSCD\Controller\Admin;

use RSCD\Model\Object\File;
use RSCD\Util\Dates;
use RSCD\Util\Strings;
use RSCD\View\AdminView;

/**
 * Admin controller for File listings.
 *
 * Provides a paginated, searchable, filterable JSON listing of file records
 * via the standard ObjectController pattern.
 */
class Files extends \RSCD\Controller\Admin\Common\ObjectController {

    /**
     * @return string[]
     */
    public static function __conditions() {
        return [
            '_AdminConsole_File_List'
        ];
    }

    /**
     * Bootstraps the controller with an AdminView and enforces authentication.
     *
     * @return void
     */
    public function initialize() {
        $this->set('view', new AdminView($this->get('app')));
        $this->authorizeOrRedirect();
    }

    /**
     * Default action: show the file listing page.
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
     * Renders the server-side file listing page.
     *
     * @param mixed $state Application state object.
     * @return void
     */
    protected function httpGetList($state) {
        $this->isAllowedOrRedirect('_AdminConsole_File_List');
        $this->renderListingPage($state, [
            'class' => File::class,
            'title' => 'Files',
            'baseUrl' => 'admin/files/list/',
            'defaultSort' => 'created_at',
            'load' => ['user'],
            'columns' => [
                ['label' => 'Name', 'sort' => 'name', 'render' => function($file) {
                    return Strings::displayText((string)$file->name);
                }],
                ['label' => 'Type', 'sort' => 'mimetype', 'render' => function($file) {
                    return htmlspecialchars((string)$file->mimetype, ENT_QUOTES);
                }],
                ['label' => 'Size', 'sort' => 'size', 'render' => function($file) {
                    $size = (int)$file->size;
                    if($size >= 1048576) {
                        return round($size / 1048576, 1) . ' MB';
                    }
                    if($size >= 1024) {
                        return round($size / 1024, 1) . ' KB';
                    }
                    return $size . ' B';
                }],
                ['label' => 'Owner', 'sort' => null, 'render' => function($file) {
                    return Strings::displayText((string)($file->user->name ?? ''));
                }],
                ['label' => 'Uploaded', 'sort' => 'created_at', 'render' => function($file) {
                    return Dates::display($file->created_at ? $file->created_at->getTimestamp() : 0, 'j M Y H:i');
                }]
            ]
        ]);
    }

    /**
     * JSON endpoint: returns a paginated, sorted, filtered file listing.
     *
     * @param mixed $state Application state object.
     * @return void Outputs JSON.
     */
    protected function httpPostList($state) {
        $response = $this->getBlankResponse();
        try {
            if(!$this->isAllowed('_AdminConsole_File_List')) {
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
            $sort = !empty($data->sort) ? $data->sort : 'updated_at';
            $mode = !empty($data->mode) ? $data->mode : 'desc';
            if($page < 1) { $page = 1; }
            if($limit < 1) { $limit = 25; }

            $listing = static::getListing(File::class, $query, $filters, ($page - 1) * $limit, $limit, $sort, $mode, [], $state->defaultTimeZone->tzdata_id);
            if($listing['queried_models'] instanceof \Illuminate\Database\Eloquent\Collection) {
                $listing['queried_models']->load(['user']);
            }
            $listing['total_pages'] = $listing['total_models'] > 0 ? ceil($listing['total_models'] / $limit) : 1;

            $response->listing = $listing;
        }
        catch (\Exception $e) {
            $response->errors[] = $this->getError($e);
        }
        return $this->showJson($response);
    }
}
