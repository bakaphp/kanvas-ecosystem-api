<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Baka\Traits\SoftDeletesTrait;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Factories\ContactFactory;
use Kanvas\Guild\Customers\Observers\ContactObserver;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Workflow\Traits\CanUseWorkflow;
use Override;

/**
 * Class Contacts.
 *
 * @property int $id
 * @property int $contacts_types_id
 * @property int $peoples_id
 * @property string $value
 * @property int $is_opt_out
 * @property int $weight
 */
#[ObservedBy(ContactObserver::class)]
class Contact extends BaseModel
{
    use NoAppRelationshipTrait;
    use NoCompanyRelationshipTrait;
    use CanUseWorkflow;
    use SoftDeletesTrait;

    public const DELETED_AT = 'is_deleted';

    public const PHONE_TYPES = [
        ContactTypeEnum::PHONE->value,
        ContactTypeEnum::CELLPHONE->value,
        ContactTypeEnum::WORK_PHONE->value,
    ];

    protected $table = 'peoples_contacts';
    protected $guarded = [];

    public function trashed()
    {
        return (bool) $this->{$this->getDeletedAtColumn()};
    }

    #[Override]
    public function casts(): array
    {
        return [
            'value' => 'string',
            'is_opt_out' => 'integer',
            'weight' => 'integer',
            'is_deleted' => 'boolean',
        ];
    }

    public function people(): BelongsTo
    {
        return $this->belongsTo(
            People::class,
            'peoples_id',
            'id'
        );
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(
            ContactType::class,
            'contacts_types_id',
            'id'
        );
    }

    public function getCleanPhone(): string
    {
        return self::cleanPhone($this->value);
    }

    public static function isPhoneType(int $contactsTypesId): bool
    {
        return in_array($contactsTypesId, self::PHONE_TYPES, true);
    }

    /**
     * Strip a phone to bare digits — the form we STORE (keeps the country code).
     * Single source of truth, used by the observer on write and by normalizeValue on match.
     *
     * Example: "+1 (201) 123-4567" => "12011234567"
     */
    public static function cleanPhone(string $value): string
    {
        return (string) preg_replace('/\D/', '', $value);
    }

    public function opOut(): bool
    {
        $this->is_opt_out = 1;
        $this->saveOrFail();

        return true;
    }

    public function optIn(): bool
    {
        $this->is_opt_out = 0;
        $this->saveOrFail();

        return true;
    }

    public function isOptedOut(): bool
    {
        return $this->is_opt_out === 1;
    }

    /**
     * Canonical form used to MATCH a contact (dedup + lookup) — NOT what we store.
     *  - phone: last 10 digits (so a +1 / formatting can't look like a new number)
     *  - email: lowercased + trimmed
     *  - other: trimmed
     *
     * Example: phone "+1 (201) 123-4567" => "2011234567" ; email " Snow@X.io " => "snow@x.io"
     */
    public static function normalizeValue(string $value, int $contactsTypesId): string
    {
        if (self::isPhoneType($contactsTypesId)) {
            $digits = self::cleanPhone($value);

            // Canonical NANP form: compare on the last 10 digits so a country-code prefix
            // (e.g. +1) or local formatting can't make the same number look like a new contact.
            return strlen($digits) > 10 ? substr($digits, -10) : $digits;
        }

        if ($contactsTypesId === ContactTypeEnum::EMAIL->value) {
            return strtolower(trim($value));
        }

        return trim($value);
    }

    #[Override]
    protected static function newFactory()
    {
        return new ContactFactory();
    }
}
