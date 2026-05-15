<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleCondition;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Tests\TestCase;

final class RuleExpressionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['workflow'];

    public function testBooleanFalseStringRendersUnquotedAndEvaluatesAgainstBooleanData(): void
    {
        $rule = Rule::factory()->create(['pattern' => '1 and 2']);

        RuleCondition::factory()->create([
            'rules_id' => $rule->getId(),
            'attribute_name' => 'message.message_types_id',
            'operator' => '==',
            'value' => '697',
        ]);

        RuleCondition::factory()->create([
            'rules_id' => $rule->getId(),
            'attribute_name' => "(message.getMessage()['from_me'] ?? false)",
            'operator' => '==',
            'value' => 'false',
        ]);

        ['expression' => $expression] = $rule->getExpressionCondition();

        $this->assertStringContainsString("message.message_types_id == 697", $expression);
        $this->assertStringContainsString("== false", $expression);
        $this->assertStringNotContainsString("== '697'", $expression);
        $this->assertStringNotContainsString("== 'false'", $expression);

        $message = new class () {
            public int $message_types_id = 697;

            public function getMessage(): array
            {
                return ['from_me' => false];
            }
        };

        $result = new ExpressionLanguage()->evaluate($expression, ['message' => $message]);
        $this->assertTrue($result, "Rule expression must evaluate true when from_me is boolean false. Got expression: {$expression}");

        $messageFromMe = new class () {
            public int $message_types_id = 697;

            public function getMessage(): array
            {
                return ['from_me' => true];
            }
        };

        $result = new ExpressionLanguage()->evaluate($expression, ['message' => $messageFromMe]);
        $this->assertFalse($result, 'Rule expression must evaluate false when from_me is boolean true.');
    }

    public function testPlainStringValueStillQuoted(): void
    {
        $rule = Rule::factory()->create(['pattern' => '1']);

        RuleCondition::factory()->create([
            'rules_id' => $rule->getId(),
            'attribute_name' => 'lead.status',
            'operator' => '==',
            'value' => 'won',
        ]);

        ['expression' => $expression] = $rule->getExpressionCondition();

        $this->assertStringContainsString("== 'won'", $expression);
    }
}
