<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Kanvas\Guild\Models\BaseModel;

/**
 * Class LeadOwner.
 *
 * @property int $id
 * @property int $companies_id
 * @property string $firstname
 * @property string $lastname
 * @property string $email
 * @property string $phone
 * @property string $address
 * @property string $created_at
 * @property string $updated_at
 * @property int $is_deleted
 *
 * @deprecated version 2
 */
class LeadOwner extends BaseModel
{
    protected $table = 'leads_owner';
    protected $guarded = [];
}
