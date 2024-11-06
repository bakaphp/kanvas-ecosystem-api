<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Orders;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;

class PaymentManagementMutation
{
    public function processPayment(mixed $root, array $request): array
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $branch = app(CompaniesBranches::class);

        return [
            'status' => 'success',
            'transaction_id' => Str::uuid(),
            'order_status' => 'paid',
            'message' => 'Payment processed successfully',
        ];
    }
}
