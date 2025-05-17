<?php

declare(strict_types=1);

namespace Kanvas\Payments\Models;

use Baka\Casts\Json;
use Kanvas\Models\BaseModel;
use Kanvas\Workflow\Traits\CanUseWorkflow;

/**
 * Class Order
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_groups_id
 * @property int $users_id
 * @property int $stripe_card_id
 * @property int $payment_methods_id
 * @property string $payment_ending_numbers
 * @property string $payment_methods_brand
 * @property string $expiration_date
 * @property string $zip_code
 * @property boolean $is_default
 * @property boolean $is_deleted
 * @property string|null $processor
 * @property string|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */

class PaymentMethods extends BaseModel
{
    use CanUseWorkflow;

    protected $table = 'payment_methods_credentials';
    protected $guarded = [];

    protected $casts = [
        'metadata' => Json::class,
    ];
}
