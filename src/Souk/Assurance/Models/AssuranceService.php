<?php

declare(strict_types=1);

namespace Kanvas\Souk\Assurance\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Abstracts\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;

class AssuranceService extends Model
{
    protected $table = 'assurance_services';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
        'is_deleted',
    ];

    public function app(): BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }
}
