<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Intelligence\Agents\Neuron\Tools\Common\RenderArtifactTool;
use Kanvas\Intelligence\Agents\Services\ArtifactBlockService;
use NeuronAI\Tools\TrackByInputs;
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

    /**
     * A Q3 report rendered 10 distinct blocks, then the 11th (a "Next steps" actions block) aborted
     * the turn with ToolRunsExceededException (KANVAS-ECOSYSTEM-6H4).
     */
    public function testDistinctBlocksDoNotShareARunBudget(): void
    {
        $tool = new RenderArtifactTool();
        $this->assertContains(TrackByInputs::class, class_uses_recursive($tool));

        $stats = [
            'component' => 'stats',
            'props' => '{"items":[{"label":"Revenue","value":10}]}',
        ];
        $actions = [
            'component' => 'actions',
            'title' => 'Next steps',
            'props' => '{"items":[{"label":"Compare vs Q2","message":"Compare Q2 and Q3"}]}',
        ];

        $statsKey = $tool->setInputs($stats)->getRunKey();

        $this->assertNotSame($statsKey, $tool->setInputs($actions)->getRunKey());
        $this->assertSame($statsKey, $tool->setInputs($stats)->getRunKey());
    }

    public function testTheDescriptionAsksForOneBlockPerReplyNotOnePerParagraph(): void
    {
        $description = (string) new RenderArtifactTool()->getDescription();

        $this->assertStringContainsString('One block per reply is the norm', $description);
        $this->assertStringNotContainsString('Several blocks per reply are fine', $description, 'Four render calls were 17 s of a 41 s PM turn');
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

    public function testApprovalsTakesFiltersNotRows(): void
    {
        $uuid = '3f2b9c1e-8a4d-4c2e-9b1f-0a1b2c3d4e5f';

        $result = new RenderArtifactTool()(
            component: 'approvals',
            props: json_encode([
                'status' => 'pending',
                'type' => 'approve_bill',
                'ids' => [482, '483', $uuid],
                'limit' => 5,
            ]),
            title: 'Bills awaiting approval',
        );
        $person = new RenderArtifactTool()(
            component: 'approvals',
            props: json_encode(['peopleId' => $uuid]),
        );

        $this->assertTrue($result['success']);
        $this->assertStringContainsString(
            '"component":"approvals","title":"Bills awaiting approval","props":{"status":"pending","type":"approve_bill"',
            $result['block']
        );
        $this->assertTrue($person['success']);
    }

    public function testApprovalsWithNoFiltersRendersAnObject(): void
    {
        $result = new RenderArtifactTool()(component: 'approvals', props: '{}');

        $this->assertTrue($result['success']);
        // `[]` would be refused by the admin chat: the component's props are an object.
        $this->assertStringContainsString('"props":{}', $result['block']);
    }

    public function testApprovalsRefusesIdsThatAreNotIds(): void
    {
        $result = new RenderArtifactTool()(
            component: 'approvals',
            props: json_encode(['ids' => ['jane', '0', 0], 'peopleId' => 'jane-cooper']),
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('props.ids[0] must be a numeric id or a uuid', $result['error']);
        $this->assertStringContainsString('props.ids[1] must be a numeric id or a uuid', $result['error']);
        $this->assertStringContainsString('props.ids[2] must be a numeric id or a uuid', $result['error']);
        $this->assertStringContainsString('props.peopleId must be a numeric id or a uuid', $result['error']);
    }

    public function testApprovalsNamesItsFiltersExactly(): void
    {
        $result = new RenderArtifactTool()(
            component: 'approvals',
            props: json_encode(['people_id' => 1845, 'status' => 'open']),
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString(
            'props.people_id is not a valid prop — allowed: status, type, ids, peopleId, limit',
            $result['error']
        );
        $this->assertStringContainsString('props.status must be one of: pending, approved', $result['error']);
    }

    public function testApprovalsLimitIsAWholeNumberInRange(): void
    {
        $tooMany = new RenderArtifactTool()(component: 'approvals', props: json_encode(['limit' => 50]));
        $asText = new RenderArtifactTool()(component: 'approvals', props: json_encode(['limit' => '5']));
        $emptyType = new RenderArtifactTool()(component: 'approvals', props: json_encode(['type' => '  ']));

        $this->assertFalse($tooMany['success']);
        $this->assertStringContainsString('props.limit must be a whole number from 1 to 25', $tooMany['error']);
        $this->assertFalse($asText['success']);
        $this->assertFalse($emptyType['success']);
        $this->assertStringContainsString('props.type must not be empty', $emptyType['error']);
    }

    /**
     * The client validates the rendered block against its own schema and drops the whole
     * artifact if a label is over 40 characters — "Ver eventos en riesgo (próximas 5 semanas)"
     * is 42, and the agent lost a turn's work to `items.0.label: Too big`. The server used to
     * pass it, so the two schemas disagreed and only the client said so, too late to fix.
     */
    public function testAnActionLabelOverTheRendererLimitIsRejectedHere(): void
    {
        $result = new RenderArtifactTool()(
            component: 'actions',
            props: json_encode(['items' => [
                ['label' => 'Ver eventos en riesgo (próximas 5 semanas)', 'message' => 'Eventos en riesgo'],
            ]]),
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('props.items[0].label is 42 characters', $result['error']);
        $this->assertStringContainsString('caps it at 40', $result['error']);
        $this->assertStringContainsString('shorten it', $result['error'], 'the fix must be actionable');
    }

    public function testAnActionLabelAtTheLimitStillRenders(): void
    {
        $result = new RenderArtifactTool()(
            component: 'actions',
            props: json_encode(['items' => [
                ['label' => str_repeat('a', 40), 'message' => 'exactly at the cap'],
                ['label' => 'Eventos en riesgo (5 semanas)', 'message' => 'well under it'],
            ]]),
        );

        $this->assertTrue($result['success']);
    }

    /**
     * `render_artifact` validates what it produces, but nothing stops the model writing the
     * fence by hand — and it does. A reply shipped an `actions` block whose third item was the
     * assistant's own closing question where a label goes; the client refused the artifact and
     * the reader lost the whole card instead of the one bad item.
     *
     * The block goes, the prose stays. That is the version of the answer still worth reading.
     */
    public function testAHandWrittenBlockTheClientWouldRejectIsStrippedFromTheReply(): void
    {
        $block = json_encode([
            'version' => 1,
            'component' => 'actions',
            'props' => ['items' => [
                ['label' => 'Ver cotizaciones pendientes de la banca', 'message' => 'a'],
                [
                    'label' => '¿Deseas profundizar en alguna de estas empresas o enfocar el plan de acción'
                        . ' en alguno de los tres frentes',
                    'message' => 'b',
                ],
            ]],
        ], JSON_UNESCAPED_UNICODE);

        $reply = "Aquí está el análisis.\n\n```kanvas-artifact\n{$block}\n```\n\n¿Deseas profundizar?";
        $stripped = new ArtifactBlockService()->stripInvalidBlocks($reply);

        $this->assertStringNotContainsString('kanvas-artifact', $stripped);
        $this->assertStringContainsString('Aquí está el análisis.', $stripped);
        $this->assertStringContainsString('¿Deseas profundizar?', $stripped);
    }

    public function testAValidHandWrittenBlockSurvivesUntouched(): void
    {
        $block = json_encode([
            'version' => 1,
            'component' => 'actions',
            'props' => ['items' => [['label' => 'Ver cotizaciones', 'message' => 'a']]],
        ], JSON_UNESCAPED_UNICODE);

        $reply = "Texto.\n\n```kanvas-artifact\n{$block}\n```\n\nFin.";

        $this->assertSame($reply, new ArtifactBlockService()->stripInvalidBlocks($reply));
    }

    /**
     * Only this fence is ours. A ```json block in an answer about code must survive.
     */
    public function testOtherFencedBlocksAreNotTouched(): void
    {
        $reply = "Mira:\n\n```json\n{\"a\": 1}\n```\n\nEso es todo.";

        $this->assertSame($reply, new ArtifactBlockService()->stripInvalidBlocks($reply));
    }

    /**
     * Counted in characters, not bytes — the labels are Spanish and an accent must not cost two.
     */
    public function testTheLimitCountsCharactersNotBytes(): void
    {
        $label = str_repeat('á', 40);

        $this->assertSame(80, strlen($label), 'the fixture has to be multi-byte for this to mean anything');

        $result = new RenderArtifactTool()(
            component: 'actions',
            props: json_encode(['items' => [['label' => $label, 'message' => 'accented']]]),
        );

        $this->assertTrue($result['success']);
    }
}
