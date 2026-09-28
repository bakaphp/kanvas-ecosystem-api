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

    public function testAnEmptyObjectStaysAnObjectOnTheWire(): void
    {
        $transport = FakeMcpServer::handshakeThenCalls(['content' => [['type' => 'text', 'text' => 'ok']]]);
        $connector = new CachedMcpConnector(['transport' => $transport]);
        $connector->toolsFromDescriptors([$this->browserlessAgent()], 'browserless');

        $connector->invokeTool(
            [...$this->browserlessAgent(), 'name' => 'browserless__browserless_agent'],
            ['commands' => [
                ['method' => 'goto', 'params' => ['url' => 'https://example.test']],
                ['method' => 'snapshot', 'params' => []],
                ['method' => 'close', 'params' => '{}'],
            ]],
        );

        $call = array_values(array_filter($transport->getSent(), fn (array $m): bool => ($m['method'] ?? '') === 'tools/call'));
        $sent = json_decode((string) json_encode($call[0]['params']['arguments']), true, 64, JSON_THROW_ON_ERROR);

        // PHP has one array type, so `{}` came back out as `[]` and Browserless refused the whole call:
        // "commands.1.params: Invalid input: expected record, received array".
        $this->assertStringContainsString('"params":{}', (string) json_encode($call[0]['params']['arguments']));
        $this->assertSame(['url' => 'https://example.test'], $sent['commands'][0]['params']);
        $this->assertSame([], $sent['commands'][1]['params']);
        $this->assertSame([], $sent['commands'][2]['params']);
    }

    public function testAReferencedItemKeepsTheShapeItPointsAt(): void
    {
        $attendees = $this->declarations()['calendar__create_event']['parameters']['properties']['attendees'];

        $this->assertSame('Attendees of the event.', $attendees['description']);
        $this->assertSame('object', $attendees['items']['type']);
        $this->assertSame(['email'], $attendees['items']['required']);
    }

    public function testReadOnlyFieldsAreNotOffered(): void
    {
        $attendee = $this->declarations()['calendar__create_event']['parameters']['properties']['attendees']['items'];

        $this->assertSame(['email', 'displayName'], array_keys($attendee['properties']));
    }

    public function testARecursiveReferenceStopsAtItsOwnAncestry(): void
    {
        $filter = $this->declarations()['analytics__run_report']['parameters']['properties']['dimension_filter'];
        $nested = $filter['properties']['and_group']['items'];

        $this->assertSame('string', $nested['type']);
        $this->assertStringContainsString('JSON-encoded object', $nested['description']);
    }

    public function testTheServerReceivesARecursiveReferenceAsAnObject(): void
    {
        $transport = FakeMcpServer::handshakeThenCalls(['content' => [['type' => 'text', 'text' => 'ok']]]);
        $connector = new CachedMcpConnector(['transport' => $transport]);
        $connector->toolsFromDescriptors([$this->analyticsRunReport()], 'analytics');

        $connector->invokeTool(
            [...$this->analyticsRunReport(), 'name' => 'analytics__run_report'],
            ['dimension_filter' => ['field_name' => 'country', 'and_group' => ['{"field_name": "city"}']]]
        );

        $call = array_values(array_filter($transport->getSent(), fn (array $m): bool => ($m['method'] ?? '') === 'tools/call'));

        $this->assertSame(
            ['dimension_filter' => ['field_name' => 'country', 'and_group' => [['field_name' => 'city']]]],
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
            ...$connector->toolsFromDescriptors([$this->calendarCreateEvent()], 'calendar'),
            ...$connector->toolsFromDescriptors([$this->analyticsRunReport()], 'analytics'),
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

    /**
     * Google Calendar `create_event`, trimmed: `attendees` only points at `$defs`.
     *
     * @return array<string, mixed>
     */
    private function calendarCreateEvent(): array
    {
        return [
            'name' => 'create_event',
            'inputSchema' => [
                'type' => 'object',
                'required' => ['summary'],
                'properties' => [
                    'summary' => ['type' => 'string'],
                    'attendees' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/$defs/Attendee'],
                        'description' => 'Attendees of the event.',
                    ],
                ],
                '$defs' => [
                    'Attendee' => [
                        'type' => 'object',
                        'required' => ['email'],
                        'properties' => [
                            'email' => ['type' => 'string'],
                            'displayName' => ['type' => 'string'],
                            'self' => ['type' => 'boolean', 'readOnly' => true],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Google Analytics `run_report`, trimmed: a filter expression that contains filter expressions.
     *
     * @return array<string, mixed>
     */
    /**
     * Browserless's `commands`: a list of {method, params} where `params` is a free-form map.
     *
     * @return array<string, mixed>
     */
    private function browserlessAgent(): array
    {
        return [
            'name' => 'browserless_agent',
            'description' => 'Execute browser commands in a persistent agent session.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'commands' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'method' => ['type' => 'string'],
                                'params' => ['type' => 'object', 'description' => 'Arguments for the method.'],
                            ],
                            'required' => ['method'],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function analyticsRunReport(): array
    {
        return [
            'name' => 'run_report',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'dimension_filter' => ['$ref' => '#/$defs/FilterExpression'],
                ],
                '$defs' => [
                    'FilterExpression' => [
                        'type' => 'object',
                        'properties' => [
                            'field_name' => ['type' => 'string'],
                            'and_group' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/FilterExpression']],
                        ],
                    ],
                ],
            ],
        ];
    }
}
