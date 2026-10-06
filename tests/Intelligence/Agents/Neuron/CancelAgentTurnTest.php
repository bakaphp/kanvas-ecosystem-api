<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Neuron;

use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Exceptions\AgentTurnCancelledException;
use Kanvas\Intelligence\Agents\Services\AgentTurnCancellationService;
use NeuronAI\Chat\Messages\UserMessage;
use Tests\Stubs\Intelligence\CapturingNeuronAgentStub;
use Tests\Stubs\Intelligence\CapturingNeuronProvider;
use Tests\Stubs\Intelligence\ScriptedToolCallNeuronProvider;
use Tests\Stubs\Intelligence\Tools\CallbackTool;
use Tests\TestCase;

/**
 * A stop lands at the next boundary: before a model call, or before a tool call. It never cuts a
 * request or a tool that already started, and a stop meant for another thread does nothing here.
 */
class CancelAgentTurnTest extends TestCase
{
    public function testAStopBeforeTheFirstModelCallNeverReachesTheProvider(): void
    {
        $thread = 'cancel-' . Str::uuid();
        $provider = new CapturingNeuronProvider();
        $agent = new CapturingNeuronAgentStub()->setThreadId($thread);
        $agent->capturedProvider = $provider;

        AgentTurnCancellationService::request($thread);

        try {
            $agent->chat(new UserMessage('Draft the whole proposal'));
            $this->fail('The turn must stop before the model is called');
        } catch (AgentTurnCancelledException $e) {
            $this->assertSame($thread, $e->threadId);
        } finally {
            AgentTurnCancellationService::clear($thread);
        }

        $this->assertSame([], $provider->messages, 'No inference ran');
    }

    public function testAStopDuringAToolLoopLandsBeforeTheNextModelCall(): void
    {
        $thread = 'cancel-' . Str::uuid();
        $ran = 0;
        $tool = new CallbackTool('search_leads', 'Find leads.', function () use (&$ran, $thread): string {
            $ran++;
            AgentTurnCancellationService::request($thread);

            return 'found 3';
        });
        $provider = new ScriptedToolCallNeuronProvider($tool);
        $agent = new CapturingNeuronAgentStub()->setThreadId($thread);
        $agent->capturedProvider = $provider;
        $agent->addTool($tool);

        try {
            $agent->chat(new UserMessage('Find my leads'));
            $this->fail('The turn must stop after the tool, before the model answers');
        } catch (AgentTurnCancelledException) {
        } finally {
            AgentTurnCancellationService::clear($thread);
        }

        $this->assertSame(1, $ran, 'A tool that started runs to its end');
        $this->assertSame([], $provider->secondCallMessages, 'The model never got the tool result');
    }

    public function testAStopOnAnotherThreadDoesNotTouchThisTurn(): void
    {
        $thread = 'cancel-' . Str::uuid();
        $other = 'cancel-' . Str::uuid();
        $agent = new CapturingNeuronAgentStub()->setThreadId($thread);
        $agent->capturedProvider = new CapturingNeuronProvider('Still answering.');

        AgentTurnCancellationService::request($other);

        try {
            $reply = $agent->chat(new UserMessage('Hello'))->getMessage();
        } finally {
            AgentTurnCancellationService::clear($other);
        }

        $this->assertSame('Still answering.', $reply?->getContent());
    }
}
