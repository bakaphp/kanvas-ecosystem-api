<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Models;

use Kanvas\Workflow\Models\BaseModel;
use Kanvas\Workflow\Rules\Factories\RuleTypeFactory;
use Override;

class RuleType extends BaseModel
{
    protected $table = 'rules_types';

    protected $guarded = [];

    #[Override]
    protected static function newFactory()
    {
        return RuleTypeFactory::new();
    }
}
