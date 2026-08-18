<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Intelligence\Agents\Neuron\BaseKanvasAgent;
use NeuronAI\Exceptions\MissingCallbackParameter;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Tools\Tool;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * A tool call the model got wrong must come back to the model as a result, not end the turn
 * (Sentry KANVAS-ECOSYSTEM-65P).
 */
class KanvasAgentToolErrorHandlerTest extends TestCase
{
    private function handler(): callable
    {
        $method = new ReflectionMethod(BaseKanvasAgent::class, 'resolveToolErrorHandler');
        $handler = $method->invoke(new BaseKanvasAgent());

        $this->assertIsCallable($handler);

        return $handler;
    }

    private function tool(): Tool
    {
        return new Tool('search_leads', 'Find leads.');
    }

    public function testMissingParameterBecomesAToolResultForTheModel(): void
    {
        $result = $this->handler()(
            new MissingCallbackParameter('Missing required parameter: query'),
            $this->tool(),
        );

        $decoded = json_decode($result, true);

        $this->assertSame('Missing required parameter: query', $decoded['error']);
        $this->assertSame('search_leads', $decoded['tool']);
        $this->assertArrayHasKey('hint', $decoded);
    }

    public function testUnexpectedFailureStillBecomesAToolResult(): void
    {
        $result = $this->handler()(new RuntimeException('database is on fire'), $this->tool());

        $this->assertSame('database is on fire', json_decode($result, true)['error']);
    }

    public function testRunCapIsRethrownSoTheLoopGuardKeepsWorking(): void
    {
        $this->expectException(ToolRunsExceededException::class);

        $this->handler()(new ToolRunsExceededException('too many runs'), $this->tool());
    }
}
