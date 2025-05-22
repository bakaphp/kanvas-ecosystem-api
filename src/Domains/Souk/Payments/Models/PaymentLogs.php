<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Models;

use Kanvas\Souk\Models\BaseModel;

class PaymentLogs extends BaseModel
{
    protected $table = 'payment_logs';
    protected $guarded = [];
}
