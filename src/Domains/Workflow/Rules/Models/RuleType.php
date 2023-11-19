<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Models;

use Kanvas\Workflow\Models\BaseModel;

class RuleType extends BaseModel
{
    protected $table = 'rules_types';

    protected $guarded = [];
}
