<?php

namespace RSCD\Model;

/**
 * A named collection of Rule objects that evaluates ALLOW/DENY access decisions.
 *
 * Rules are keyed by "ACTION:md5(conditions)" to prevent duplicates. The
 * allow() and deny() methods scan all rules of the matching action type
 * and return the magnitude (condition-string length) of the best match,
 * enabling the caller (RulePolicy) to compare specificity across collections.
 */
class RuleCollection {
    protected $name;
    protected $rules;

    /**
     * Initialise the collection from a plain object, hydrating each rule element.
     *
     * @param object|null $object Object with optional name (string) and rules (array) properties.
     */
    public function __construct($object = null) {
        $this->setName(isset($object->name) ? $object->name : null);
        $this->setRules([]);
        if(isset($object->rules) && is_array($object->rules)) {
            foreach($object->rules as $rule) {
                $this->addRule(new Rule($rule));
            }
        }
    }

    /**
     * Add one or more Rule objects to the collection.
     *
     * @param  Rule|Rule[]|null $rule      The rule or array of rules to add.
     * @param  bool             $overwrite When true, existing rules with the same key are replaced.
     * @return $this
     * @throws \Exception If a rule is invalid, has an invalid action, has no conditions,
     *                    or already exists and overwrite is disabled.
     */
    public function addRule($rule = null, $overwrite = false) {
        $rules = is_array($rule) ? $rule : [$rule];
        foreach($rules as $rule) {
            if(!Rule::valid($rule, true)) {
                throw new \Exception('nothing added: rule is not a valid rule object');
            }
            if(($action = $rule->getActionAsString()) === 'INVALID' || empty($action)) {
                throw new \Exception('nothing added: rule action cannot be null');
            }
            if(($conditions = $rule->getConditions()) === null || empty($conditions)) {
                throw new \Exception('nothing added: rule condition cannot be null');
            }
            $key = $action . ':' . md5(implode(';', $conditions));
            if(isset($this->rules[$key]) && $overwrite === false) {
                throw new \Exception('nothing added: rule already exists and overwrite disabled');
            }
            $this->rules[$key] = $rule;
        }
        return $this;
    }

    /**
     * Add or replace a rule (overwrite enabled).
     *
     * @param  Rule|null $rule The rule to add or replace.
     * @return $this
     */
    public function overwriteRule($rule = null) {
        return $this->addRule($rule, true);
    }

    /**
     * Merge one or more RuleCollection instances into this collection.
     *
     * Invalid collections or rules are silently skipped.
     *
     * @param  RuleCollection|RuleCollection[]|null $collection Collection(s) to merge from.
     * @param  bool                                 $overwrite  When true, existing rules are replaced.
     * @return $this
     */
    public function mergeWithCollection($collection = null, $overwrite = false) {
        $collections = is_array($collection) ? $collection : [$collection];
        foreach($collections as $collection) {
            if(!RuleCollection::valid($collection, true)) {
                continue;
            }
            foreach($collection->rules as $rule) {
                if(!Rule::valid($rule, true)) {
                    continue;
                }
                $this->addRule($rule, $overwrite);
            }
        }
        return $this;
    }

    /**
     * Merge one or more RuleCollections into this one, overwriting duplicates.
     *
     * @param  RuleCollection|RuleCollection[]|null $collection Collection(s) to merge from.
     * @return $this
     */
    public function mergeWithCollectionAndOverwrite($collection = null) {
        return $this->mergeWithCollection($collection, true);
    }

    /**
     * Return the best ALLOW-rule magnitude matching the given condition.
     *
     * @param  string $condition The subject string to match against rule conditions.
     * @return int|false Specificity magnitude (> 0) on match, or false if no rules exist.
     */
    public function allow($condition) {
        if(empty($condition)) {
            return false;
        }
        $rules = $this->getRules();
        if(empty($rules)) {
            return false;
        }
        $magnitude = 0;
        foreach($rules as $rule) {
            if($rule->getAction() === Rule::ACTION_DENY) {
                continue;
            }
            $ruleConditions = $rule->getConditions();
            foreach($ruleConditions as $ruleCondition) {
                $ruleMagnitude = 0;
                if($ruleCondition === $condition || static::wildcardCompare($ruleCondition, $condition)) {
                    $ruleMagnitude = strlen($ruleCondition);
                }
                if($ruleMagnitude > $magnitude) {
                    $magnitude = $ruleMagnitude;
                }
            }
        }
        return $magnitude;
    }

    /**
     * Return the best DENY-rule magnitude matching the given condition.
     *
     * @param  string $condition The subject string to match against rule conditions.
     * @return int|false Specificity magnitude (> 0) on match, or false if no rules exist.
     */
    public function deny($condition) {
        if(empty($condition)) {
            return false;
        }
        $rules = $this->getRules();
        if(empty($rules)) {
            return false;
        }
        $magnitude = 0;
        foreach($rules as $rule) {
            if($rule->getAction() === Rule::ACTION_ALLOW) {
                continue;
            }
            $ruleConditions = $rule->getConditions();
            foreach($ruleConditions as $ruleCondition) {
                $ruleMagnitude = 0;
                if($ruleCondition === $condition || static::wildcardCompare($ruleCondition, $condition)) {
                    $ruleMagnitude = strlen($ruleCondition);
                }
                if($ruleMagnitude > $magnitude) {
                    $magnitude = $ruleMagnitude;
                }
            }
        }
        return $magnitude;
    }

    /**
     * Return the total number of rules in the collection.
     *
     * @return int
     */
    public function getRuleCount() {
        return !empty($this->rules) ? count($this->rules) : 0;
    }

    /**
     * Return the collection name.
     *
     * @return string|null
     */
    public function getName() {
        return $this->name;
    }

    /**
     * Return all rules in the collection.
     *
     * @return array Associative array of Rule objects keyed by action:conditions hash.
     */
    public function getRules() {
        return $this->rules;
    }

    /**
     * Set the collection name.
     *
     * @param  string|null $name The name to assign.
     * @return $this
     */
    public function setName($name = null) {
        $this->name = $name;
        return $this;
    }

    /**
     * Replace the entire rules array.
     *
     * @param  array|null $rules Associative array of Rule objects.
     * @return $this
     */
    public function setRules($rules = null) {
        $this->rules = $rules;
        return $this;
    }

    /**
     * Return the collection as a plain object suitable for JSON serialisation.
     *
     * @return object Object with name and rules array properties.
     */
    public function getAsObject() {
        $object = (object)[
            'name' => $this->getName(),
            'rules' => []
        ];
        $rules = $this->getRules();
        if(empty($rules) || !is_array($rules)) {
            return $object;
        }
        foreach($rules as $rule) {
            if(!Rule::valid($rule, true)) {
                continue;
            }
            $object->rules[] = $rule->getAsObject();
        }
        return $object;
    }

    /**
     * Return the collection as a JSON string.
     *
     * @return string JSON-encoded collection.
     */
    public function getAsJson() {
        return json_encode($this->getAsObject());
    }

    /**
     * Decode a JSON string and return a RuleCollection instance, or null if decoding fails.
     *
     * @param  string|null $json JSON-encoded collection definition.
     * @return RuleCollection|null
     */
    public static function createFromJson($json = null) {
        $object = json_decode($json);
        if(is_object($object)) {
            return new RuleCollection($object);
        }
        return null;
    }

    /**
     * Check whether the given value is a valid RuleCollection object.
     *
     * @param  mixed $collection The value to test.
     * @param  bool  $strict     When true, also requires getName(), allow(), and deny() methods.
     * @return bool
     */
    public static function valid($collection = null, $strict = false) {
        return !empty($collection) && is_object($collection) && property_exists($collection, 'name') && property_exists($collection, 'rules') &&
           (($strict !== false && method_exists($collection, 'getName') && method_exists($collection, 'allow') && method_exists($collection, 'deny')) || $strict === false);
    }

    /**
     * Escape a string for use as a regex pattern, with optional wildcard support.
     *
     * @param  string      $string   The string to make regex-compatible.
     * @param  string|bool $wildcard Character to treat as .* wildcard, or false to disable.
     * @return string The escaped pattern string.
     */
    public static function makeRegexCompatible($string = '', $wildcard = false) {
        $string = str_replace(['\\.', '\\^', '\\$', '\\(', '\\|', '\\)', '\\?', '\\*', '\\+', '\\{', '\\}', '\\[', '\\]',  '\\/'],
            ['.', '^', '$', '(', '|', ')', '?', '*', '+', '{', '}', '[', ']', '/'], $string);
        $string = str_replace(['.', '^', '$', '(', '|', ')', '?', '*', '+', '{', '}', '[', ']', '/'],
            ['\\.', '\\^', '\\$', '\\(', '\\|', '\\)', '\\?', '\\*', '\\+', '\\{', '\\}', '\\[', '\\]', '\\/'], $string);
        if($wildcard !== false) {
            $string = str_replace($wildcard, '.*', $string);
        }
        return $string;
    }

    /**
     * Compare a needle pattern (with optional wildcard) against a haystack string.
     *
     * @param  string      $needle     The pattern, may contain $wildcard characters.
     * @param  string      $haystack   The string to test.
     * @param  string      $wildcard   The wildcard character (default '%').
     * @param  bool        $ignoreCase When true, comparison is case-insensitive.
     * @return bool True if the haystack matches the needle pattern.
     */
    public static function wildcardCompare($needle = '', $haystack = '', $wildcard = '%', $ignoreCase = false) {
        return preg_match('/^' . static::makeRegexCompatible($needle, $wildcard) . '$/' . ($ignoreCase ? 'i' : ''), $haystack) == 1;
    }
}
