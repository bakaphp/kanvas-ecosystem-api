<?php

declare(strict_types=1);

namespace Tests\Approvals;

use Kanvas\Approvals\Services\ApprovalConditionEvaluatorService;
use Tests\TestCase;

final class ApprovalConditionEvaluatorTest extends TestCase
{
    private ApprovalConditionEvaluatorService $evaluator;

    public function setUp(): void
    {
        parent::setUp();

        $this->evaluator = new ApprovalConditionEvaluatorService();
    }

    public function test_an_absent_condition_always_matches(): void
    {
        $this->assertTrue($this->evaluator->matches(null, ['total' => 1]));
        $this->assertTrue($this->evaluator->matches([], ['total' => 1]));
    }

    public function test_a_condition_with_no_field_matches_rather_than_dropping_the_step(): void
    {
        $this->assertTrue($this->evaluator->matches(['operator' => '>=', 'value' => 10], []));
    }

    public function test_greater_than_or_equal_gates_on_an_amount(): void
    {
        $condition = ['field' => 'total_native', 'operator' => '>=', 'value' => 10000];

        $this->assertTrue($this->evaluator->matches($condition, ['total_native' => 12400]));
        $this->assertTrue($this->evaluator->matches($condition, ['total_native' => 10000]));
        $this->assertFalse($this->evaluator->matches($condition, ['total_native' => 9999.99]));
    }

    public function test_numeric_comparison_survives_json_string_values(): void
    {
        $condition = ['field' => 'total_native', 'operator' => '>', 'value' => '500'];

        $this->assertTrue($this->evaluator->matches($condition, ['total_native' => '1200.50']));
        $this->assertFalse($this->evaluator->matches($condition, ['total_native' => '12.50']));
    }

    public function test_in_operator_gates_on_provenance(): void
    {
        $condition = ['field' => 'origin', 'operator' => 'in', 'value' => ['email', 'agent']];

        $this->assertTrue($this->evaluator->matches($condition, ['origin' => 'agent']));
        $this->assertFalse($this->evaluator->matches($condition, ['origin' => 'ui']));
    }

    public function test_not_in_operator(): void
    {
        $condition = ['field' => 'origin', 'operator' => 'not in', 'value' => ['ui']];

        $this->assertTrue($this->evaluator->matches($condition, ['origin' => 'agent']));
        $this->assertFalse($this->evaluator->matches($condition, ['origin' => 'ui']));
    }

    public function test_not_equal_treats_a_missing_field_as_null(): void
    {
        $condition = ['field' => 'source_email_message_id', 'operator' => '!=', 'value' => null];

        $this->assertTrue($this->evaluator->matches($condition, ['source_email_message_id' => 'abc']));
        $this->assertFalse($this->evaluator->matches($condition, []));
    }

    public function test_dot_notation_reaches_into_the_frozen_payload(): void
    {
        $condition = ['field' => 'payload.total_native', 'operator' => '>=', 'value' => 100];

        $this->assertTrue($this->evaluator->matches($condition, ['payload' => ['total_native' => 250]]));
        $this->assertFalse($this->evaluator->matches($condition, ['payload' => ['total_native' => 50]]));
        $this->assertFalse($this->evaluator->matches($condition, ['payload' => []]));
    }

    public function test_matches_operator_uses_a_regex(): void
    {
        $condition = ['field' => 'email', 'operator' => 'matches', 'value' => '/@acme\.com$/'];

        $this->assertTrue($this->evaluator->matches($condition, ['email' => 'ap@acme.com']));
        $this->assertFalse($this->evaluator->matches($condition, ['email' => 'ap@other.com']));
    }

    public function test_an_invalid_regex_is_a_non_match_not_a_crash(): void
    {
        $condition = ['field' => 'email', 'operator' => 'matches', 'value' => 'not-a-pattern'];

        $this->assertFalse($this->evaluator->matches($condition, ['email' => 'ap@acme.com']));
    }

    public function test_an_unknown_operator_falls_back_to_equality(): void
    {
        $condition = ['field' => 'status', 'operator' => 'wat', 'value' => 'draft'];

        $this->assertTrue($this->evaluator->matches($condition, ['status' => 'draft']));
        $this->assertFalse($this->evaluator->matches($condition, ['status' => 'issued']));
    }
}
