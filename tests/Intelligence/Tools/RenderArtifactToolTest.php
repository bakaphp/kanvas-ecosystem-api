<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Intelligence\Agents\Neuron\Tools\Common\RenderArtifactTool;
use Tests\TestCase;

final class RenderArtifactToolTest extends TestCase
{
    public function testItRendersTheExactFencedBlock(): void
    {
        $result = new RenderArtifactTool()(
            component: 'stats',
            props: json_encode(['items' => [['label' => 'Vacation', 'value' => 12, 'description' => '20 days/year']]]),
            title: 'Leave balance',
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            "```kanvas-artifact\n"
            . '{"version":1,"component":"stats","title":"Leave balance","props":{"items":[{"label":"Vacation","value":12,"description":"20 days/year"}]}}'
            . "\n```",
            $result['block']
        );
    }

    public function testTheTitleIsOptional(): void
    {
        $result = new RenderArtifactTool()(
            component: 'callout',
            props: ['variant' => 'info', 'text' => 'This request is now PENDING manager approval.'],
        );

        $this->assertTrue($result['success']);
        $this->assertStringNotContainsString('"title"', $result['block']);
    }

    public function testNamingTheArrayAfterTheComponentIsRejectedWithTheFix(): void
    {
        $result = new RenderArtifactTool()(
            component: 'stats',
            props: json_encode(['stats' => [['label' => 'Revenue', 'value' => 10]]]),
        );

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_args', $result['outcome']);
        $this->assertStringContainsString('props.stats is not a valid prop — allowed: items', $result['error']);
        $this->assertStringContainsString('props.items is required', $result['error']);
    }

    public function testTableRowsAreNotData(): void
    {
        $result = new RenderArtifactTool()(
            component: 'table',
            props: json_encode(['columns' => [['key' => 'name', 'label' => 'Name']], 'data' => [['name' => 'Jane']]]),
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('props.data is not a valid prop', $result['error']);
        $this->assertStringContainsString('props.rows is required', $result['error']);
    }

    public function testUnknownComponentIsRejected(): void
    {
        $result = new RenderArtifactTool()(component: 'kpi', props: '{}');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown component "kpi"', $result['error']);
    }

    public function testMalformedJsonIsRejected(): void
    {
        $result = new RenderArtifactTool()(component: 'keyvalue', props: "{'items': []}");

        $this->assertFalse($result['success']);
        $this->assertSame('props is not a valid JSON object.', $result['error']);
    }

    public function testOverTheLimitAsksToTruncate(): void
    {
        $items = array_map(
            fn (int $i): array => ['label' => 'Metric ' . $i, 'value' => $i],
            range(1, 7)
        );

        $result = new RenderArtifactTool()(component: 'stats', props: json_encode(['items' => $items]));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('props.items has 7 entries; it needs 1 to 6', $result['error']);
    }

    public function testEnumValuesAreChecked(): void
    {
        $result = new RenderArtifactTool()(
            component: 'timeline',
            props: json_encode(['items' => [['title' => 'First day', 'status' => 'pending']]]),
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('props.items[0].status must be one of: done, current, upcoming', $result['error']);
    }

    public function testTableRowsMustBeFlat(): void
    {
        $result = new RenderArtifactTool()(
            component: 'table',
            props: json_encode([
                'columns' => [['key' => 'name', 'label' => 'Name']],
                'rows' => [['name' => ['first' => 'Jane']]],
            ]),
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('props.rows[0].name must be a string, number, boolean or null', $result['error']);
    }

    public function testChartKeysMustExistInTheData(): void
    {
        $result = new RenderArtifactTool()(
            component: 'chart',
            props: json_encode([
                'kind' => 'bar',
                'xKey' => 'month',
                'series' => [['key' => 'revenue']],
                'data' => [['month' => 'Jan', 'sales' => 10]],
            ]),
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('props.data has no field named revenue', $result['error']);
    }

    public function testAFenceInsideAStringIsRejected(): void
    {
        $result = new RenderArtifactTool()(
            component: 'callout',
            props: json_encode(['variant' => 'info', 'text' => 'Run ```php artisan``` first']),
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('props.text must be a string without ```', $result['error']);
    }

    public function testAnEntityNeedsARealId(): void
    {
        $valid = new RenderArtifactTool()(
            component: 'entity',
            props: json_encode(['type' => 'people', 'id' => 1042, 'title' => 'Jane Cooper']),
        );
        $missing = new RenderArtifactTool()(
            component: 'entity',
            props: json_encode(['type' => 'people', 'title' => 'Jane Cooper']),
        );

        $fenced = new RenderArtifactTool()(
            component: 'entity',
            props: json_encode(['type' => 'people', 'id' => "1\n```", 'title' => 'Jane Cooper']),
        );

        $this->assertTrue($valid['success']);
        $this->assertFalse($missing['success']);
        $this->assertStringContainsString('props.id is required', $missing['error']);
        $this->assertFalse($fenced['success'], 'A fence in the id would close the block early');
    }
}
