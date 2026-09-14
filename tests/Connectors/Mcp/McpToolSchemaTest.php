<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use Kanvas\Intelligence\Agents\Neuron\Tools\Mcp\CachedMcpConnector;
use NeuronAI\Providers\Gemini\ToolMapper as GeminiToolMapper;
use Tests\Stubs\Connectors\Mcp\FakeMcpServer;
use Tests\TestCase;
use Tests\Traits\ValidatesGeminiSchema;

/**
 * Shapes taken from the real servers: Google Sheets `update_values`, Linear `save_issue`, Atlassian
 * `createJiraIssue`. Each one used to 400 every Gemini turn of any agent holding it.
 */
final class McpToolSchemaTest extends TestCase
{
    use ValidatesGeminiSchema;

    public function testEveryDeclarationIsValidForGemini(): void
    {
        $violations = [];

        foreach ($this->declarations() as $declaration) {
            $violations = [...$violations, ...$this->schemaViolations($declaration['name'], $declaration['parameters'])];
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    public function testAnArrayOfArraysKeepsItsInnerItems(): void
    {
        $values = $this->declarations()['sheets__update_values']['parameters']['properties']['values'];

        $this->assertSame('array', $values['items']['type']);
        $this->assertSame('string', $values['items']['items']['type']);
    }

    public function testObjectItemsKeepTheirProperties(): void
    {
        $links = $this->declarations()['linear__save_issue']['parameters']['properties']['links'];

        $this->assertSame(['url', 'title'], array_keys($links['items']['properties']));
        $this->assertSame(['url', 'title'], $links['items']['required']);
    }

    public function testOnlyStringEnumsAreDeclared(): void
    {
        $properties = $this->declarations()['linear__save_issue']['parameters']['properties'];

        $this->assertSame(['low', 'high'], $properties['priority']['enum']);
        $this->assertArrayNotHasKey('enum', $properties['estimate']);
    }

    public function testAFreeFormObjectIsAskedForAsJson(): void
    {
        $fields = $this->declarations()['jira__createJiraIssue']['parameters']['properties']['additional_fields'];

        $this->assertSame('string', $fields['type']);
        $this->assertStringContainsString('JSON-encoded object', $fields['description']);
    }

    public function testTheServerReceivesTheObjectTheModelSentAsJson(): void
    {
        $transport = FakeMcpServer::handshakeThenCalls(['content' => [['type' => 'text', 'text' => 'ok']]]);
        $connector = new CachedMcpConnector(['transport' => $transport]);
        $connector->toolsFromDescriptors([$this->jiraCreateIssue()], 'jira');

        $connector->invokeTool(
            [...$this->jiraCreateIssue(), 'name' => 'jira__createJiraIssue'],
            ['summary' => 'Fix login', 'additional_fields' => '{"Story Points": 8}']
        );

        $call = array_values(array_filter($transport->getSent(), fn (array $m): bool => ($m['method'] ?? '') === 'tools/call'));

        $this->assertSame(
            ['summary' => 'Fix login', 'additional_fields' => ['Story Points' => 8]],
            $call[0]['params']['arguments']
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function declarations(): array
    {
        $connector = new CachedMcpConnector(['transport' => FakeMcpServer::handshakeThenCalls([], 0)]);
        $tools = [
            ...$connector->toolsFromDescriptors([$this->sheetsUpdateValues()], 'sheets'),
            ...$connector->toolsFromDescriptors([$this->linearSaveIssue()], 'linear'),
            ...$connector->toolsFromDescriptors([$this->jiraCreateIssue()], 'jira'),
        ];

        return array_column(new GeminiToolMapper()->map($tools)['functionDeclarations'], null, 'name');
    }

    /**
     * @return array<string, mixed>
     */
    private function sheetsUpdateValues(): array
    {
        return [
            'name' => 'update_values',
            'inputSchema' => [
                'type' => 'object',
                'required' => ['values'],
                'properties' => [
                    'values' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => []]],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function linearSaveIssue(): array
    {
        return [
            'name' => 'save_issue',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'links' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['url', 'title'],
                            'properties' => [
                                'url' => ['type' => 'string', 'format' => 'uri'],
                                'title' => ['type' => 'string', 'minLength' => 1],
                            ],
                        ],
                    ],
                    'priority' => ['type' => ['string', 'null'], 'enum' => ['low', 'high']],
                    'estimate' => ['type' => 'integer', 'enum' => [1, 2, 3]],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jiraCreateIssue(): array
    {
        return [
            'name' => 'createJiraIssue',
            'inputSchema' => [
                'type' => 'object',
                'required' => ['summary'],
                'properties' => [
                    'summary' => ['type' => 'string'],
                    'additional_fields' => ['type' => 'object', 'description' => 'Additional Jira fields.'],
                ],
            ],
        ];
    }
}
