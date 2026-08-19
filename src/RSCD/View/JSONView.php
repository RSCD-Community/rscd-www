<?php
namespace RSCD\View;

use RSCD\View\Common\View;

/**
 * JSON API view — serializes a PHP object to a JSON HTTP response.
 *
 * Sets application/json Content-Type with no-cache headers and pretty-prints
 * the JSON body. Forward-slashes are unescaped (json_encode escapes "/" by default
 * but this is unnecessary and makes URLs harder to read in responses).
 *
 * If the object has an empty $errors property, it is removed before encoding to
 * keep successful responses clean.
 *
 * Used by all API endpoints (/api/ routes) to return structured data.
 */
class JSONView extends View {

    /**
     * @param  object $app  The application instance.
     */
    public function __construct($app) {
        parent::__construct($app);
    }

    /**
     * Encode $object as JSON and configure the HTTP response.
     *
     * Removes an empty $errors property if present, then JSON-encodes with
     * JSON_PRETTY_PRINT. Sets Content-Type: application/json, no-cache headers,
     * and Content-Length.
     *
     * @param  object $object  The response payload to serialize.
     * @return static
     */
    public function setContentFromObject($object) {
        if(isset($object->errors) && empty($object->errors)) {
            unset($object->errors);
        }
        $json = str_replace('\\/', '/', json_encode($object, JSON_PRETTY_PRINT));
        $this->addHeader('Pragma', 'public');
        $this->addHeader('Expires', '0');
        $this->addHeader('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
        $this->addHeader("Cache-Control: private", false);
        $this->addHeader('Content-Type', 'application/json');
        $this->addHeader('Content-Length', strlen($json));
        $this->set('content', $json);
        return $this;
    }

}
