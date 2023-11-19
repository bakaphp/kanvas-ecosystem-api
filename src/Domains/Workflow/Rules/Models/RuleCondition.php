<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Workflow\Models\BaseModel;

class RuleCondition extends BaseModel
{
    protected $table = 'rules_conditions';

    protected $guarded = [];

    public function rules(): BelongsTo
    {
        return $this->belongsTo(Rule::class, 'rules_id', 'id');
    }
}
