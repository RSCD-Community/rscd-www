<?php

namespace RSCD\Util;

use RSCD\Util\Arrays;
use RSCD\Util\Strings;

/**
 * Fluent HTTP client wrapping PHP's cURL extension.
 *
 * Supports GET, POST, PUT, PATCH, and DELETE requests in JSON or form-data
 * mode. Multipart file uploads are detected automatically when a parameter
 * array entry has `filename` and `contents` keys. Cookie persistence is
 * supported via a cookie-jar file path.
 *
 * Usage:
 *   $response = (new CURL())->setUrl('https://example.com/api')->setParams(['foo' => 'bar'])->send()->getResponse();
 */
class CURL {

    /** Send and receive body as JSON (default). */
    const MODE_JSON = 1;

    /** Send body as application/x-www-form-urlencoded or multipart/form-data. */
    const MODE_FORMDATA = 2;

    const HTTP_GET    = 'GET';
    const HTTP_POST   = 'POST';
    const HTTP_PUT    = 'PUT';
    const HTTP_PATCH  = 'PATCH';
    const HTTP_DELETE = 'DELETE';

    /** Default sprintf format for flattening nested parameter keys. */
    const DEFAULT_DATA_FORMAT = '%s.%s';

    /** @var int  One of the MODE_* constants. */
    protected $mode;

    /** @var string  HTTP method (one of the HTTP_* constants). */
    protected $method;

    /** @var string|null  Full URL for the request. */
    protected $url;

    /** @var array|string  Request parameters or raw body. */
    protected $params;

    /** @var array  Additional HTTP headers to send. */
    protected $headers;

    /** @var int|null  HTTP response status code. */
    protected $httpResponseCode;

    /** @var array  Parsed response headers. */
    protected $responseHeaders;

    /** @var mixed  Decoded response body (object for JSON mode, string otherwise). */
    protected $response;

    /** @var string  Key-join format passed to Arrays::getAsDataUri(). */
    protected $dataFormat;

    /** @var string|null  Filesystem path to the cURL cookie-jar file. */
    protected $cookieJar;

    /**
     * Initialise the client with optional default values.
     *
     * @param  string|null  $method   HTTP method (default GET).
     * @param  string|null  $url      Request URL.
     * @param  array        $params   Request parameters.
     * @param  array        $headers  Additional HTTP headers.
     */
    public function __construct($method = null, $url = null, $params = [], $headers = []) {
        $this->mode = self::MODE_JSON;
        $this->method = self::HTTP_GET;
        $this->url = $url;
        $this->params = $params;
        $this->headers = $headers;
        $this->dataFormat = self::DEFAULT_DATA_FORMAT;
    }

    /**
     * Execute the HTTP request and populate the response properties.
     *
     * Returns $this for chaining. Does nothing if the URL is not set or if the
     * request has already been sent (non-empty response).
     *
     * @return static  $this
     * @throws \Exception  On cURL execution failure.
     */
    public function send() {
        if(!$this->isReady()) {
            return $this;
        }
        if($this->isSent()) {
            return $this;
        }
        $isJsonMode = $this->getMode() !== self::MODE_FORMDATA;
        $headers = $this->getHeaders();
        $params = $this->getParams();
        $method = $this->getMethod();
        $ch = curl_init();
        if($method == self::HTTP_GET) {
            curl_setopt($ch, CURLOPT_HTTPGET, 1);
            if(!empty($params)) {
                $this->setUrl($this->getUrl() . (is_object($params) || is_array($params) ? Arrays::getAsDataUri($params, $this->getDataFormat()) : '?' . $params));
            }
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        else if($method == self::HTTP_DELETE) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            if(!empty($params)) {
                $this->setUrl($this->getUrl() .  (is_object($params) || is_array($params) ? Arrays::getAsDataUri($params, $this->getDataFormat()) : '?' . $params));
            }
            if($isJsonMode) {
                $headers[] = 'Content-Type: application/json';
            }
            else {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }
        }
        else {
            $isMultipart = $this->isMultipartRequired();
            if($isMultipart) {
                $boundary = md5(time());
                $multipartData = $this->getMultipartData($boundary);
                $headers[] = 'Content-Type: multipart/form-data; boundary=' . $boundary;
            }
            else if($isJsonMode) {
                $headers[] = 'Content-Type: application/json';
            }
            else {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }
            if(in_array($method, [self::HTTP_POST, self::HTTP_PUT, self::HTTP_PATCH])) {
                if(in_array($method, [self::HTTP_PUT, self::HTTP_PATCH])) {
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                }
                else {
                    curl_setopt($ch, CURLOPT_POST, 1);
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $isMultipart ? $multipartData : ($isJsonMode ? json_encode($params) : $params));
            } else {
                throw new \Exception('Unrecognized method: ' . $method);
            }
        }
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_URL, $this->getUrl());
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 300);
        $cookieJar = $this->getCookieJar();
        if(!empty($cookieJar)) {
            if(file_exists($cookieJar)) {
                curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
            }
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        }
        try {
            $fullResponse = curl_exec($ch);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headersAsString = substr($fullResponse, 0, $headerSize);
            $response = substr($fullResponse, $headerSize);
            $responseHeaders = [];
            $rawResponseHeaders = explode("\n", $headersAsString);
            $httpResponseCode = 200;
            foreach($rawResponseHeaders as $header) {
                $matches = [];
                $cleanHeader = Strings::trim(str_replace("\r", '', $header));
                if(($pos = strpos($cleanHeader, ':')) !== false) {
                    $responseHeaders[Strings::trim(substr($cleanHeader, 0, $pos))] = Strings::trim(substr($cleanHeader, $pos + 1));
                }
                else if(preg_match('/HTTP\/[12]\.?[01]? (\d+)/i', $cleanHeader, $matches) >= 1) {
                    $httpResponseCode = (int)$matches[1];
                }
            }
            $this->setHttpResponseCode($httpResponseCode);
            $this->setResponseHeaders($responseHeaders);
            $this->setResponse($isJsonMode ? json_decode($response) : $response);
            curl_close($ch);
        }
        catch(\Exception $e) {
            curl_close($ch);
            throw new \Exception('Invalid CURL response', 9001);
        }
        return $this;
    }

    /**
     * Build a raw multipart/form-data body string.
     *
     * Each parameter that is a file (has `filename` + `contents` keys/properties)
     * is serialized as a file part; array parameters with a `[]` key suffix are
     * repeated; all others are serialized as plain text parts.
     *
     * @param  string  $boundary  Multipart boundary string.
     * @return string              Raw multipart body.
     */
    protected function getMultipartData($boundary) {
        $data = '';
        $delimiter = "\r\n";
        $params = $this->getParams();
        foreach($params as $key => $value) {
            if(is_array($value) && isset($value['filename']) && isset($value['contents'])) {
                $data .= '--' . $boundary . $delimiter;
                $data .= 'Content-Disposition: form-data; name="' . $key . '"; filename="' . $value['filename'] . '"' . $delimiter . 'Content-Type: application/octet-stream' . $delimiter . 'Content-Transfer-Encoding: 8bit' . $delimiter . $delimiter . $value['contents'] . $delimiter;
            }
            else if(is_object($value) && isset($value->filename) && isset($value->contents)) {
                $data .= '--' . $boundary . $delimiter;
                $data .= 'Content-Disposition: form-data; name="' . $key . '"; filename="' . $value->filename . '"' . $delimiter . 'Content-Type: application/octet-stream' . $delimiter . 'Content-Transfer-Encoding: 8bit' . $delimiter . $delimiter . $value->contents . $delimiter;
            }
            else if(strpos($key, '[]') !== false && (is_array($value) || is_object($value))) {
                foreach($value as $v) {
                    $data .= '--' . $boundary . $delimiter;
                    $data .= 'Content-Disposition: form-data; name="' . $key . '"' . $delimiter . $delimiter . $v . $delimiter;

                }
            }
            else {
                $data .= '--' . $boundary . $delimiter;
                $data .= 'Content-Disposition: form-data; name="' . $key . '"' . $delimiter . $delimiter . $value . $delimiter;
            }
        }
        return $data . "--" . $boundary . "--" . $delimiter . $delimiter;
    }

    /**
     * Test whether a single parameter value represents a file upload.
     *
     * A parameter is considered a file if it has both `filename` and `contents`
     * entries (for arrays) or properties (for objects).
     *
     * @param  array|object  $param  A single parameter value.
     * @return bool                   True if the parameter is a file upload.
     */
    protected function paramIsFile($param) {
        if(is_object($param)) {
            return !empty($param->filename) && !empty($param->contents);
        }
        return !empty($param['filename']) && !empty($param['contents']);
    }

    /**
     * Return true if the client has a URL set and is ready to send.
     *
     * @return bool
     */
    protected function isReady() {
        return !empty($this->url);
    }

    /**
     * Return true if the request has already been sent (response is populated).
     *
     * @return bool
     */
    protected function isSent() {
        return !empty($this->response);
    }

    /**
     * Reset the client to its initial state so it can be reused for another request.
     *
     * @return static  $this
     */
    public function reset() {
        $this->method = null;
        $this->url = null;
        $this->params = [];
        $this->headers = [];
        $this->dataFormat = self::DEFAULT_DATA_FORMAT;
        return $this;
    }

    /**
     * Return true if any parameter is a file upload, requiring multipart encoding.
     *
     * @return bool
     */
    public function isMultipartRequired() {
        if(empty($this->params)) {
            return false;
        }
        if(!is_array($this->params) && !is_object($this->params)) {
            return false;
        }
        foreach($this->params as $key => $value) {
            if(!$this->paramIsFile($value)) {
                continue;
            }
            return true;
        }
        return false;
    }

    /** @return int  One of the MODE_* constants. */
    public function getMode() {
        return $this->mode;
    }

    /** @param int $mode  One of the MODE_* constants. @return static */
    public function setMode($mode) {
        $this->mode = $mode;
        return $this;
    }

    /** @return string  Current HTTP method. */
    public function getMethod() {
        return $this->method;
    }

    /** @param string $method  One of the HTTP_* constants. @return static */
    public function setMethod($method) {
        $this->method = $method;
        return $this;
    }

    /** @return string|null  Request URL. */
    public function getUrl() {
        return $this->url;
    }

    /** @param string $url  Full URL for the request. @return static */
    public function setUrl($url) {
        $this->url = $url;
        return $this;
    }

    /** @return array|string  Request parameters or raw body. */
    public function getParams() {
        return $this->params;
    }

    /**
     * Set request parameters. Normalizes null/empty to an empty array.
     *
     * @param  array|string|null  $params  Parameters or raw body.
     * @return static
     */
    public function setParams($params) {
        if(empty($params)) {
            $params = [];
        }
        $this->params = $params;
        return $this;
    }

    /** @return array  Additional HTTP headers. */
    public function getHeaders() {
        return $this->headers;
    }

    /** @param array $headers  HTTP header strings (e.g. `['Authorization: Bearer …']`). @return static */
    public function setHeaders($headers) {
        $this->headers = $headers;
        return $this;
    }

    /** @return string  Key-join format for nested parameters. */
    public function getDataFormat() {
        return $this->dataFormat;
    }

    /** @param string $dataFormat  sprintf format, e.g. `'%s[%s]'` for PHP-style arrays. @return static */
    public function setDataFormat($dataFormat) {
        $this->dataFormat = $dataFormat;
        return $this;
    }

    /** @return string|null  Path to the cookie-jar file, or null if not set. */
    public function getCookieJar() {
        return $this->cookieJar;
    }

    /** @param string $cookieJar  Filesystem path for reading/writing cookies. @return static */
    public function setCookieJar($cookieJar) {
        $this->cookieJar = $cookieJar;
        return $this;
    }

    /** @return int|null  HTTP response status code (populated after send()). */
    public function getHttpResponseCode() {
        return $this->httpResponseCode;
    }

    /** @param int $httpResponseCode  HTTP status code from the response. @return static */
    public function setHttpResponseCode($httpResponseCode) {
        $this->httpResponseCode = $httpResponseCode;
        return $this;
    }

    /** @return array  Parsed response headers as `name => value` pairs. */
    public function getResponseHeaders() {
        return $this->responseHeaders;
    }

    /** @param array $responseHeaders  Parsed response header map. @return static */
    public function setResponseHeaders($responseHeaders) {
        $this->responseHeaders = $responseHeaders;
        return $this;
    }

    /** @return mixed  Decoded response body (stdClass for JSON mode, string otherwise). */
    public function getResponse() {
        return $this->response;
    }

    /** @param mixed $response  Decoded response body. @return static */
    public function setResponse($response) {
        $this->response = $response;
        return $this;
    }

}
