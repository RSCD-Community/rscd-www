<?php

namespace RSCD\Model;

use \RSCD\Model\URL;
use \RSCD\Util\Dates;
use \RSCD\Util\Strings;

/**
 * Loads an HTML layout file from disk and injects template codes and variable values.
 *
 * Template codes such as [{url.base}], [{directory}], etc. are replaced with
 * values derived from the current request URL. Additional key/value pairs
 * can be injected via injectHtml(). The populated HTML is stored in the
 * `html` property and can be read back by the view layer.
 */
class ViewLayout {
    // constants

    const DEFAULT_STORAGE_DIR = 'ui' . DIRECTORY_SEPARATOR . 'html'
        . DIRECTORY_SEPARATOR;

    // properties

    protected $storageDirectory;
    protected $file;
    protected $html;

    // constructor / destructor

    /**
     * Initialise the layout with storage directory, filename, and optional pre-set HTML.
     *
     * @param object|null $layout Object with optional storageDirectory, file, and html properties.
     */
    public function __construct($layout = null) {
        $this->set('storageDirectory',
            ((isset($layout->storageDirectory) ? $layout->storageDirectory
                : (defined('__HTML_DIR__') ? __HTML_DIR__ : __ROOTS__
                . ViewLayout::DEFAULT_STORAGE_DIR))));
        $this->set('file', isset($layout->file) ? $layout->file : '');
        $this->set('html', isset($layout->html) ? $layout->html : '');
    }

    // public methods

    /**
     * Load the configured HTML file from disk and inject built-in URL template codes.
     *
     * @return $this
     * @throws \Exception If the file does not exist in the storage directory.
     */
    public function populateHtmlFromFile() {
        if(empty(($file = $this->get('file'))) || ! file_exists(($directory = $this->get('storageDirectory')) . $file)) {
            throw new \Exception("html layout file does not exist: $directory$file");
        }

        $html = file_get_contents($directory . $file);

        $this->set('html', $this->injectHtmlBuiltInTemplates($html));

        return $this;
    }

    /**
     * Inject key/value pairs into the stored HTML and re-apply built-in template codes.
     *
     * @param  string|array|null $keys   Key or array of keys to replace.
     * @param  string|array|null $values Corresponding replacement value(s).
     * @return $this
     */
    public function injectHtml($keys = null, $values = null) {
        if(empty($keys)) {
            return $this;
        }

        $this->set('html', $this->injectHtmlBuiltInTemplates(Strings::inject($this->get('html'), $keys, $values)));

        return $this;
    }

    /**
     * Replace [{key}] template codes in an HTML string with current URL values.
     *
     * Escaped codes (\[{key}]) are preserved and not substituted.
     *
     * @param  string|null $html The HTML string to process.
     * @return string|null The processed HTML, or the original value if empty.
     */
    public function injectHtmlBuiltInTemplates($html = null) {
        if(empty($html)) {
            return $html;
        }

        $url = URL::getCurrentUrlWithRefs();

        $templateCodes = [
            'url.base' => $url->getBaseUrl(),
            'url' => $url->getUrl(),
            'protocol' => $url->get('protocol'),
            'domain' => $url->get('domain'),
            'directory' => $url->get('directory'),
            'document' => $url->get('document'),
            // Current year in the viewer's timezone, for copyright lines --
            // hardcoded years go stale every January.
            'year' => Dates::display(time(), 'Y')
        ];

        foreach($templateCodes as $key => $value) {
            if(is_string($value)) {
                $html = str_replace('\\{{[' . $key . ']}}', '\\[{' . $key . '}]', str_replace('[{' . $key . '}]', $value, str_replace('\\[{' . $key . '}]', '\\{{[' . $key . ']}}', $html)));
            }
        }

        return $html;
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
    // static public methods
    // static protected methods
}
