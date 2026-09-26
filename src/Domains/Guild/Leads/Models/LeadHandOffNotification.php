<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Baka\Traits\KanvasModelTrait;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Append-only history of handoff notifications, one row per notification sent for a lead.
 */
class LeadHandOffNotification extends Model
{
    use KanvasModelTrait;

    public const ?string UPDATED_AT = null;

    protected $connection = 'crm';

    protected $table = 'lead_handoff_notifications';

    protected $fillable = [
        'apps_id',
        'companies_id',
        'leads_id',
        'sequence',
        'handoff_type',
        'created_at',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'sequence' => 'int',
            'created_at' => 'datetime',
        ];
    }
}
