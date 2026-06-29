<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Expenses\DataTransferObject;

use Spatie\LaravelData\Data;

class ExpenseLine extends Data
{
    public function __construct(
        public readonly string $description,
        public readonly float $amount_native,
        public readonly int $expense_account_id,
        public readonly ?int $item_id = null,
        public readonly ?int $sort_order = null,
        public readonly float $tax_amount_native = 0.0,
        public readonly ?int $class_id = null,
        public readonly ?int $department_id = null,
        public readonly ?array $metadata = null,
    ) {
    }
}
