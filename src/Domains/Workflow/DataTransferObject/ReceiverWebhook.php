<?php

declare(strict_types=1);

namespace Kanvas\Workflow\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Workflow\Models\WorkflowAction;
use Spatie\LaravelData\Data;

class ReceiverWebhook extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly WorkflowAction $action,
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly ?array $configuration = null,
        public readonly bool $is_active = true,
        public readonly bool $run_async = true,
    ) {
    }
}
