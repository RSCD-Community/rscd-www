<?php

namespace RSCD\Model\Object;

/**
 * A system event record capturing a noteworthy occurrence in the platform.
 *
 * Events are created programmatically whenever the system needs to log a
 * notice, warning, or error. They carry a severity level that indicates the
 * potential impact of the occurrence. Other models (Artwork, File, etc.) attach
 * events to themselves via many-to-many pivot tables so that a complete audit
 * trail can be built per entity without a single monolithic log table.
 *
 * @property int    $id
 * @property string $uuid       UUIDv7, assigned by UniqueModel observer.
 * @property int    $type       One of TYPE_NOTICE, TYPE_ERROR, or TYPE_WARNING.
 * @property int    $severity   One of the SEVERITY_* constants.
 * @property string $message    Human-readable description of the event.
 * @property string $created_at
 * @property string $updated_at
 */
class Event extends \RSCD\Model\Object\Common\Model {

    /** Informational notice — no action required. */
    const TYPE_NOTICE = 1;

    /** An error has occurred that may require intervention. */
    const TYPE_ERROR = 2;

    /** A warning that something unusual happened but is recoverable. */
    const TYPE_WARNING = 3;

    /** Negligible impact; no significant consequence. */
    const SEVERITY_NEGLIGIBLE = 1;

    /** Marginal impact; worth noting but unlikely to cause problems. */
    const SEVERITY_MARGINAL = 2;

    /** Critical impact; immediate attention may be required. */
    const SEVERITY_CRITICAL = 3;

    /** Catastrophic impact; system or data integrity is at risk. */
    const SEVERITY_CATASTROPHIC = 4;

    // -------------------------------------------------------------------------
    // Action codes — structured identifiers for webhook dispatch and UI filtering
    // -------------------------------------------------------------------------

    /** Artwork was created (customer upload or admin create). */
    const ACTION_ARTWORK_CREATED = 'artwork.created';

    /** Artwork file was modified/replaced (upload modification). */
    const ACTION_ARTWORK_MODIFIED = 'artwork.modified';

    /** Artwork status changed (e.g. needs_reviewed → preflight_approved). */
    const ACTION_ARTWORK_STATUS_CHANGED = 'artwork.status_changed';

    /** Artwork preflight was approved. */
    const ACTION_ARTWORK_PREFLIGHT_APPROVED = 'artwork.preflight_approved';

    /** Artwork preflight failed. */
    const ACTION_ARTWORK_PREFLIGHT_FAILED = 'artwork.preflight_failed';

    /** Artwork was submitted for preflight review. */
    const ACTION_ARTWORK_PREFLIGHT_SUBMITTED = 'artwork.preflight_submitted';

    /** Artwork was archived. */
    const ACTION_ARTWORK_ARCHIVED = 'artwork.archived';

    /** Artwork was un-archived. */
    const ACTION_ARTWORK_UNARCHIVED = 'artwork.unarchived';

    /** Artwork was moved/transferred to another customer. */
    const ACTION_ARTWORK_TRANSFERRED = 'artwork.transferred';

    /** Master template was created. */
    const ACTION_MASTER_CREATED = 'master.created';

    /** Derivative artwork was created from a master template. */
    const ACTION_DERIVATIVE_CREATED = 'derivative.created';

    /** Master derivatives contract submitted to ARC for operator processing. */
    const ACTION_DERIVATIVE_CONTRACT_CREATED = 'derivative.contract_created';

    /** Sheet run was exported to hot folder. */
    const ACTION_SHEET_RUN_EXPORTED = 'sheet_run.exported';

    /** Sheet run status changed. */
    const ACTION_SHEET_RUN_STATUS_CHANGED = 'sheet_run.status_changed';

    /** Order was shipped. */
    const ACTION_ORDER_SHIPPED = 'order.shipped';

    /** Proof approval request sent to customer. */
    const ACTION_PROOF_SENT = 'artwork.proof_sent';

    /** Customer approved the proof. */
    const ACTION_PROOF_APPROVED = 'artwork.proof_approved';

    /** Customer rejected the proof. */
    const ACTION_PROOF_REJECTED = 'artwork.proof_rejected';

    /** Proof auto-accepted after timeout. */
    const ACTION_PROOF_AUTO_ACCEPTED = 'artwork.proof_auto_accepted';

    protected $table = 'event';
    protected $columns = [
        'id',
        'uuid',
        'type',
        'severity',
        'action',
        'payload',
        'message',
        'created_at',
        'updated_at'
    ];
    protected $fillable = [
        'uuid',
        'type',
        'severity',
        'action',
        'payload',
        'message'
    ];
    protected $casts = [
        'payload' => 'json',
    ];

    /**
     * Boot the model and register the UniqueModel observer.
     *
     * The observer automatically generates a UUIDv7 for new records.
     */
    public static function boot(){
        parent::boot();
        static::observe(\RSCD\Model\Observer\UniqueModel::class);
    }

    /**
     * Return a map of integer type codes to their human-readable labels.
     *
     * @return array<int, string> Map of type constant → label.
     */
    public static function getTypeArray() {
        return [
            1 => 'Notice',
            2 => 'Error',
            3 => 'Warning'
        ];
    }

    /**
     * Resolve a human-readable type label to its integer constant.
     *
     * Note: the parameter is compared against the values (labels) of the type
     * map, so pass a string like 'Notice', not the integer constant.
     *
     * @param string $type The label string to look up (e.g. "Notice").
     * @return int|string The integer constant, or "Unknown" if not found.
     */
    public static function resolveTypeText($type) {
        $types = self::getTypeArray();
        foreach($types as $id => $typeB) {
            if($typeB == $type) {
                return $id;
            }
        }
        return "Unknown";
    }

    /**
     * Resolve an integer type constant to its human-readable label.
     *
     * @param int $type The integer constant (e.g. self::TYPE_NOTICE).
     * @return string Human-readable label, or "Unknown" if not recognised.
     */
    public static function resolveType($type) {
        $types = self::getTypeArray();
        foreach($types as $id => $typeB) {
            if($id == $type) {
                return $typeB;
            }
        }
        return "Unknown";
    }

    /**
     * Return a map of integer severity codes to their human-readable labels.
     *
     * @return array<int, string> Map of severity constant → label.
     */
    public static function getSeverityArray() {
        return [
            1 => 'Negligible',
            2 => 'Marginal',
            3 => 'Critical',
            4 => 'Catastrophic'
        ];
    }

    /**
     * Resolve a human-readable severity label to its integer constant.
     *
     * @param string $severity The label string to look up (e.g. "Critical").
     * @return int|string The integer constant, or "Unknown" if not found.
     */
    public static function resolveSeverityText($severity) {
        $severities = self::getSeverityArray();
        foreach($severities as $id => $severityB) {
            if($severityB == $severity) {
                return $id;
            }
        }
        return "Unknown";
    }

    /**
     * Resolve an integer severity constant to its human-readable label.
     *
     * @param int $severity The integer constant (e.g. self::SEVERITY_CRITICAL).
     * @return string Human-readable label, or "Unknown" if not recognised.
     */
    public static function resolveSeverity($severity) {
        $severities = self::getSeverityArray();
        foreach($severities as $id => $severityB) {
            if($id == $severity) {
                return $severityB;
            }
        }
        return "Unknown";
    }

    /**
     * Users associated with this event via the user_event pivot.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function users() {
        return $this->belongsToMany('\\RSCD\\Model\\Object\\User', 'user_event');
    }

}
