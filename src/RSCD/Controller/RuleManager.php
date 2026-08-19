<?php

namespace RSCD\Controller;

use \RSCD\Model\RulePolicy;

/**
 * Static access-control rule evaluator.
 *
 * Maintains a registry of RulePolicy objects and a condition list. After
 * evaluating all policies, it stores the set of allowed conditions so that
 * controllers can call isAllowed() to check specific permissions.
 *
 * Usage:
 *   1. Call setConditionList() with all known permission slugs.
 *   2. Call setRulePolicy() one or more times to register policies.
 *      (evaluateConditionList() is called automatically after each batch.)
 *   3. Call isAllowed($slug) to check a specific permission.
 */
class RuleManager {

    protected static $policies;
    protected static $conditionList;
    protected static $allowedConditions;

    const VERDICT_NONE = 0;
    const VERDICT_ALLOW = 1;
    const VERDICT_DENY = 2;

    /**
     * Check whether a named condition is in the current allowed-conditions list.
     *
     * @param string $condition The condition slug to check.
     * @return bool True if the condition is allowed; false otherwise.
     */
    public static function isAllowed($condition) {
        if(!isset(self::$allowedConditions)) {
            return false;
        }
        return in_array($condition, self::$allowedConditions);
    }

    /**
     * Re-evaluate all conditions in the condition list against the registered policies.
     *
     * Rebuilds $allowedConditions by calling evaluate() for each condition.
     * Returns false when no condition list has been set.
     *
     * @return bool True on success; false if no condition list is set.
     */
    public static function evaluateConditionList() {
        self::$allowedConditions = [];
        if(!isset(self::$conditionList)) {
            return false;
        }
        foreach(self::$conditionList as $condition) {
            if(self::evaluate($condition)) {
                self::$allowedConditions[] = $condition;
            }
        }
        return true;
    }

    /**
     * Evaluate a single condition against all registered policies.
     *
     * Each policy returns an ALLOW, DENY, or NONE verdict with a magnitude.
     * The condition is allowed when the highest ALLOW magnitude exceeds the
     * highest DENY magnitude. Returns false when no policies are registered.
     *
     * @param string $condition The condition slug to evaluate.
     * @return bool True if the condition is net-allowed; false otherwise.
     */
    public static function evaluate($condition) {
        if(empty($condition)) {
            return false;
        }
        if(!isset(self::$policies)) {
            return false;
        }
        $verdicts = [];
        foreach(self::$policies as $policy) {
            if(!RulePolicy::valid($policy, true)) {
                continue;
            }
            $allow = $policy->allow($condition);
            $deny = $policy->deny($condition);
            if($allow > $deny && $allow > 0) {
                $verdicts[] = (object)[
                    'result' => static::VERDICT_ALLOW,
                    'magnitude' => $allow
                ];
            }
            else if($deny > 0) {
                $verdicts[] = (object)[
                    'result' => static::VERDICT_DENY,
                    'magnitude' => $deny
                ];
            }
            else {
                $verdicts[] = (object)[
                    'result' => static::VERDICT_NONE,
                    'magnitude' => 0
                ];
            }
        }
        $allowed = 0;
        $denied = 0;
        foreach($verdicts as $verdict) {
            if($verdict->result === static::VERDICT_ALLOW && $verdict->magnitude > $allowed) {
                $allowed = $verdict->magnitude;
            }
            if($verdict->result === static::VERDICT_DENY && $verdict->magnitude > $denied) {
                $denied = $verdict->magnitude;
            }
        }
        if($allowed  > $denied) {
            return true;
        }
        return false;
    }

    /**
     * Return the current allowed-conditions list (evaluated conditions only).
     *
     * @return array List of allowed condition slugs, or empty array if not set.
     */
    public static function getAllowedConditions() {
        if(!isset(self::$allowedConditions)) {
            return [];
        }
        return self::$allowedConditions;
    }

    /**
     * Return the full condition list as set by setConditionList().
     *
     * @return array All registered condition slugs, or empty array if not set.
     */
    public static function getConditionList() {
        if(!isset(self::$conditionList)) {
            return [];
        }
        return self::$conditionList;
    }

    /**
     * Return a registered RulePolicy by name.
     *
     * @param string|null $name The policy name to look up.
     * @return \RSCD\Model\RulePolicy|null The policy, or null if not found.
     */
    public static function getRulePolicy($name = null) {
        if(!empty($name) && isset(self::$policies[$name])) {
            return self::$policies[$name];
        }
        return null;
    }

    /**
     * Replace the full condition list used during evaluation.
     *
     * @param array|null $conditionList Array of condition slugs.
     * @return void
     */
    public static function setConditionList($conditionList = null) {
        self::$conditionList = $conditionList;
    }

    /**
     * Register one or more RulePolicy objects and re-evaluate the condition list.
     *
     * Accepts a single policy object/JSON string or an array thereof. Policies
     * are stored by name; invalid or unnamed policies are silently skipped.
     * evaluateConditionList() is called automatically after all policies are added.
     *
     * @param mixed $policy A RulePolicy object, JSON string, or array of either.
     * @return void
     */
    public static function setRulePolicy($policy = null) {
        $policies = is_array($policy) ? $policy : [$policy];
        foreach($policies as $policy) {
            if(!is_object($policy)) {
                $policy = RulePolicy::createFromJson($policy);
            }
            if(!RulePolicy::valid($policy, true)) {
                continue;
            }
            $name = $policy->getName();
            if(empty($name)) {
                continue;
            }
            if(!isset(self::$policies)) {
                self::$policies = [];
            }
            self::$policies[$name] = $policy;
        }
        self::evaluateConditionList();
    }

    /**
     * Reset all registered policies, conditions, and allowed-conditions to empty arrays.
     *
     * @return void
     */
    public static function reset() {
        self::$policies = [];
        self::$conditionList = [];
        self::$allowedConditions = [];
    }
}
