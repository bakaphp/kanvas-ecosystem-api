<?php

declare(strict_types=1);

namespace Kanvas\Guild\LeadSources;

use Kanvas\Guild\Models\BaseModel;

/**
 *  Class LeadSource
 *
 *  @property int $id
 *  @property int $apps_id
 *  @property int $companies_id
 *  @property string $name
 *  @property string $description
 *  @property bool $is_active
 *  @property int $leads_types_id
 *  @property datetime $created_at
 *  @property datetime $updated_at
 *  @property bool $is_deleted
 */
class LeadSource extends BaseModel
{
    protected $table = 'lead_sources';

    protected $guarded = [];

}
