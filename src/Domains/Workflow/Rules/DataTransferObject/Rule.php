<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Rules\Models\RuleType;
use Spatie\LaravelData\Data;

class Rule extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly SystemModules $systemModule,
        public readonly RuleType $ruleType,
        public readonly string $name,
        public readonly string $pattern,
        public readonly ?string $description = null,
        public readonly array $params = [],
        public readonly bool $is_async = true,
        public readonly array $conditions = [],
        public readonly array $actions = [],
    ) {
    }
}
