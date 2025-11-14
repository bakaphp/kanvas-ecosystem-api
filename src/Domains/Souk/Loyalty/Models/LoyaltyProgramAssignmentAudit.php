<?php

declare(strict_types=1);

namespace Kanvas\Souk\Loyalty\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Souk\Models\BaseModel;
use Kanvas\Souk\Orders\Models\Order;
use Override;

/**
 * @property int $apps_id
 * @property int $users_id
 * @property int $orders_id
 * @property int $loyalty_programs_id
 * @property string $selection_reason
 * @property array|null $matched_conditions
 * @property array|null $alternative_programs
 */
class LoyaltyProgramAssignmentAudit extends BaseModel
{
    use UuidTrait;

    protected $table = 'loyalty_program_assignment_audit';

    protected $fillable = [
        'apps_id',
        'users_id',
        'orders_id',
        'loyalty_programs_id',
        'selection_reason',
        'matched_conditions',
        'alternative_programs',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'matched_conditions' => Json::class,
            'alternative_programs' => Json::class,
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'orders_id');
    }

    public function loyaltyProgram(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'loyalty_programs_id');
    }
}
