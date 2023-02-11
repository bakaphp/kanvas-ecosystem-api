<?php
declare(strict_types=1);

namespace Kanvas\Guild\Customers\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Guild\Models\BaseModel;

/**
 * Class Contacts.
 *
 * @property int $id
 * @property int $contacts_types_id
 * @property int $peoples_id
 * @property string $value
 * @property int $weight
 *
 */
class Contacts extends BaseModel
{
    protected $table = 'peoples_contacts';
    protected $guarded = [];

    public function people() : BelongsTo
    {
        return $this->belongsTo(
            Peoples::class,
            'peoples_id',
            'id'
        );
    }

    public function contactType() : BelongsTo
    {
        return $this->belongsTo(
            ContactTypes::class,
            'contacts_types_id',
            'id'
        );
    }
}
