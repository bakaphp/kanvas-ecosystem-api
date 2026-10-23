<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Workflow\Models\BaseModel;
use Kanvas\Workflow\Rules\Factories\RuleConditionFactory;
use Kanvas\Workflow\Rules\Support\SafeExpression;
use Override;

class RuleCondition extends BaseModel
{
    protected $table = 'rules_conditions';

    protected $guarded = [];

    #[Override]
    protected static function booted(): void
    {
        static::saving(
            fn (self $condition) => SafeExpression::assertCondition(
                sprintf('(%s) %s null', $condition->attribute_name, $condition->operator)
            )
        );
    }

    public function rules(): BelongsTo
    {
        return $this->belongsTo(Rule::class, 'rules_id', 'id');
    }

    protected static function newFactory()
    {
        return RuleConditionFactory::new();
    }
}
