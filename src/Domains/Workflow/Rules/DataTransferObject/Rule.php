<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Rules\Models\RuleType;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class Rule extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly RuleType $ruleType,
        public readonly SystemModules $systemModule,
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly string $pattern = '1',
        public readonly mixed $params = null,
        public readonly bool $is_async = false,
        #[DataCollectionOf(RuleConditionData::class)]
        public readonly ?DataCollection $conditions = null,
        #[DataCollectionOf(RuleActionData::class)]
        public readonly ?DataCollection $actions = null,
    ) {
    }
}
