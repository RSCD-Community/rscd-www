<?php

namespace RSCD\Model;

/**
 * Reads and exposes application configuration from a JSON file.
 *
 * On construction the JSON file is parsed, PHP constants embedded as keys
 * or values are resolved via replaceConstants(), and the resulting object
 * is stored as the `properties` property. Consumers may read individual
 * top-level keys via getProperty() and inject runtime values via setProperty().
 */
class ConfigReader {
    const DEFAULT_FILE = 'app' . DIRECTORY_SEPARATOR . 'app.json';

    protected $file;
    protected $properties;

    /**
     * Load configuration from the given JSON file path.
     *
     * @param  string|null $file Absolute path to the JSON config file.
     * @throws \Exception If $file is empty or the file does not exist.
     */
    public function __construct($file = null) {
        $this->set('file', $file);
        $this->set('properties', []);

        if(! empty($this->get('file'))) {
            $this->updatePropertiesFromFile();
        } else {
            throw new \Exception('config file not defined');
        }
    }

    // public methods

    /**
     * Retrieve a top-level config property by key.
     *
     * @param  string|null $property The property key to retrieve.
     * @return mixed The property value, or null if not set.
     */
    public function getProperty($property = null) {
        $properties = $this->get('properties');

        if(! empty($property) && ! empty($properties->$property)) {
            return $properties->$property;
        }

        return null;
    }

    /**
     * Set or overwrite a top-level config property at runtime.
     *
     * @param  string|null $property The property key to set.
     * @param  mixed       $value    The value to store.
     * @return $this
     */
    public function setProperty($property = null, $value = null) {
        $properties = $this->get('properties');

        if(! empty($property)) {
            $properties->$property = $value;
        }

        $this->set('properties', $properties);

        return $this;;
    }

    /**
     * Return a raw instance property by name.
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
     * Set a raw instance property by name.
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

    /**
     * Reload properties from the configured file path.
     *
     * @return $this
     */
    protected function updatePropertiesFromFile() {
        $this->set('properties', $this->getPropertiesFromFile($this->get('file')));

        return $this;
    }

    /**
     * Parse a JSON config file and return the decoded object with constants resolved.
     *
     * @param  string|null $file Absolute path to the JSON file.
     * @return object Decoded and constant-resolved config object.
     * @throws \Exception If the file does not exist.
     */
    protected function getPropertiesFromFile($file = null) {
        $properties = [];
        if(self::fileExists($file)) {
            $json = json_decode(file_get_contents($file));
            if(! empty($json) && is_object($json)) {
                $properties = $this->replaceConstants($json);
            }
        } else {
            throw new \Exception('config file does not exist: ' . $file);
        }

        return $properties;
    }

    /**
     * Recursively replace PHP constant names used as keys or values in the config object.
     *
     * @param  object $properties The config object to process.
     * @return object The processed object with constants resolved.
     */
    protected function replaceConstants($properties) {
        foreach($properties as $key => $value) {
            if(is_array($value)) {
                $value = (object)$value;
            }
            if(is_object($value)) {
                $ckey = ! empty($key) && defined($key) ? constant($key) : $key;
                $properties->$ckey = $this->replaceConstants($value);
            } else {
                $ckey = ! empty($key) && defined($key) ? constant($key) : $key;
                $cval = ! empty($value) && defined($value) ? constant($value) : $value;
                $properties->$ckey = $cval;
            }

            if($ckey != $key) {
                unset($properties->$key);
            }
        }

        return $properties;
    }

    /**
     * Check whether the given file path exists and is non-empty.
     *
     * @param  string|null $file        File path to check.
     * @param  bool        $allowEmpty  When true, an empty path is accepted.
     * @return bool
     */
    public static function fileExists($file = null, $allowEmpty = false) {
        return ((! empty($file) || $allowEmpty === true) && file_exists($file));
    }
}
