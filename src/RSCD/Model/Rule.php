<?php

namespace RSCD\Model;
use RSCD\Util\Strings;

/**
 * Represents a single access-control rule with an action and one or more conditions.
 *
 * A rule has an ACTION_ALLOW or ACTION_DENY action and a list of wildcard
 * condition strings. The RuleCollection evaluates rules by matching a
 * subject string against the conditions and returning the magnitude
 * (specificity) of the best-matching rule.
 */
class Rule {
    const ACTION_ALLOW = 1;
    const ACTION_DENY = 2;

    protected $action;
    protected $conditions;

    /**
     * Initialise a Rule from a plain object.
     *
     * @param object|null $object Object with optional action (string or int) and conditions (array) properties.
     */
    public function __construct($object = null) {
        $this->setAction(isset($object->action) ? $object->action : null);
        $this->setConditions(isset($object->conditions) ? $object->conditions : []);
    }

    /**
     * Return the rule as a plain object suitable for JSON serialisation.
     *
     * @return object Object with action (string) and conditions properties.
     */
    public function getAsObject() {
        $object = (object)[
            'action' => $this->getActionAsString(),
            'conditions' => $this->conditions
        ];
        return $object;
    }

    /**
     * Return the rule as a JSON string.
     *
     * @return string JSON-encoded rule.
     */
    public function getAsJson() {
        return json_encode($this->getAsObject());
    }

    /**
     * Return the action as an uppercase string ('ALLOW', 'DENY', or 'INVALID').
     *
     * @return string
     */
    public function getActionAsString() {
        if($this->action === static::ACTION_ALLOW) {
            return "ALLOW";
        }
        if($this->action === static::ACTION_DENY) {
            return "DENY";
        }
        return "INVALID";
    }

    /**
     * Return the numeric action constant.
     *
     * @return int|null ACTION_ALLOW, ACTION_DENY, or null if not set.
     */
    public function getAction() {
        return $this->action;
    }

    /**
     * Set the action, accepting either a numeric constant or an 'ALLOW'/'DENY' string.
     *
     * @param  mixed $action Numeric constant or string 'ALLOW'/'DENY'.
     * @return $this
     */
    public function setAction($action = null) {
        if(!is_numeric($action)) {
            $actionAsString = Strings::trim(strtoupper($action));
            if($actionAsString === 'ALLOW') {
                $action = static::ACTION_ALLOW;
            } else if($actionAsString === 'DENY') {
                $action = static::ACTION_DENY;
            }
        }
        $this->action = $action;
        return $this;
    }

    /**
     * Return the array of condition strings.
     *
     * @return array
     */
    public function getConditions() {
        return $this->conditions;
    }

    /**
     * Set the conditions, trimming each entry.
     *
     * @param  array|string|null $conditions One or more condition strings.
     * @return $this
     */
    public function setConditions($conditions = null) {
        $conditions = is_array($conditions) ? $conditions : [$conditions];
        foreach($conditions as $i => $condition) {
            $conditions[$i] = Strings::trim($condition);
        }
        $this->conditions = $conditions;
        return $this;
    }

    /**
     * Decode a JSON string and return a Rule instance, or null if decoding fails.
     *
     * @param  string|null $json JSON-encoded rule definition.
     * @return Rule|null
     */
    public static function createFromJson($json = null) {
        $object = json_decode($json);
        if(is_object($object)) {
            return new Rule($object);
        }
        return null;
    }

    /**
     * Check whether the given value is a valid rule object.
     *
     * @param  mixed $rule   The value to test.
     * @param  bool  $strict When true, also requires getAction() and getConditions() methods.
     * @return bool
     */
    public static function valid($rule = null, $strict = false) {
        return !empty($rule) && is_object($rule) && property_exists($rule, 'action') && property_exists($rule, 'conditions') &&
           (($strict !== false && method_exists($rule, 'getAction') && method_exists($rule, 'getConditions')) || $strict === false);
    }
}
