<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Neuron;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use RuntimeException;
use Tests\Stubs\Intelligence\CapturingNeuronAgentStub;
use Tests\Stubs\Intelligence\ScriptedToolCallNeuronProvider;
use Tests\TestCase;

class ToolErrorHandlerTest extends TestCase
{
    public function testMissingRequiredArgumentIsReturnedToTheModelInsteadOfFailingTheTurn(): void
    {
        $executed = false;
        $tool = Tool::make('add_nervous_system_task', 'Add a task to a plan')
            ->addProperty(new ToolProperty(
                name: 'plan_id',
                type: PropertyType::INTEGER,
                description: 'The plan id.',
                required: true,
            ))
            ->setCallable(function () use (&$executed): string {
                $executed = true;

                return 'created';
            });

        $provider = new ScriptedToolCallNeuronProvider($tool);
        $agent = new CapturingNeuronAgentStub();
        $agent->capturedProvider = $provider;

        $reply = $agent->chat(new UserMessage('Add a task'))->run()->getMessage();

        $this->assertFalse($executed);
        $this->assertSame('Review done', $reply->getContent());

        $sent = array_values(array_filter(
            $provider->secondCallMessages,
            fn (Message $message): bool => $message instanceof ToolResultMessage,
        ));

        $this->assertCount(1, $sent);
        $this->assertStringContainsString(
            'Missing required parameter: plan_id',
            $sent[0]->getTools()[0]->getResult()
        );
    }

    public function testOtherToolFailuresStillThrow(): void
    {
        $tool = Tool::make('get_nervous_system_task', 'Get a task')
            ->setCallable(fn () => throw new RuntimeException('database down'));

        $agent = new CapturingNeuronAgentStub();
        $agent->capturedProvider = new ScriptedToolCallNeuronProvider($tool);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('database down');

        $agent->chat(new UserMessage('Get the task'))->run();
    }
}
