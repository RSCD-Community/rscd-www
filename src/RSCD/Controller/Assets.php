<?php

namespace RSCD\Controller;

use RSCD\Model\Object\File;
use RSCD\View\ShopView;

/**
 * Serves stored asset files at the /assets/ URL prefix.
 *
 * Requests arriving at /assets/some/path/file.ext are handled here.  The
 * controller strips the leading "/assets/" segment from the URI, URL-decodes
 * the remainder, resolves it inside the local file store (File::storageRoot()),
 * detects its MIME type, and streams the body to the browser with appropriate
 * Content-Type and caching headers.
 *
 * Security: the resolved path must stay inside the storage root (realpath
 * containment), so encoded "../" sequences cannot escape it. Dotfiles are
 * never served.
 */
class Assets extends \RSCD\Controller\ObjectController {

    /**
     * Initialise the controller, setting up a ShopView instance.
     *
     * A view object is required by the parent controller framework even though
     * this controller streams binary output directly rather than rendering HTML.
     */
    public function initialize() {
        $this->set('view', new ShopView($this->get('app')));
    }

    /**
     * Handle all requests to /assets/* by streaming the corresponding file.
     *
     * Strips the leading "/assets" prefix (7 characters) from the request URI,
     * strips any trailing slash, URL-decodes the remaining path, and resolves
     * it inside the local storage root.  On success, delegates to output() to
     * send the file.  On failure (missing, traversal attempt, dotfile),
     * returns a 404 response.
     *
     * @param object $state Application state (includes url helper and other context).
     * @return mixed        File stream output, a redirect, or a 404 error response.
     */
    public function processDefaultAction($state) {
        // Strip the "/assets" prefix (7 chars) from the full request URI.
        $uri = substr($state->url->get('uri'), 7);

        // Remove a trailing slash that may be appended by some routing configurations.
        if(substr($uri, -1) === '/') {
            $uri = substr($uri, 0, -1);
        }

        // If nothing meaningful remains, redirect to the site root.
        if(empty($uri)) {
            return $this->app->redirect($state->url->getBaseUrl());
        }

        // URL-decode the path segment so percent-encoded characters resolve correctly.
        $target = ltrim(rawurldecode($uri), '/');

        // Never serve dotfiles or paths with a dot-segment anywhere in them.
        foreach(explode('/', $target) as $segment) {
            if($segment === '' || $segment[0] === '.') {
                return $this->error404();
            }
        }

        $root = realpath(File::storageRoot());
        $path = !empty($root) ? realpath($root . DIRECTORY_SEPARATOR . $target) : false;

        // realpath() containment: the resolved file must live under the root.
        if(empty($path) || strpos($path, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
            return $this->error404();
        }

        $file = (object)[
            'path' => $path,
            'size' => filesize($path),
            'type' => (string)(mime_content_type($path) ?: 'application/octet-stream'),
            'name' => basename($path)
        ];
        return $this->output($file);
    }

    /**
     * Send a stored file to the browser with appropriate Content-Type and cache headers.
     *
     * MIME-type routing:
     *   - Non-media types (not image/audio/video/text/zip): force download via
     *     Content-Disposition: attachment and application/octet-stream.
     *   - ZIP archives: Content-Type: application/zip (inline, no forced download).
     *   - All other recognised types (image, audio, video, text): serve inline.
     *
     * A 1-year (31,536,000 s) cache lifetime is set for all successfully served assets.
     *
     * @param object $file File descriptor with path, size, type, and name.
     */
    protected function output($file) {
        if(strpos($file->type, 'zip') === false && strpos($file->type, 'image') === false && strpos($file->type, 'audio') === false  && strpos($file->type, 'video') === false  && strpos($file->type, 'text') === false ) {
            // Unknown/binary type — force a file download rather than inline display.
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename=' . $file->name);
            header('Content-Transfer-Encoding: binary');
        }
        else if(strpos($file->type, 'zip') !== false) {
            header('Content-Type: application/zip');
        }
        else {
            // Images, audio, video, text — display inline in the browser.
            header('Content-Type: ' . $file->type);
            header('Content-Disposition: inline; filename=' . $file->name);
        }
        header('Content-Length: ' . $file->size);
        header('Connection: Keep-Alive');
        // 1-year cache (31,536,000 seconds) for static assets.
        header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + 31536000));
        header('Cache-Control: public, max-age=31536000');
        header('Pragma: public');

        readfile($file->path);
    }

    /**
     * Emit a plain-text 404 response.
     */
    protected function error404() {
        http_response_code(404);
        print '404 Not Found';
    }

}
