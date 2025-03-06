<?php

declare(strict_types=1);

namespace App\GraphQL\Workflow\Queries;

use Kanvas\Workflow\Enums\ReceiversStatusEnum;

class ReceiverStatusQuery
{
    public function __invoke(): array
    {
        return array_column(ReceiversStatusEnum::cases(), 'value');
    }
}
