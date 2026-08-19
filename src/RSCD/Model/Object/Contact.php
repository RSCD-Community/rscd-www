<?php

namespace RSCD\Model\Object;

use RSCD\Model\Mutator;
use RSCD\Util\Strings;

/**
 * Contact model — stores personal and address information for a person.
 *
 * Contacts are shared across multiple domain objects: a User has a primary
 * contact (plus optionally emergency contacts), and the same Contact class
 * may be reused for customers, vendors, and other addressable parties.
 *
 * All sensitive address fields (street lines, city, state, postal code,
 * country, attention, and title) are stored encrypted + encoded in the
 * database. Eloquent get/set mutators transparently decrypt on read and
 * encrypt on write so callers always work with plain-text values.
 *
 * The 'required' array on email_address, phone_number, mobile_number, and
 * fax_number means at least one of the listed fields must be present — the
 * validation framework treats the group as "one-of-many required".
 *
 * Table: contact
 */
class Contact extends \RSCD\Model\Object\Common\Model {

    /**
     * Column metadata used by the framework for validation and display.
     *
     * @var array<string, array<string, mixed>>
     */
    const COLUMN_FORMAT = [
        'id' => ['type' => 'integer', 'name' => 'ID', 'min' => 0, 'max' => self::SIGNED_INT_32],
        'uuid' => ['type' => 'string', 'name' => 'UUID', 'minlength' => 36, 'maxlength' => 36],
        'user_id' => ['type' => 'integer', 'name' => 'User ID', 'min' => 0, 'max' => self::SIGNED_INT_32],
        'type' => ['type' => 'string', 'name' => 'Type', 'maxlength' => 32],
        'name' => ['type' => 'string', 'name' => 'Name', 'maxlength' => 256, 'required' => true],
        'title' => ['type' => 'string', 'name' => 'Title', 'maxlength' => 128],
        'street1' => ['type' => 'string', 'name' => 'Street 1', 'maxlength' => 128],
        'street2' => ['type' => 'string', 'name' => 'Street 2', 'maxlength' => 128],
        'street3' => ['type' => 'string', 'name' => 'Street 3', 'maxlength' => 128],
        'city' => ['type' => 'string', 'name' => 'City', 'maxlength' => 128],
        'state' => ['type' => 'state_code', 'name' => 'State', 'maxlength' => 128],
        'postal_code' => ['type' => 'string', 'name' => 'Postal code', 'maxlength' => 128],
        'country' => ['type' => 'country_code', 'name' => 'Country', 'maxlength' => 128],
        'attention' => ['type' => 'string', 'name' => 'Attention', 'maxlength' => 256],
        // At least one contact method is required — the 'required' array lists acceptable alternatives
        'email_address' => ['type' => 'email_address', 'name' => 'Email address', 'maxlength' => 256, 'required' => ['phone_number', 'mobile_number', 'fax_number']],
        'phone_number' => ['type' => 'phone_number', 'name' => 'Phone number', 'maxlength' => 32, 'required' => ['email_address', 'mobile_number', 'fax_number']],
        'mobile_number' => ['type' => 'phone_number', 'name' => 'Mobile number', 'maxlength' => 32, 'required' => ['phone_number', 'email_address', 'fax_number']],
        'fax_number' => ['type' => 'phone_number', 'name' => 'Fax number', 'maxlength' => 32, 'required' => ['phone_number', 'mobile_number', 'email_address']],
        'is_default' => ['type' => 'integer', 'name' => 'Is default', 'maxlength' => 1, 'min' => 0, 'max' => 1],
        'ups_account_number' => ['type' => 'string', 'name' => 'UPS account number', 'maxlength' => 6],
        'fedex_account_number' => ['type' => 'string', 'name' => 'FedEx account number', 'maxlength' => 9],
        'created_at' => ['type' => 'datetime', 'name' => 'Created at'],
        'updated_at' => ['type' => 'datetime', 'name' => 'Updated at']
    ];

    /**
     * The underlying database table.
     *
     * @var string
     */
    protected $table = 'contact';

    /**
     * All columns on this table.
     *
     * @var string[]
     */
    protected $columns = [
        'id',
        'uuid',
        'user_id',
        'type',
        'name',
        'title',
        'street1',
        'street2',
        'street3',
        'city',
        'state',
        'postal_code',
        'country',
        'attention',
        'email_address',
        'phone_number',
        'mobile_number',
        'fax_number',
        'is_default',
        'ups_account_number',
        'fedex_account_number',
        'variable_data',
        'created_at',
        'updated_at'
    ];

    /**
     * Mass-assignable columns.
     *
     * @var string[]
     */
    protected $fillable = [
        'uuid',
        'user_id',
        'type',
        'name',
        'title',
        'street1',
        'street2',
        'street3',
        'city',
        'state',
        'postal_code',
        'country',
        'attention',
        'email_address',
        'phone_number',
        'mobile_number',
        'fax_number',
        'is_default',
        'ups_account_number',
        'fedex_account_number',
        'variable_data'
    ];

    /**
     * Column type casts.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'variable_data' => 'array',
    ];

    /**
     * Columns hidden from serialisation.
     *
     * @var string[]
     */
    protected $hidden = [];

    /**
     * Boot the model and register the UniqueModel observer.
     *
     * Auto-generates a UUID on record creation.
     *
     * @return void
     */
    public static function boot(){
        parent::boot();
        static::observe(\RSCD\Model\Observer\UniqueModel::class);
    }

    /**
     * Scope: filter to only the default contact record (is_default = 1).
     *
     * Used by User::contact() via ->default() to return the primary contact.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDefault($query) {
        return $query->where('is_default', 1);
    }

    /**
     * The user this contact belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user() {
        return $this->belongsTo('\\RSCD\\Model\\Object\\User', 'user_id');
    }

    /**
     * Events associated with this contact via the contact_event pivot.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function events() {
        return $this->belongsToMany('\\RSCD\\Model\\Object\\Event', 'contact_event');
    }

    /**
     * Accessor: decrypt and decode the title field.
     *
     * Returns null when the stored value is empty to distinguish between
     * "not set" and an empty string in display logic.
     *
     * @return string|null  Plain-text title, or null if not set.
     */
    public function getTitleAttribute() {
        if(empty($this->attributes['title'])) {
          return null;
        }
        return Mutator::decrypt(Mutator::decode($this->attributes['title']));
    }

    /**
     * Mutator: encrypt and encode the title field before storing.
     *
     * Stores an empty string (encrypted) rather than null to keep the
     * column type consistent.
     *
     * @param  string  $value  Plain-text title.
     * @return void
     */
    public function setTitleAttribute($value) {
        if(empty($value)) {
            $value = '';
        }
        $this->attributes['title'] = Mutator::encode(Mutator::encrypt($value));
    }

    /**
     * Accessor: decrypt and decode the street1 field.
     *
     * @return string|null  Plain-text street line 1, or null if not set.
     */
    public function getStreet1Attribute() {
        if(empty($this->attributes['street1'])) {
          return null;
        }
        return Mutator::decrypt(Mutator::decode($this->attributes['street1']));
    }

    /**
     * Mutator: encrypt and encode street1 before storing.
     *
     * @param  string  $value  Plain-text street line 1.
     * @return void
     */
    public function setStreet1Attribute($value) {
        if(empty($value)) {
            $value = '';
        }
        $this->attributes['street1'] = Mutator::encode(Mutator::encrypt($value));
    }

    /**
     * Accessor: decrypt and decode the street2 field.
     *
     * @return string|null  Plain-text street line 2, or null if not set.
     */
    public function getStreet2Attribute() {
        if(empty($this->attributes['street2'])) {
          return null;
        }
        return Mutator::decrypt(Mutator::decode($this->attributes['street2']));
    }

    /**
     * Mutator: encrypt and encode street2 before storing.
     *
     * @param  string  $value  Plain-text street line 2.
     * @return void
     */
    public function setStreet2Attribute($value) {
        if(empty($value)) {
            $value = '';
        }
        $this->attributes['street2'] = Mutator::encode(Mutator::encrypt($value));
    }

    /**
     * Accessor: decrypt and decode the street3 field.
     *
     * @return string|null  Plain-text street line 3, or null if not set.
     */
    public function getStreet3Attribute() {
        if(empty($this->attributes['street3'])) {
          return null;
        }
        return Mutator::decrypt(Mutator::decode($this->attributes['street3']));
    }

    /**
     * Mutator: encrypt and encode street3 before storing.
     *
     * @param  string  $value  Plain-text street line 3.
     * @return void
     */
    public function setStreet3Attribute($value) {
        if(empty($value)) {
            $value = '';
        }
        $this->attributes['street3'] = Mutator::encode(Mutator::encrypt($value));
    }

    /**
     * Accessor: decrypt and decode the city field.
     *
     * @return string|null  Plain-text city name, or null if not set.
     */
    public function getCityAttribute() {
        if(empty($this->attributes['city'])) {
          return null;
        }
        return Mutator::decrypt(Mutator::decode($this->attributes['city']));
    }

    /**
     * Mutator: encrypt and encode city before storing.
     *
     * @param  string  $value  Plain-text city name.
     * @return void
     */
    public function setCityAttribute($value) {
        if(empty($value)) {
            $value = '';
        }
        $this->attributes['city'] = Mutator::encode(Mutator::encrypt($value));
    }

    /**
     * Accessor: decrypt and decode the state field.
     *
     * @return string|null  Plain-text state code, or null if not set.
     */
    public function getStateAttribute() {
        if(empty($this->attributes['state'])) {
          return null;
        }
        return Mutator::decrypt(Mutator::decode($this->attributes['state']));
    }

    /**
     * Mutator: encrypt and encode state before storing.
     *
     * @param  string  $value  Plain-text state code.
     * @return void
     */
    public function setStateAttribute($value) {
        if(empty($value)) {
            $value = '';
        }
        $this->attributes['state'] = Mutator::encode(Mutator::encrypt($value));
    }

    /**
     * Accessor: decrypt and decode the postal_code field.
     *
     * @return string|null  Plain-text postal code, or null if not set.
     */
    public function getPostalCodeAttribute() {
        if(empty($this->attributes['postal_code'])) {
          return null;
        }
        return Mutator::decrypt(Mutator::decode($this->attributes['postal_code']));
    }

    /**
     * Mutator: encrypt and encode postal_code before storing.
     *
     * @param  string  $value  Plain-text postal code.
     * @return void
     */
    public function setPostalCodeAttribute($value) {
        if(empty($value)) {
            $value = '';
        }
        $this->attributes['postal_code'] = Mutator::encode(Mutator::encrypt($value));
    }

    /**
     * Accessor: decrypt and decode the country field.
     *
     * @return string|null  Plain-text country code, or null if not set.
     */
    public function getCountryAttribute() {
        if(empty($this->attributes['country'])) {
          return null;
        }
        return Mutator::decrypt(Mutator::decode($this->attributes['country']));
    }

    /**
     * Mutator: encrypt and encode country before storing.
     *
     * @param  string  $value  Plain-text country code.
     * @return void
     */
    public function setCountryAttribute($value) {
        if(empty($value)) {
            $value = '';
        }
        $this->attributes['country'] = Mutator::encode(Mutator::encrypt($value));
    }

    /**
     * Accessor: decrypt and decode the attention field.
     *
     * The 'attention' field holds a person or department name for shipping
     * label "Attn:" lines.
     *
     * @return string|null  Plain-text attention line, or null if not set.
     */
    public function getAttentionAttribute() {
        if(empty($this->attributes['attention'])) {
          return null;
        }
        return Mutator::decrypt(Mutator::decode($this->attributes['attention']));
    }

    /**
     * Mutator: encrypt and encode attention before storing.
     *
     * @param  string  $value  Plain-text attention line.
     * @return void
     */
    public function setAttentionAttribute($value) {
        if(empty($value)) {
            $value = '';
        }
        $this->attributes['attention'] = Mutator::encode(Mutator::encrypt($value));
    }

    /**
     * Build a formatted multi-line HTML address string from a Contact object.
     *
     * Each address component is followed by '<br />' or a separator and is
     * omitted if empty or blank. The result is intended for display in HTML
     * contexts (invoices, packing slips, etc.).
     *
     * @param  \RSCD\Model\Object\Contact  $contact  Contact instance to format.
     * @return string                                                 HTML address string (may be empty).
     */
    public static function getFullAddress($contact) {
        return (!empty($contact->street1) && strlen(Strings::trim($contact->street1)) > 0 ? $contact->street1 . '<br />' : '')
            . (!empty($contact->street2) && strlen(Strings::trim($contact->street2)) > 0 ? $contact->street2 . '<br />' : '')
            . (!empty($contact->street3) && strlen(Strings::trim($contact->street3)) > 0 ? $contact->street3 . '<br />' : '')
            . (!empty($contact->city) ? $contact->city . ', ' : '')
            . (!empty($contact->state) ? $contact->state . ' ' : '')
            . (!empty($contact->postal_code) ? $contact->postal_code . ' ' : '')
            . (!empty($contact->country) ? $contact->country . ' ' : '');
    }

}
