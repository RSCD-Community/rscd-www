<?php

namespace RSCD\Model;

use \RSCD\Model\RuleCollection;

/**
 * A named policy containing one or more RuleCollection instances.
 *
 * A RulePolicy aggregates multiple named RuleCollections and delegates
 * allow() / deny() decisions to each, returning the highest-magnitude
 * (most-specific) match found across all collections. Policies can be
 * merged together and serialised to/from JSON.
 */
class RulePolicy {
    protected $name;
    protected $collections;

    /**
     * Initialise the policy from a plain object, hydrating each collection.
     *
     * @param object|null $object Object with optional name (string) and collections (array) properties.
     */
    public function __construct($object = null) {
        $this->setName(isset($object->name) ? $object->name : null);
        $this->setCollections([]);
        if(isset($object->collections) && is_array($object->collections)) {
            foreach($object->collections as $collection) {
                $this->addCollection(new RuleCollection($collection));
            }
        }
    }

    /**
     * Merge one or more RulePolicies into this policy.
     *
     * Invalid policies are skipped. For each named collection in the incoming
     * policy, if this policy already has a collection with that name it is
     * merged; otherwise the collection is added directly.
     *
     * @param  RulePolicy|RulePolicy[]|null $policy    Policy or policies to merge from.
     * @param  bool                         $overwrite When true, duplicate rules are overwritten.
     * @return $this
     */
    public function mergeWithPolicy($policy = null, $overwrite = false) {
        $policies = is_array($policy) ? $policy : [$policy];
        foreach($policies as $policy) {
            if(!RulePolicy::valid($policy, true)) {
                continue;
            }
            $collections = $this->getCollections();
            if(empty($collections)) {
                return $names;
            }
            foreach($collections as $name => $collection) {
                if($this->getCollectionByName($name) !== null) {
                    $this->getCollectionByName($name)->mergeWithCollection($collection, $overwrite);
                } else {
                    $this->setCollection($name, $collection);
                }
            }
        }
        return $this;
    }

    /**
     * Merge one or more RulePolicies into this policy, overwriting duplicate rules.
     *
     * @param  RulePolicy|RulePolicy[]|null $policy Policy or policies to merge from.
     * @return $this
     */
    public function mergeWithPolicyAndOverwrite($policy = null) {
        return $this->mergeWithPolicy($policy, true);
    }

    /**
     * Add one or more RuleCollection objects to the policy.
     *
     * @param  RuleCollection|RuleCollection[]|null $collection Collection(s) to add.
     * @param  bool                                 $overwrite  When true, existing collections with the same name are replaced.
     * @return $this
     * @throws \Exception If a collection is invalid, has no name, or already exists and overwrite is disabled.
     */
    public function addCollection($collection = null, $overwrite = false) {
        $collections = is_array($collection) ? $collection : [$collection];
        foreach($collections as $collection) {
            if(!RuleCollection::valid($collection, true)) {
                throw new \Exception('nothing added: collection is not a valid collection object');
            }
            if(($name = $collection->getName()) === null || empty($name)) {
                throw new \Exception('nothing added: collection name cannot be null');
            }
            if(isset($this->collections[$name]) && $overwrite === false) {
                throw new \Exception('nothing added: collection already exists and overwrite disabled');
            }
            $this->collections[$name] = $collection;
        }
        return $this;
    }

    /**
     * Add or replace a collection (overwrite enabled).
     *
     * @param  RuleCollection|null $collection The collection to add or replace.
     * @return $this
     */
    public function overwriteCollection($collection = null) {
        return $this->addCollection($collection, true);
    }

    /**
     * Return the best ALLOW-rule magnitude matching the given condition across all collections.
     *
     * @param  string $condition The subject string to match.
     * @return int|false Specificity magnitude (> 0) on match, or false if no collections exist.
     */
    public function allow($condition) {
        if(empty($condition)) {
            return false;
        }
        $collections = $this->getCollections();
        if(empty($collections)) {
            return false;
        }
        $magnitude = 0;
        foreach($collections as $collection) {
            if(!RuleCollection::valid($collection, true)) {
                continue;
            }
            $collectionMagnitude = $collection->allow($condition);
            if($collectionMagnitude > $magnitude) {
                $magnitude = $collectionMagnitude;
            }
        }
        return $magnitude;
    }

    /**
     * Return the best DENY-rule magnitude matching the given condition across all collections.
     *
     * @param  string $condition The subject string to match.
     * @return int|false Specificity magnitude (> 0) on match, or false if no collections exist.
     */
    public function deny($condition) {
        if(empty($condition)) {
            return false;
        }
        $collections = $this->getCollections();
        if(empty($collections)) {
            return false;
        }
        $magnitude = 0;
        foreach($collections as $collection) {
            if(!RuleCollection::valid($collection, true)) {
                continue;
            }
            $collectionMagnitude = $collection->deny($condition);
            if($collectionMagnitude > $magnitude) {
                $magnitude = $collectionMagnitude;
            }
        }
        return $magnitude;
    }

    /**
     * Return the policy name.
     *
     * @return string|null
     */
    public function getName() {
        return $this->name;
    }

    /**
     * Return all collections in the policy.
     *
     * @return array Associative array of RuleCollection objects keyed by name.
     */
    public function getCollections() {
        return $this->collections;
    }

    /**
     * Return a single collection by name, or null if not found.
     *
     * @param  string|null $name The collection name.
     * @return RuleCollection|null
     */
    public function getCollectionByName($name = null) {
        if(isset($this->collections[$name])) {
            return $this->collections[$name];
        }
        return null;
    }

    /**
     * Return an array of all collection names in this policy.
     *
     * @return array Array of non-empty name strings.
     */
    public function getCollectionNames() {
        $names = [];
        $collections = $this->getCollections();
        if(empty($collections)) {
            return $names;
        }
        foreach($collections as $collection) {
            if(!RuleCollection::valid($collection, true)) {
                continue;
            }
            $name = $collection->getName();
            if(!empty($name)) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * Return the total number of collections in the policy.
     *
     * @return int
     */
    public function getCollectionCount() {
        return $this->collections !== null ? count($this->collections) : 0;
    }

    /**
     * Set the policy name.
     *
     * @param  string|null $name The name to assign.
     * @return $this
     */
    public function setName($name = null) {
        $this->name = $name;
        return $this;
    }

    /**
     * Replace the entire collections array.
     *
     * @param  array|null $collections Associative array of RuleCollection objects.
     * @return $this
     */
    public function setCollections($collections = null) {
        $this->collections = $collections;
        return $this;
    }

    /**
     * Set a single named collection.
     *
     * @param  string|null          $name       The collection name key.
     * @param  RuleCollection|null  $collection The collection to store.
     * @return $this
     */
    public function setCollection($name = null, $collection = null) {
        if(!empty($name)) {
            $this->collections[$name] = $collection;
        }
        return $this;
    }

    /**
     * Return the policy as a plain object suitable for JSON serialisation.
     *
     * @return object Object with name and collections array properties.
     */
    public function getAsObject() {
        $object = (object)[
            'name' => $this->getName(),
            'collections' => []
        ];
        $collections = $this->getCollections();
        if(empty($collections) || !is_array($collections)) {
            return $object;
        }
        foreach($collections as $collection) {
            if(!RuleCollection::valid($collection, true)) {
                continue;
            }
            $object->collections[] = $collection->getAsObject();
        }
        return $object;
    }

    /**
     * Return the policy as a JSON string.
     *
     * @return string JSON-encoded policy.
     */
    public function getAsJson() {
        return json_encode($this->getAsObject());
    }

    /**
     * Decode a JSON string and return a RulePolicy instance, or null if decoding fails.
     *
     * @param  string|null $json JSON-encoded policy definition.
     * @return RulePolicy|null
     */
    public static function createFromJson($json = null) {
        $object = json_decode($json);
        if(is_object($object)) {
            return new RulePolicy($object);
        }
        return null;
    }

    /**
     * Check whether the given value is a valid RulePolicy object.
     *
     * @param  mixed $policy The value to test.
     * @param  bool  $strict When true, also requires getName(), allow(), and deny() methods.
     * @return bool
     */
    public static function valid($policy = null, $strict = false) {
        return $policy !== null && is_object($policy) && property_exists($policy, 'name') && property_exists($policy, 'collections') &&
           (($strict !== false && method_exists($policy, 'getName') && method_exists($policy, 'allow') && method_exists($policy, 'deny')) || $strict === false);
    }
}
