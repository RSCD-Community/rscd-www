<?php

namespace RSCD\Model;

use \RSCD\Model\URL;

/**
 * Reads, validates, and writes the application .htaccess file.
 *
 * On construction, loads the current .htaccess contents and the template
 * file contents. The validate() method compares them; if they differ the
 * template is re-injected with current URL values and written to disk.
 * Template codes such as [{url}], [{directory}], etc. are replaced with
 * values derived from the current request URL at write time.
 */
class AccessReaderWriter {
    // constants

    const DEFAULT_TEMPLATE_FILE = 'app' . DIRECTORY_SEPARATOR . 'app.htaccess';

    // properties

    protected $contents;
    protected $templateFile;
    protected $templateContents;

    // constructor / destructor

    /**
     * Initialise the reader/writer and load existing file contents.
     *
     * @param string|null $file Path to the .htaccess template file, or null to use the built-in default template.
     */
    public function __construct($file = null) {
        $this->set('contents', '');
        $this->set('templateFile', $file);
        $this->set('templateContents', '');
        $this->populateContentsFromFile();
        $this->populateTemplateContentsFromFile();
    }

    // public methods

    /**
     * Validate that the live .htaccess matches the template; rewrite it if not.
     *
     * @return void
     */
    public function validate() {
        if(empty(($contents = $this->get('contents'))) || $contents != $this->get('templateContents')) {
            $this->populateContentsFromTemplateContents()->writeContentsToFile();
        }
    }

    // protected methods

    /**
     * Write the current contents buffer to the live .htaccess file.
     *
     * @return $this
     */
    protected function writeContentsToFile() {
        file_put_contents(__ROOTS__ . '.htaccess', $this->get('contents'));

        return $this;
    }

    /**
     * Populate the contents buffer from the template, injecting URL values.
     *
     * @return $this
     */
    protected function populateContentsFromTemplateContents() {
        if(empty(($templateContents = $this->get('templateContents')))) {
            return $this;
        }

        $this->set('contents', $this->injectTemplateCodeValues($templateContents));

        return $this;
    }

    /**
     * Load the template file into the templateContents buffer.
     *
     * Falls back to a built-in default template when no file is configured
     * or the file does not exist on disk.
     *
     * @return $this
     */
    protected function populateTemplateContentsFromFile() {
        $defaultTemplate = '## auto-generated , manual changes will be overwritten!' . PHP_EOL
            . '##   instead create template file (default location: config/app.htaccess)' . PHP_EOL
            . '##   template codes: \[{url}] | \[{protocol}] | \[{domain}] | \[{directory}] | \[{document}]' . PHP_EOL
            . 'RedirectMatch 404 /(composer|gitignore|htaccess|phpunit|LICENSE|README)' . PHP_EOL
            . 'RedirectMatch 404 /(bin|config|\.git|log|nbproject|routes|src|vendor)' . PHP_EOL
            . 'ErrorDocument 403 [{directory}]index.php/403/' . PHP_EOL
            . 'ErrorDocument 404 [{directory}]index.php/404/' . PHP_EOL
            . 'ErrorDocument 500 [{directory}]index.php/500/' . PHP_EOL
            . 'RewriteEngine On' . PHP_EOL
            . 'RewriteCond %{REQUEST_FILENAME} !-f' . PHP_EOL
            . 'RewriteCond %{REQUEST_FILENAME} !-d' . PHP_EOL
            . 'RewriteRule ^(.*)$ [{directory}]index.php/$1 [L]';
        $template = null;

        if(! empty(($file = $this->get('templateFile'))) && file_exists($file)) {
            $template = file_get_contents($file);
        }

        $this->set('templateContents', empty($template) ? $defaultTemplate : $template);

        return $this;
    }

    /**
     * Load the live .htaccess file into the contents buffer.
     *
     * Does nothing if the file does not yet exist.
     *
     * @return $this
     */
    protected function populateContentsFromFile() {
        $file = __ROOTS__ . '.htaccess';

        if(! file_exists($file)) {
            return $this;
        }

        $this->set('contents', file_get_contents($file));

        return $this;
    }

    /**
     * Replace [{key}] template codes in a string with current URL values.
     *
     * Escaped codes (\[{key}]) are preserved and not substituted.
     *
     * @param  string|null $string The template string to process.
     * @return string|null The processed string, or the original value if empty.
     */
    protected function injectTemplateCodeValues($string = null) {
        if(empty($string)) {
            return $string;
        }

        $url = URL::getCurrentUrlWithRefs();
        $templateCodes = [
            'url.base' => $url->getBaseUrl(),
            'url' => $url->getUrl(),
            'protocol' => $url->get('protocol'),
            'domain' => $url->get('domain'),
            'directory' => $url->get('directory'),
            'document' => $url->get('document')
        ];

        foreach($templateCodes as $key => $value) {
            if(is_string($value)) {
                $string = str_replace('\\{{[' . $key . ']}}', '\\[{' . $key . '}]', str_replace('[{' . $key . '}]', $value, str_replace('\\[{' . $key . '}]', '\\{{[' . $key . ']}}', $string)));
            }
        }

        return $string;
    }

    /**
     * Return a property value by name.
     *
     * @param  string|null $property Property name.
     * @return mixed The property value, or null if the property does not exist.
     */
    protected function get($property = null) {
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
    protected function set($property = null, $value = null) {
        if(property_exists($this, $property)) {
            $this->$property = $value;
        }

        return $this;
    }

    // static public methods
    // static protected methods
}
