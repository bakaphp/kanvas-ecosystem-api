<?php

declare(strict_types=1);

namespace Tests\Workflow\Unit;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Rules\Support\SafeExpression;
use Tests\TestCaseUnit;

final class SafeExpressionTest extends TestCaseUnit
{
    public function testComparesAndNavigatesData(): void
    {
        $values = [
            'status' => 'open',
            'priority' => 3,
            'metadata' => ['data' => ['user_company_id' => 7]],
            'message' => (object) ['is_public' => 1, 'is_published' => 1],
        ];

        $this->assertTrue(SafeExpression::evaluate(
            "status == 'open' and priority > 2 and metadata.data.user_company_id == 7 and message.is_public == 1",
            $values
        ));
        $this->assertTrue(SafeExpression::evaluate("priority in [1, 3] and status matches '/^op/'", $values));
        $this->assertSame('Hi Ana', SafeExpression::evaluateText("'Hi ' ~ name", ['name' => 'Ana']));
    }

    public function testConditionsReadAttributesAndAllowedGetters(): void
    {
        $entity = $this->model();

        $this->assertTrue(SafeExpression::evaluate(
            "entity.id == 42 and entity.get('source') == 'B2B' and (entity.getMessage()['from_me'] ?? '') == false",
            ['entity' => $entity]
        ));
    }

    /**
     * Every method shape found in production rule conditions on 2026-10-18.
     */
    public function testProductionConditionShapesEvaluate(): void
    {
        $message = $this->model();
        $message->setRelation('messageType', $this->model()->setRawAttributes(['verb' => 'twilio-sms']));

        $values = [
            'id' => 9,
            'companies_id' => 12038,
            'lead' => $this->model(),
            'message' => $message,
            'order' => $this->model(),
        ];

        foreach ([
            "message.getMessage()['status'] == 'submitted'",
            "(message.getMessage()['from_me'] ?? '') == false",
            "(message.getMessage()['from_human'] ?? '') == true",
            "order.getMetadata('source') == 'B2B'",
            "(order.getMetadata('affiliate_id') ?? '') != ''",
            "order.getMetadata('esims')[0]['data']['insurance'] != null",
            "lead.get('source') == 'B2B'",
            "lead.hasTag(['vip']) == true",
            "message.hasTag(['vip']) == true and message.messageType.verb in ['twilio-sms', 'mailgun-email', 'notes']",
            "(message.getMessage()['status'] ?? null) != null and id > 0 and companies_id in [12038, 11176]",
        ] as $expression) {
            $this->assertTrue((bool) SafeExpression::evaluate($expression, $values), $expression);
            SafeExpression::assertCondition($expression);
        }
    }

    public function testRefusesOtherMethodsAndFunctions(): void
    {
        $entity = $this->model();

        foreach (['entity.wipe()', "constant('PHP_VERSION') != ''", 'entity.getKey() > 0', "entity.__call('wipe', [])"] as $expression) {
            try {
                SafeExpression::evaluate($expression, ['entity' => $entity]);
                $this->fail("Evaluated: {$expression}");
            } catch (ValidationException) {
                $this->assertFalse($entity::$called, $expression);
            }
        }
    }

    public function testTextCannotCallEvenTheAllowedGetters(): void
    {
        $this->expectException(ValidationException::class);

        SafeExpression::evaluateText("entity.get('source')", ['entity' => $this->model()]);
    }

    public function testReadingAPropertyNeverCallsAModelMethod(): void
    {
        $entity = $this->model();

        $this->assertTrue(SafeExpression::evaluate('entity.wipe == null and entity.id == 42', ['entity' => $entity]));
        $this->assertTrue(SafeExpression::evaluateText('entity.wipe == null', ['entity' => $entity]));
        $this->assertFalse($entity::$called);
    }

    public function testSavedConditionsAreCheckedWithoutTheirVariables(): void
    {
        SafeExpression::assertCondition("(message.getMessage()['from_me'] ?? false) == false");

        $this->expectException(ValidationException::class);

        SafeExpression::assertCondition('(entity.delete()) == null');
    }

    private function model(): Model
    {
        $model = new class () extends Model {
            public static bool $called = false;

            public function wipe(): void
            {
                self::$called = true;
            }

            public function get(string $key): ?string
            {
                return ['source' => 'B2B'][$key] ?? null;
            }

            public function getMessage(): array
            {
                return ['from_me' => false, 'from_human' => true, 'status' => 'submitted'];
            }

            public function getMetadata(string $key): mixed
            {
                return [
                    'source' => 'B2B',
                    'affiliate_id' => 'aff-1',
                    'esims' => [['data' => ['insurance' => 'ACME']]],
                ][$key] ?? null;
            }

            public function hasTag(array $tags): bool
            {
                return in_array('vip', $tags, true);
            }
        };
        $model->setRawAttributes(['id' => 42]);

        return $model;
    }
}
