<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleCondition;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testInOperatorWithStoredArrayStringBuildsArrayLiteralAndEvaluates(): void
    {
        $rule = Rule::factory()->create(['pattern' => '1']);

        // stored value comes back from the DB as a plain string (no cast on `value`)
        RuleCondition::factory()->create([
            'rules_id' => $rule->getId(),
            'attribute_name' => 'message_types_id',
            'operator' => 'in',
            'value' => '[1, 2]',
        ]);

        ['expression' => $expression] = $rule->getExpressionCondition();

        $this->assertStringContainsString('message_types_id in [1, 2]', $expression);
        $this->assertStringNotContainsString("in '[1", $expression);

        $matches = new ExpressionLanguage()->evaluate($expression, ['message_types_id' => 2]);
        $this->assertTrue($matches, "`in` must not crash and must match a value in the set. Got: {$expression}");

        $noMatch = new ExpressionLanguage()->evaluate($expression, ['message_types_id' => 3]);
        $this->assertFalse($noMatch);
    }

    public function testNotInOperatorWithCommaSeparatedStringEvaluates(): void
    {
        $rule = Rule::factory()->create(['pattern' => '1']);

        RuleCondition::factory()->create([
            'rules_id' => $rule->getId(),
            'attribute_name' => 'status',
            'operator' => 'not in',
            'value' => 'won, lost',
        ]);

        ['expression' => $expression] = $rule->getExpressionCondition();

        $this->assertStringContainsString("status not in ['won', 'lost']", $expression);

        $result = new ExpressionLanguage()->evaluate($expression, ['status' => 'open']);
        $this->assertTrue($result);

        $result = new ExpressionLanguage()->evaluate($expression, ['status' => 'won']);
        $this->assertFalse($result);
    }

    /**
     * Every operator exposed in the "Add Condition" picker must build a valid expression
     * and evaluate without crashing. `attr` resolves to 5 in the pass rows.
     *
     */
    #[DataProvider('operatorProvider')]
    public function testEveryPickerOperatorBuildsAndEvaluates(string $operator, string $value, array $data, bool $expected): void
    {
        $rule = Rule::factory()->create(['pattern' => '1']);

        RuleCondition::factory()->create([
            'rules_id' => $rule->getId(),
            'attribute_name' => 'attr',
            'operator' => $operator,
            'value' => $value,
        ]);

        ['expression' => $expression] = $rule->getExpressionCondition();

        // `matches` returns preg_match's int (1/0); the workflow gate is `if (! $result)`,
        // so truthiness is what matters, not strict bool identity.
        $result = new ExpressionLanguage()->evaluate($expression, $data);
        $this->assertSame($expected, (bool) $result, "Operator [{$operator}] produced: {$expression}");
    }

    public static function operatorProvider(): array
    {
        return [
            'equal true' => ['==', '5', ['attr' => 5], true],
            'equal false' => ['==', '5', ['attr' => 4], false],
            'not equal true' => ['!=', '5', ['attr' => 4], true],
            'not equal false' => ['!=', '5', ['attr' => 5], false],
            'greater than true' => ['>', '5', ['attr' => 6], true],
            'greater than false' => ['>', '5', ['attr' => 5], false],
            'greater or equal true' => ['>=', '5', ['attr' => 5], true],
            'greater or equal false' => ['>=', '5', ['attr' => 4], false],
            'less than true' => ['<', '5', ['attr' => 4], true],
            'less than false' => ['<', '5', ['attr' => 5], false],
            'less or equal true' => ['<=', '5', ['attr' => 5], true],
            'less or equal false' => ['<=', '5', ['attr' => 6], false],
            'in true' => ['in', '[4, 5]', ['attr' => 5], true],
            'in false' => ['in', '[4, 6]', ['attr' => 5], false],
            'not in true' => ['not in', '[4, 6]', ['attr' => 5], true],
            'not in false' => ['not in', '[4, 5]', ['attr' => 5], false],
            'matches true' => ['matches', '/^ab/', ['attr' => 'abc'], true],
            'matches false' => ['matches', '/^ab/', ['attr' => 'xyz'], false],
        ];
    }
}
