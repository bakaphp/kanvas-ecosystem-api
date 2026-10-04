<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Neuron;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use RuntimeException;
use Tests\Stubs\Intelligence\CapturingNeuronAgentStub;
use Tests\Stubs\Intelligence\ScriptedToolCallNeuronProvider;
use Tests\Stubs\Intelligence\Tools\CallbackTool;
use Tests\TestCase;

class ToolErrorHandlerTest extends TestCase
{
    /**
     * Neuron binds and validates the inputs before the tool runs: the model gets the correction as the
     * tool result and the turn goes on instead of dying (KANVAS-ECOSYSTEM-65P).
     */
    public function testMissingRequiredArgumentIsReturnedToTheModelInsteadOfFailingTheTurn(): void
    {
        $executed = false;
        $tool = new CallbackTool(
            'add_nervous_system_task',
            'Add a task to a plan',
            function () use (&$executed): string {
                $executed = true;

                return 'created';
            },
            [new ToolProperty(
                name: 'plan_id',
                type: PropertyType::INTEGER,
                description: 'The plan id.',
                required: true,
            )],
        );

        $provider = new ScriptedToolCallNeuronProvider($tool);
        $agent = new CapturingNeuronAgentStub()->setThreadId('tool-error-handler');
        $agent->capturedProvider = $provider;
        $agent->addTool($tool);

        $reply = $agent->chat(new UserMessage('Add a task'))->getMessage();

        $this->assertFalse($executed);
        $this->assertSame('Review done', $reply?->getContent());

        $sent = array_values(array_filter(
            $provider->secondCallMessages,
            fn (Message $message): bool => $message instanceof ToolResultMessage,
        ));

        $this->assertCount(1, $sent);
        $this->assertStringContainsString(
            'Parameter "plan_id" is required',
            (string) $sent[0]->getToolCalls()[0]->getResult()
        );
    }

    public function testOtherToolFailuresStillThrow(): void
    {
        $tool = new CallbackTool('get_nervous_system_task', 'Get a task', fn () => throw new RuntimeException('database down'));

        $agent = new CapturingNeuronAgentStub()->setThreadId('tool-error-handler');
        $agent->capturedProvider = new ScriptedToolCallNeuronProvider($tool);
        $agent->addTool($tool);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('database down');

        $agent->chat(new UserMessage('Get the task'));
    }
}
