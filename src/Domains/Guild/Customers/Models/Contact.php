<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
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

    protected $table = 'peoples_contacts';
    protected $guarded = [];

    #[Override]
    public function casts(): array
    {
        return [
            'value' => 'string',
            'is_opt_out' => 'integer',
            'weight' => 'integer',
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
        return preg_replace('/\D+/', '', $this->value);
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

    public static function normalizeValue(string $value, int $contactsTypesId): string
    {
        $phoneTypes = [
            ContactTypeEnum::PHONE->value,
            ContactTypeEnum::CELLPHONE->value,
            ContactTypeEnum::WORK_PHONE->value,
        ];

        if (in_array($contactsTypesId, $phoneTypes, true)) {
            return (string) preg_replace('/\D/', '', $value);
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
