<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Guild\Customers\Factories\ContactFactory;
use Kanvas\Guild\Models\BaseModel;

/**
 * Class Contacts.
 *
 * @property int $id
 * @property int $contacts_types_id
 * @property int $peoples_id
 * @property string $value
 * @property int $weight
 */
class Contact extends BaseModel
{
    use NoAppRelationshipTrait;
    use NoCompanyRelationshipTrait;

    protected $table = 'peoples_contacts';
    protected $guarded = [];

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

    protected static function newFactory()
    {
        return new ContactFactory();
    }
}
