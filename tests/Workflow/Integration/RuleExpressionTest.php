<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleCondition;
use Kanvas\Workflow\Rules\Support\ExpressionData;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
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

        $this->assertStringContainsString('message.message_types_id == 697', $expression);
        $this->assertStringContainsString('== false', $expression);
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

    public function testDotNotationTraversesNestedJsonArrayWhenWrapped(): void
    {
        // Reproduces Sentry KANVAS-ECOSYSTEM-5VJ: a rule condition `metadata.data.user_company_id != 0`
        // against an entity whose `metadata` JSON column surfaces as a plain PHP array.
        $rule = Rule::factory()->create(['pattern' => '1']);

        RuleCondition::factory()->create([
            'rules_id' => $rule->getId(),
            'attribute_name' => 'metadata.data.user_company_id',
            'operator' => '!=',
            'value' => '0',
        ]);

        ['expression' => $expression] = $rule->getExpressionCondition();

        $this->assertStringContainsString('metadata.data.user_company_id != 0', $expression);

        $matches = new ExpressionLanguage()->evaluate($expression, [
            'metadata' => new ExpressionData(['data' => ['user_company_id' => 5]]),
        ]);
        $this->assertTrue($matches, "Dot navigation into wrapped JSON must resolve. Got: {$expression}");

        $noMatch = new ExpressionLanguage()->evaluate($expression, [
            'metadata' => new ExpressionData(['data' => ['user_company_id' => 0]]),
        ]);
        $this->assertFalse($noMatch);
    }

    public function testDotNotationOnMissingNestedKeyReturnsNullInsteadOfThrowing(): void
    {
        $data = new ExpressionData(['data' => []]);

        $result = new ExpressionLanguage()->evaluate('metadata.data.user_company_id', ['metadata' => $data]);

        $this->assertNull($result);
    }

    public function testBracketNotationStillWorksOnWrappedData(): void
    {
        $data = new ExpressionData(['data' => ['user_company_id' => 7]]);

        $result = new ExpressionLanguage()->evaluate("metadata['data']['user_company_id']", ['metadata' => $data]);

        $this->assertSame(7, $result);
    }

    public function testBracketNotationRuleBuildsAndEvaluatesThroughFullPipeline(): void
    {
        // Full path: stored RuleCondition -> getExpressionCondition() string build -> evaluate,
        // mirroring exactly how a bracket-authored rule is persisted and rendered in production.
        $rule = Rule::factory()->create(['pattern' => '1']);

        RuleCondition::factory()->create([
            'rules_id' => $rule->getId(),
            'attribute_name' => "metadata['data']['user_company_id']",
            'operator' => '!=',
            'value' => '0',
        ]);

        ['expression' => $expression] = $rule->getExpressionCondition();

        $this->assertStringContainsString("metadata['data']['user_company_id'] != 0", $expression);

        $matches = new ExpressionLanguage()->evaluate($expression, [
            'metadata' => new ExpressionData(['data' => ['user_company_id' => 5]]),
        ]);
        $this->assertTrue($matches, "Bracket navigation must resolve through the built expression. Got: {$expression}");

        $noMatch = new ExpressionLanguage()->evaluate($expression, [
            'metadata' => new ExpressionData(['data' => ['user_company_id' => 0]]),
        ]);
        $this->assertFalse($noMatch);
    }

    public function testUnquotedBracketAttributeIsNormalisedAndEvaluates(): void
    {
        // getExpressionCondition() rewrites [data] -> ['data'] so an unquoted stored attribute
        // still produces a valid Symfony array-access expression.
        $rule = Rule::factory()->create(['pattern' => '1']);

        RuleCondition::factory()->create([
            'rules_id' => $rule->getId(),
            'attribute_name' => 'metadata[data][user_company_id]',
            'operator' => '!=',
            'value' => '0',
        ]);

        ['expression' => $expression] = $rule->getExpressionCondition();

        $this->assertStringContainsString("metadata['data']['user_company_id'] != 0", $expression);

        $matches = new ExpressionLanguage()->evaluate($expression, [
            'metadata' => new ExpressionData(['data' => ['user_company_id' => 5]]),
        ]);
        $this->assertTrue($matches);
    }

    /**
     * Locks in the CURRENT production rule 610 shape after switching `!=` to `>`:
     * a real company id matches, zero does not, and — critically — missing/null metadata does NOT
     * match (null > 0 is false), which is the whole reason `>` was chosen over `!=`.
     */
    public function testGreaterThanRuleIsNullSafeForMissingCompanyId(): void
    {
        $rule = Rule::factory()->create(['pattern' => '1']);

        RuleCondition::factory()->create([
            'rules_id' => $rule->getId(),
            'attribute_name' => 'metadata.data.user_company_id',
            'operator' => '>',
            'value' => '0',
        ]);

        ['expression' => $expression] = $rule->getExpressionCondition();

        $this->assertStringContainsString('metadata.data.user_company_id > 0', $expression);

        $present = new ExpressionLanguage()->evaluate($expression, [
            'metadata' => new ExpressionData(['data' => ['user_company_id' => 5]]),
        ]);
        $this->assertTrue($present, 'A real non-zero company id must match.');

        $zero = new ExpressionLanguage()->evaluate($expression, [
            'metadata' => new ExpressionData(['data' => ['user_company_id' => 0]]),
        ]);
        $this->assertFalse($zero, 'Zero must not match.');

        $missing = new ExpressionLanguage()->evaluate($expression, [
            'metadata' => new ExpressionData(['data' => []]),
        ]);
        $this->assertFalse($missing, 'Missing/null company id must NOT match with the > operator.');
    }

    public function testWrappedPlainNestedArrayResolvesWithDotAndBracket(): void
    {
        $el = new ExpressionLanguage();
        $profile = new ExpressionData([
            'address' => ['city' => 'NY', 'geo' => ['lat' => 40, 'lng' => -73]],
        ]);

        $this->assertSame('NY', $el->evaluate('profile.address.city', ['profile' => $profile]));
        $this->assertSame('NY', $el->evaluate("profile['address']['city']", ['profile' => $profile]));
        $this->assertSame(40, $el->evaluate('profile.address.geo.lat', ['profile' => $profile]));
        $this->assertSame(-73, $el->evaluate("profile['address']['geo']['lng']", ['profile' => $profile]));
    }

    public function testMixedDotAndBracketOnSameWrappedValue(): void
    {
        $el = new ExpressionData(['data' => ['user_company_id' => 42]]);

        $this->assertSame(42, new ExpressionLanguage()->evaluate("meta.data['user_company_id']", ['meta' => $el]));
        $this->assertSame(42, new ExpressionLanguage()->evaluate("meta['data'].user_company_id", ['meta' => $el]));
    }

    public function testObjectPropertyMethodAndBracketIntoArrayAllWork(): void
    {
        $el = new ExpressionLanguage();

        $order = new class () {
            public string $status = 'won';
            public array $metadata = ['data' => ['user_company_id' => 9]];

            public function total(): int
            {
                return 100;
            }

            public function getMessage(): array
            {
                return ['from_me' => false];
            }
        };

        $values = ['order' => $order];

        $this->assertSame('won', $el->evaluate('order.status', $values), 'object property access');
        $this->assertSame(100, $el->evaluate('order.total()', $values), 'object method call');
        $this->assertFalse($el->evaluate("order.getMessage()['from_me']", $values), 'method returning array + bracket');
        $this->assertSame(9, $el->evaluate("order.metadata['data']['user_company_id']", $values), 'object -> JSON array via bracket');
    }

    /**
     * Documented boundary: reaching INTO a raw JSON/array field THROUGH an entity object with dot
     * syntax (order.metadata.data.x) is NOT supported — the model returns a plain array and Symfony's
     * `.` needs an object. Author these as top-level (metadata.data.x, which the workflow flattens and
     * wraps) or with bracket (order.metadata['data']['x']). This test pins the boundary so a future
     * change that closes it also updates the guidance.
     */
    public function testDotThroughObjectIntoRawArrayThrows(): void
    {
        $order = new class () {
            public array $metadata = ['data' => ['user_company_id' => 9]];
        };

        $this->expectException(RuntimeException::class);
        new ExpressionLanguage()->evaluate('order.metadata.data.user_company_id', ['order' => $order]);
    }

    /**
     * Compliance guard for every distinct condition SHAPE currently live in production
     * (rules_conditions dump, Jul 2026). Each expression is evaluated through the exact
     * ExpressionData::wrapValues() the workflow applies, proving the array-wrapping change does not
     * regress scalar comparisons, object property/method access, relation chains, or method-return
     * bracket navigation — and that top-level JSON dot-navigation (the KANVAS-ECOSYSTEM-5VJ bug) works.
     */
    public function testAllProductionConditionShapesStillEvaluate(): void
    {
        $message = new class () {
            public int $message_types_id = 572;

            public function getMessage(): array
            {
                return ['status' => 'submitted', 'from_me' => false, 'from_human' => true];
            }
        };

        $order = new class () {
            public array $meta = [
                'source' => 'B2B',
                'affiliate_id' => 'AFF123',
                'esims' => [['data' => ['insurancePendingData' => [['insurance' => 'ACME']]]]],
            ];

            public function getMetadata(string $key): mixed
            {
                return $this->meta[$key] ?? null;
            }
        };

        $company = new class () {
            public function get(string $key): mixed
            {
                return $key === 'use_global_workflows' ? 1 : null;
            }
        };

        $receiver = new class () {
            public int $id = 449;
        };

        $lead = new class ($company, $receiver) {
            public function __construct(public object $company, public object $receiver)
            {
            }

            public function get(string $key): mixed
            {
                return $key === 'driver_license_images' ? 'https://img' : null;
            }
        };

        $channel = new class () {
            public string $name = 'Notes';
        };

        $values = ExpressionData::wrapValues([
            'id' => 5,
            'message_types_id' => 572,
            'verb' => 'trade-walk',
            'slug' => 'wa-inbound',
            'companies_id' => 2,
            'name' => 'Sales',
            'order_types_id' => 2,
            'communication_channel' => 'sms',
            'metadata' => ['data' => ['user_company_id' => 9]],
            'message' => $message,
            'order' => $order,
            'lead' => $lead,
            'channel' => $channel,
        ]);

        $el = new ExpressionLanguage();

        $cases = [
            'scalar gt' => ['id > 0', true],
            'scalar eq int' => ['message_types_id == 572', true],
            'scalar strict eq str' => ["verb === 'trade-walk'", true],
            'scalar neq' => ["communication_channel != 'email'", true],
            'scalar matches' => ['slug matches "/wa/"', true],
            'object property' => ["channel.name == 'Notes'", true],
            'object nested property' => ['message.message_types_id == 572', true],
            'top-level json dot-nav' => ['metadata.data.user_company_id != 0', true],
            'method arg scalar' => ["order.getMetadata('source') == 'B2B'", true],
            'method array bracket' => "message.getMessage()['status'] == 'submitted'",
            'method array bracket coalesce' => "(message.getMessage()['from_me'] ?? '') == false",
            'method missing coalesce' => "(order.getMetadata('affiliate_id') ?? '') != ''",
            'relation method' => "lead.company.get('use_global_workflows') == 1",
            'relation property' => ['lead.receiver.id == 449', true],
            'object method scalar' => ["lead.get('driver_license_images') != ''", true],
        ];

        foreach ($cases as $label => $case) {
            $expression = is_array($case) ? $case[0] : $case;
            $result = $el->evaluate($expression, $values);
            $this->assertTrue((bool) $result, "Production shape [{$label}] must evaluate true: {$expression}");
        }

        // Deep method -> array -> bracket-chain navigation (real rule 277) returns the nested value.
        $deep = $el->evaluate(
            "order.getMetadata('esims')[0]['data']['insurancePendingData'][0]['insurance'] ?? null",
            $values
        );
        $this->assertSame('ACME', $deep);
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
