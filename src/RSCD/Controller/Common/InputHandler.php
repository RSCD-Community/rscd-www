<?php

namespace RSCD\Controller\Common;

/**
 * Thin wrapper around PHP's filter_input functions for reading HTTP input.
 *
 * Provides a clean, testable API for accessing request headers, GET/POST data,
 * raw PHP input, and uploaded files without directly referencing superglobals
 * in controller business logic.
 */
class InputHandler {

    /**
     * Return the current request's server/header variables.
     *
     * @return array|null Filtered INPUT_SERVER array, or null on failure.
     */
    protected function getHeaders() {
        return filter_input_array(INPUT_SERVER);
    }

    /**
     * Return the raw request body as a string.
     *
     * @return string Raw bytes from php://input.
     */
    protected function getInputData() {
        return file_get_contents('php://input');
    }

    /**
     * Return filtered GET parameters matching the given argument spec.
     *
     * @param array $args filter_input_array argument spec.
     * @return array|null Filtered GET values, or null on failure.
     */
    protected function getGetData(array $args) {
        return filter_input_array(INPUT_GET, $args);
    }

    /**
     * Return filtered POST parameters matching the given argument spec.
     *
     * @param array $args filter_input_array argument spec.
     * @return array|null Filtered POST values, or null on failure.
     */
    protected function getPostData(array $args) {
        return filter_input_array(INPUT_POST, $args);
    }

    /**
     * Return the raw $_FILES superglobal for uploaded file access.
     *
     * @return array $_FILES array.
     */
    protected function getPostFiles() {
        return $_FILES;
    }

}
