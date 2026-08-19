<?php

namespace RSCD\Model;

/**
 * Parses and represents the current HTTP request URL.
 *
 * Stores all URL components (protocol, domain, directory, document, URI,
 * query params, controller/action refs, and variable segments) and exposes
 * helper methods to reconstruct the base URL or the full URL with or without
 * the document segment.
 *
 * The static getCurrentUrl() / getCurrentUrlWithRefs() factory methods read
 * from PHP's $_SERVER superglobal (via INPUT_SERVER) and return a fully
 * populated URL instance.
 */
class URL {
    // constants
    // properties

    protected $port;
    protected $method;
    protected $protocol;
    protected $domain;
    protected $directory;
    protected $document;
    protected $initialUri;
    protected $uri;
    protected $params;
    protected $variables;
    protected $controllerRef;
    protected $actionRef;

    // constructor / destructor

    /**
     * Initialise the URL from a plain object of URL components.
     *
     * @param object|null $object Object with optional port, method, protocol, domain,
     *                            directory, document, initialUri, uri, params, variables,
     *                            controllerRef, and actionRef properties.
     */
    public function __construct($object = null) {
        $this->set('port', isset($object->port) ? $object->port : null);
        $this->set('method', isset($object->method) ? $object->method : null);
        $this->set('protocol', isset($object->protocol) ? $object->protocol : null);
        $this->set('domain', isset($object->domain) ? $object->domain : null);
        $this->set('directory', isset($object->directory) ? $object->directory : null);
        $this->set('document', isset($object->document) ? $object->document : null);
        $this->set('initialUri', isset($object->initialUri) ? $object->initialUri : null);
        $this->set('uri', isset($object->uri) ? $object->uri : null);
        $this->set('params', isset($object->params) ? $object->params : null);
        $this->set('variables', isset($object->variables) ? $object->variables : []);
        $this->set('controllerRef', isset($object->controllerRef) ? $object->controllerRef : null);
        $this->set('actionRef', isset($object->actionRef) ? $object->actionRef : null);

        return $this;
    }

    // public methods

    /**
     * Return the base URL (protocol + domain + port if non-standard + directory).
     *
     * @param  bool $injectDocument Unused; present for interface compatibility.
     * @return string The base URL string.
     */
    public function getBaseUrl($injectDocument = false) {
        $url = (($protocol = $this->get('protocol')) !== null && ! empty($protocol) ? $protocol : 'http') . '://';
        $url .= ($domain = $this->get('domain')) !== null && ! empty($domain) ? $domain : 'localhost';
        if(!in_array($this->get('port'), ['80', '443'])) {
            $url .= ':' . $this->get('port');
        }
        $url .= ($directory = $this->get('directory')) !== null && ! empty($directory) ? $directory : '/';

        return $url;
    }

    /**
     * Return the full URL including the document segment.
     *
     * Convenience alias for getUrl(true).
     *
     * @return string The full URL string with document.
     */
    public function getUrlWithDocument() {
        return $this->getUrl(true);
    }

    /**
     * Return the full request URL, optionally including the document segment.
     *
     * Handles clean URLs (no document in path), suppressed file extensions,
     * controller/action refs, and key=value variable segments.
     *
     * @param  bool $injectDocument When true, includes the document filename in the URL.
     * @return string The constructed URL string.
     */
    public function getUrl($injectDocument = false) {
        $url = (($protocol = $this->get('protocol')) !== null && ! empty($protocol) ? $protocol : 'http') . '://';
        $url .= ($domain = $this->get('domain')) !== null && ! empty($domain) ? $domain : 'localhost';
        $url .= ($directory = $this->get('directory')) !== null && ! empty($directory) ? $directory : '/';

        // check if the url is a clean url

        $cleanUrls = substr(($uri = $this->get('uri')), 0, strlen(($document = $this->get('document')))) != $document;

        // check if url is a suppressed file extension and not a true clean url

        $suppressed = false;

        if($cleanUrls) {
            if(($pos = strpos($document, '.')) !== false && $pos > 0) {
                $baseName = substr($document, 0, $pos);
                $cleanUrls = substr($uri, 0, strlen($baseName)) != $baseName;
                $suppressed = ! $cleanUrls;
            }
        }

        if($this->refsDoExist()) {
            $url .= ($injectDocument && $document !== null ? ($suppressed ? $baseName : $document) . '/' : (! $cleanUrls ? ($suppressed ? $baseName : $document) . '/' : ''));
            $url .= (($controllerRef = $this->get('controllerRef')) !== null && strlen($controllerRef) > 0 ? rawurlencode($controllerRef) . '/' : '');
            $url .= (($actionRef = $this->get('actionRef')) !== null && strlen($actionRef) > 0 ? rawurlencode($actionRef) . '/' : '');
        } else {
            $url .= $injectDocument && $document !== null && $cleanUrls ? ($suppressed ? $baseName : $document) . (! empty($uri) && substr($uri, 0, 1) != '/' ? '/' : '') : '';
            $url .= ($uri !== null && ! empty($uri) ? $uri : '');
        }

        if(($variables = $this->get('variables')) !== null && is_array($variables) && count($variables) > 0) {
            foreach($variables as $key => $value) {
                $url .= rawurlencode($key . '=' . $value) . '/';
            }
        }

        return $url;
    }

    /**
     * Return a single URL variable (key=value segment) by key.
     *
     * @param  string|null $variable The variable key to look up.
     * @return string|null The value, or null if the key is not present.
     */
    public function getVariable($variable = null) {
        $variables = $this->get('variables');

        if(! empty($variable) && ! empty($variables[$variable])) {
            return $variables[$variable];
        }

        return null;
    }

    /**
     * Set a URL variable (key=value segment).
     *
     * @param  string|null $variable The variable key.
     * @param  mixed       $value    The variable value.
     * @return $this
     */
    public function setVariable($variable = null, $value = null) {
        $variables = $this->get('variables');

        if(! empty($variable)) {
            $variables[$variable] = $value;
        }

        $this->set('variables', $variable);

        return $this;;
    }

    /**
     * Return a property value by name.
     *
     * @param  string|null $property Property name.
     * @return mixed The property value, or null if it does not exist.
     */
    public function get($property = null) {
        if(property_exists($this, $property)) {
            return $this->$property;
        }

        return null;
    }

    /**
     * Set a property value by name.
     *
     * @param  string|null $property Property name.
     * @param  mixed       $value    Value to assign.
     * @return $this
     */
    public function set($property = null, $value = null) {
        if(property_exists($this, $property)) {
            $this->$property = $value;
        }

        return $this;
    }

    // protected methods

    /**
     * Return true when a controllerRef segment is present in the URL.
     *
     * @return bool
     */
    protected function refsDoExist() {
        return ($controllerRef = $this->get('controllerRef')) !== null && strlen($controllerRef) > 0;
    }

    // static public methods

    /**
     * Parse the current request URL with controller/action ref mapping enabled.
     *
     * @return URL
     */
    public static function getCurrentUrlWithRefs() {
        return self::getCurrentUrl(true);
    }

    /**
     * Parse the current request URL from PHP's INPUT_SERVER superglobal.
     *
     * Extracts protocol, domain, directory, document, URI, query params,
     * key=value variable segments, and optionally maps the first two path
     * segments to controllerRef and actionRef.
     *
     * @param  bool       $mapRefs When true, the first URI segment is mapped to controllerRef
     *                             and the second to actionRef.
     * @param  array|null $server  Optional server array override (used in tests).
     * @return URL
     */
    public static function getCurrentUrl($mapRefs = false, $server = null) {
        if(empty($server) || ! is_array($server)) {
            $server = [
                'REQUEST_METHOD' => filter_input(INPUT_SERVER, 'REQUEST_METHOD', FILTER_UNSAFE_RAW),
                'HTTP_HOST' => filter_input(INPUT_SERVER, 'HTTP_HOST', FILTER_UNSAFE_RAW),
                'SERVER_PORT' => filter_input(INPUT_SERVER, 'SERVER_PORT', FILTER_UNSAFE_RAW),
                'HTTPS' => filter_input(INPUT_SERVER, 'HTTPS', FILTER_UNSAFE_RAW),
                'SCRIPT_NAME' => filter_input(INPUT_SERVER, 'SCRIPT_NAME', FILTER_UNSAFE_RAW),
                'REQUEST_URI' => filter_input(INPUT_SERVER, 'REQUEST_URI', FILTER_UNSAFE_RAW)
            ];
        }

        $baseUrl = '';

        if(! empty($server['HTTP_HOST'])) {
            $protocol = ! empty($server['HTTPS']) && strtolower($server['HTTPS']) !== 'off' ? 'https' : 'http';
            $domain = $server['HTTP_HOST'];
            $directory = str_replace(basename($server['SCRIPT_NAME']), '', $server['SCRIPT_NAME']);
            $template = "%s://%s%s";
            $baseUrl = sprintf($template, $protocol, $domain, $directory);
        }

        $url = (object)array_merge(['currentUrl' => $baseUrl], parse_url($baseUrl));
        $url->method = ! empty($server['REQUEST_METHOD']) ? $server['REQUEST_METHOD'] : 'GET';
        $url->document = ! empty($server['SCRIPT_NAME']) ? substr($server['SCRIPT_NAME'], strlen($url->path)) : '';
        $url->initialUri = substr($server['REQUEST_URI'], strlen($url->path));
        $url->params = ($pos = strpos($url->initialUri, '?')) !== false ? substr($url->initialUri, $pos) : null;
        $url->uri = (!empty($url->params) ? substr($url->initialUri, 0, $pos) : $url->initialUri);
        $url->uriArray = array_filter(explode('/', $url->uri));
        $url->currentUrl .= $url->uri;

        // check if the url is a clean url

        $cleanUrls = !(isset($url->uriArray[0]) && $url->uriArray[0] == $url->document);

        // check if url is a suppressed file extension and not a true clean url

        if($cleanUrls) {
            if(($pos = strpos($url->document, '.')) !== false && $pos > 0) {
                $cleanUrls = !(isset($url->uriArray[0]) && $url->uriArray[0] == substr($url->document, 0, $pos));
            }
        }

        if($mapRefs !== false) {
            if(isset($url->uriArray[$cleanUrls ? 0 : 1]) && strpos(($controller = rawurldecode($url->uriArray[$cleanUrls ? 0 : 1])), '=') === false) {
                $url->controllerRef = $controller;
            }

            if(isset($url->controllerRef) && isset($url->uriArray[$cleanUrls ? 1 : 2]) && strpos(($action = rawurldecode($url->uriArray[$cleanUrls ? 1 : 2])), '=') === false) {
                $url->actionRef = $action;
            }
        }

        if(! empty($url->uriArray) > 0) {
              $url->uri = '';

              foreach($url->uriArray as $part) {
                  if(strpos($part, '=') < 1) {
                      $url->uri .= $part . '/';
                  }
              }
        }

       $url->variables = [];

        // Plain GET forms submit a query string; merge those params in first so
        // key=value path segments (parsed below) take precedence over them.
        if(!empty($url->params)) {
            parse_str(ltrim($url->params, '?'), $queryParams);
            foreach($queryParams as $key => $value) {
                if(is_string($value) && $value !== '') {
                    $url->variables[$key] = $value;
                }
            }
        }

        foreach($url->uriArray as $value) {
            if(($pos = strpos(($value = rawurldecode($value)), '=')) !== false && $pos > 0) {
                $key = substr($value, 0, $pos);
                $value = substr($value, $pos + 1);
                $url->variables[$key] = $value;
            }
        }

        if(! isset($url->scheme)) {
            $url->scheme = null;
        }

        if(! isset($url->host)) {
            $url->host = null;
        }

        $url = (object)[
            'port' => !empty($server['SERVER_PORT']) ? $server['SERVER_PORT'] : '80',
            'method' => $url->method,
            'protocol' => $url->scheme,
            'domain' => $url->host,
            'directory' => $url->path,
            'document' => $url->document,
            'initialUri' => $url->initialUri,
            'uri' => $url->uri,
            'params' => $url->params,
            'variables' => $url->variables,
            'controllerRef' => isset($url->controllerRef) ? $url->controllerRef : null,
            'actionRef' => isset($url->actionRef) ? $url->actionRef : null
        ];

        return new URL($url);
    }

    // static protected methods
}
