<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Intelligence\Agents\Helpers\ConversationStepsHelper;
use PHPUnit\Framework\TestCase;
use stdClass;

class ConversationStepsHelperTest extends TestCase
{
    public function testAnswerAfterToolCallsIsTwoStepsWithTheResultOnTheCall(): void
    {
        $steps = ConversationStepsHelper::fromToolCallsAndResults(
            'Yes, 3 in stock.',
            [['id' => 'call_1', 'name' => 'search_products', 'arguments' => ['q' => 'hoodie']]],
            [['id' => 'call_1', 'name' => 'search_products', 'arguments' => ['q' => 'hoodie'], 'result' => '[{"stock":3}]']],
            'Need stock first.',
        );

        $this->assertCount(2, $steps);
        $this->assertSame('', $steps[0]['content']);
        $this->assertSame([
            ['id' => 'call_1', 'name' => 'search_products', 'arguments' => ['q' => 'hoodie'], 'result' => '[{"stock":3}]'],
        ], $steps[0]['tool_calls']);
        $this->assertSame('Yes, 3 in stock.', $steps[1]['content']);
        $this->assertSame([], $steps[1]['tool_calls']);
        $this->assertSame('Need stock first.', $steps[1]['reasoning']);
        $this->assertSame([], $steps[1]['replay_blocks']);
        $this->assertSame([], $steps[1]['provider_tool_calls']);
    }

    public function testPlainAnswerIsOneStep(): void
    {
        $steps = ConversationStepsHelper::fromToolCallsAndResults('Hello.', [], []);

        $this->assertSame([[
            'content' => 'Hello.',
            'tool_calls' => [],
            'reasoning' => '',
            'replay_blocks' => [],
            'provider_tool_calls' => [],
        ]], $steps);
    }

    public function testACallWithoutAResultStaysBare(): void
    {
        $steps = ConversationStepsHelper::fromToolCallsAndResults(
            '',
            [['id' => 't9', 'name' => 'read_file', 'arguments' => ['path' => 'a.php']]],
            [],
        );

        $this->assertCount(1, $steps);
        $this->assertSame(
            [['id' => 't9', 'name' => 'read_file', 'arguments' => ['path' => 'a.php']]],
            $steps[0]['tool_calls'],
        );
        $this->assertArrayNotHasKey('result', $steps[0]['tool_calls'][0]);
    }

    public function testAResultWhoseCallIsOnAnotherRowBecomesACallCarryingItsResult(): void
    {
        $steps = ConversationStepsHelper::fromToolCallsAndResults(
            '',
            [],
            [['id' => 'tcid-1', 'name' => 'lookup_user', 'arguments' => null, 'result' => '{"name":"Max"}', 'result_id' => 'tcid-1']],
        );

        $this->assertSame(
            [['id' => 'tcid-1', 'name' => 'lookup_user', 'arguments' => [], 'result' => '{"name":"Max"}']],
            $steps[0]['tool_calls'],
        );
    }

    public function testNeuronShapeIsNormalized(): void
    {
        $calls = ConversationStepsHelper::foldResultsIntoCalls(
            [['callId' => 'c1', 'name' => 'get_lead', 'description' => 'd', 'parameters' => [], 'inputs' => new stdClass(), 'result' => null]],
            [['callId' => 'c1', 'name' => 'get_lead', 'description' => 'd', 'parameters' => [], 'inputs' => ['lead_id' => 7], 'result' => ['status' => 'won']]],
        );

        $this->assertSame(
            [['id' => 'c1', 'name' => 'get_lead', 'arguments' => [], 'result' => ['status' => 'won']]],
            $calls,
        );
    }

    public function testOpenAiShapeWithJsonStringArgumentsIsNormalized(): void
    {
        $calls = ConversationStepsHelper::foldResultsIntoCalls(
            [['id' => 'tc-1', 'function' => ['name' => 'lookup_user', 'arguments' => '{"id":4}']]],
            [],
        );

        $this->assertSame([['id' => 'tc-1', 'name' => 'lookup_user', 'arguments' => ['id' => 4]]], $calls);
    }

    public function testCallsWithoutIdsFoldByName(): void
    {
        $calls = ConversationStepsHelper::foldResultsIntoCalls(
            [['name' => 'get_lead_ref', 'inputs' => ['lead_id' => 700015]]],
            [['name' => 'get_lead_ref', 'result' => '{"lead_id":700015}']],
        );

        $this->assertSame(
            [['id' => '', 'name' => 'get_lead_ref', 'arguments' => ['lead_id' => 700015], 'result' => '{"lead_id":700015}']],
            $calls,
        );
    }

    public function testDeniedAndFailedFlagsTravelWithTheResult(): void
    {
        $calls = ConversationStepsHelper::foldResultsIntoCalls(
            [['id' => 'c7', 'name' => 'delete_file', 'arguments' => ['path' => 'x']]],
            [['id' => 'c7', 'name' => 'delete_file', 'arguments' => ['path' => 'x'], 'result' => 'Denied.', 'denied' => true]],
        );

        $this->assertTrue($calls[0]['denied']);
        $this->assertSame('Denied.', $calls[0]['result']);
    }
}
