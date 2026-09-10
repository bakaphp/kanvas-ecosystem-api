<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use Kanvas\Intelligence\Agents\Neuron\Tools\Mcp\CachedMcpConnector;
use Tests\Stubs\Connectors\Mcp\FakeMcpServer;
use Tests\TestCase;

final class CachedMcpConnectorTest extends TestCase
{
    public function testBuildingToolsFromCachedDescriptorsOpensNoConnection(): void
    {
        // An empty queue makes any transport use fail the test outright, which is the assertion: a warm
        // turn must cost zero round trips even though the parent's tools() always dials.
        $connector = new CachedMcpConnector(['transport' => FakeMcpServer::handshakeThenCalls([], 0)]);

        $tools = $connector->toolsFromDescriptors(FakeMcpServer::twoTools(), 'jira');

        $this->assertCount(2, $tools);
    }

    public function testToolsAreExposedUnderThePrefixedName(): void
    {
        $connector = new CachedMcpConnector(['transport' => FakeMcpServer::handshakeThenCalls([], 0)]);

        $names = array_map(
            fn ($tool): string => $tool->getName(),
            $connector->toolsFromDescriptors(FakeMcpServer::twoTools(), 'jira')
        );

        $this->assertContains('jira__searchJiraIssuesUsingJql', $names);
        $this->assertContains('jira__createJiraIssue', $names);
    }

    public function testTheServerIsCalledByItsOwnNameNotThePrefixedOne(): void
    {
        $transport = FakeMcpServer::handshakeThenCalls(['content' => [['type' => 'text', 'text' => 'ok']]]);
        $connector = new CachedMcpConnector(['transport' => $transport]);
        $connector->toolsFromDescriptors(FakeMcpServer::twoTools(), 'jira');

        $connector->invokeTool(['name' => 'jira__createJiraIssue'], ['summary' => 'hi']);

        $sent = $transport->getSent();
        $call = array_values(array_filter($sent, fn (array $m): bool => ($m['method'] ?? '') === 'tools/call'));

        // The prefix exists only so two servers cannot collide in the prompt; sending it to the vendor
        // would be an unknown-tool error on every single call.
        $this->assertSame('createJiraIssue', $call[0]['params']['name']);
    }

    public function testTheBudgetStopsCallingOutAndTellsTheModelWhy(): void
    {
        $transport = FakeMcpServer::handshakeThenCalls(['content' => [['type' => 'text', 'text' => 'ok']]]);
        $connector = new CachedMcpConnector(['transport' => $transport])->withBudget(1, 60000);
        $connector->toolsFromDescriptors(FakeMcpServer::twoTools(), 'jira');

        $connector->invokeTool(['name' => 'jira__createJiraIssue'], ['summary' => 'one']);
        $second = $connector->invokeTool(['name' => 'jira__createJiraIssue'], ['summary' => 'two']);

        // Refusing by return value, not by withdrawing the tool: Neuron cannot remove a tool mid-turn,
        // and a model that is told why answers with what it has instead of retrying.
        $this->assertIsString($second);
        $this->assertStringContainsString('budget exhausted', $second);
        $this->assertSame(1, $connector->callCount(), 'The refused call must not reach the vendor.');
    }

    public function testCountersAreExposedForTheTurnRecord(): void
    {
        $transport = FakeMcpServer::handshakeThenCalls(['content' => []]);
        $connector = new CachedMcpConnector(['transport' => $transport]);
        $connector->toolsFromDescriptors(FakeMcpServer::twoTools(), 'jira');

        $connector->invokeTool(['name' => 'jira__createJiraIssue'], []);

        $this->assertSame(1, $connector->callCount());
        $this->assertGreaterThanOrEqual(0, $connector->elapsedMs());
    }

    public function testTheNameMapSurvivesSerialization(): void
    {
        $connector = new CachedMcpConnector(['transport' => FakeMcpServer::handshakeThenCalls([], 0)]);
        $connector->toolsFromDescriptors(FakeMcpServer::twoTools(), 'jira');

        /** @var CachedMcpConnector $revived */
        $revived = unserialize(serialize($connector));

        $this->assertSame(
            $connector->callCount(),
            $revived->callCount(),
            'Interrupt handling serializes the connector, so its translation table has to round-trip.'
        );
    }
}
