<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Intelligence\Agents\Helpers\ConversationStepsHelper;
use PHPUnit\Framework\TestCase;

class ConversationStepsHelperForRowTest extends TestCase
{
    public function testAHumanPromptHasNoSteps(): void
    {
        $this->assertSame([], ConversationStepsHelper::forRow(
            'user',
            'hello',
            [],
            [],
        ));
    }

    public function testANeuronToolResultRowIsUserRoleAndStillCarriesTheResult(): void
    {
        $steps = ConversationStepsHelper::forRow(
            'user',
            '',
            [],
            [['callId' => 'c1', 'name' => 'get_lead', 'inputs' => ['lead_id' => 7], 'result' => ['status' => 'won']]],
        );

        $this->assertCount(1, $steps);
        $this->assertSame('', $steps[0]['content']);
        $this->assertSame(['status' => 'won'], $steps[0]['tool_calls'][0]['result']);
    }

    public function testARuntimeToolResultRowKeepsItsContentOutOfTheStepText(): void
    {
        $steps = ConversationStepsHelper::forRow(
            'tool_result',
            '{"name":"Max"}',
            [],
            [['id' => 'tcid-1', 'name' => 'lookup_user', 'arguments' => null, 'result' => '{"name":"Max"}']],
        );

        $this->assertSame('', $steps[0]['content']);
        $this->assertSame('{"name":"Max"}', $steps[0]['tool_calls'][0]['result']);
    }

    public function testAnAssistantRowKeepsItsText(): void
    {
        $steps = ConversationStepsHelper::forRow(
            'assistant',
            'Done.',
            [],
            [],
            'thought',
        );

        $this->assertSame('Done.', $steps[0]['content']);
        $this->assertSame('thought', $steps[0]['reasoning']);
    }
}
