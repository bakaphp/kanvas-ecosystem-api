<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules;

use Generator;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Workflow\Rules\Models\Rule;
use Workflow\ActivityStub;
use Workflow\Workflow;

class DynamicRuleWorkflow extends Workflow
{
    public function execute(Rule $rule, Model $mode, array $params): Generator
    {
        //$result = yield ActivityStub::make(ZohoLeadActivity::class, $lead);

        //return $result;
    }
}
