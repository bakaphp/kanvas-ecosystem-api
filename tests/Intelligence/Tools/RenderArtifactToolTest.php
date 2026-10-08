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

    public function testAnEntityTakesEveryRecordTypeTheAdminDraws(): void
    {
        $uuid = '3f2b9c1e-8a4d-4c2e-9b1f-0a1b2c3d4e5f';

        foreach ([['event', 210], ['discount', 12], ['agent', $uuid], ['category', 'summer-sale']] as [$type, $id]) {
            $result = new RenderArtifactTool()(
                component: 'entity',
                props: ['type' => $type, 'id' => $id, 'title' => 'A record'],
            );

            $this->assertTrue($result['success'], $type . ' is a record type the admin draws');
        }

        $unknown = new RenderArtifactTool()(
            component: 'entity',
            props: ['type' => 'invoice', 'id' => 1, 'title' => 'Invoice 1'],
        );

        $this->assertFalse($unknown['success']);
        $this->assertStringContainsString('props.type must be one of: lead, deal, people', $unknown['error']);
    }

    /**
     * The card reads the row and links with the identifier the row carries, so it is found by more
     * than its page opens with: an order by its uuid, a product by the numeric id a tool returns.
     */
    public function testAnEntityIsFoundByMoreThanItsPageReads(): void
    {
        $uuid = '5f1c9c1e-8a4d-4c2e-9b1f-0a1b2c3d4e5f';

        foreach ([['order', $uuid], ['order', 1042], ['product', 482], ['product', $uuid], ['lead', $uuid]] as [$type, $id]) {
            $result = new RenderArtifactTool()(
                component: 'entity',
                props: ['type' => $type, 'id' => $id, 'title' => 'A record'],
            );

            $this->assertTrue($result['success'], $type . ' is found by ' . $id);
        }
    }

    public function testAnEntityIdHasToBeOneItsCardCanFindItBy(): void
    {
        $uuid = '5f1c9c1e-8a4d-4c2e-9b1f-0a1b2c3d4e5f';
        $card = static fn (string $type, string|int $id): array => new RenderArtifactTool()(
            component: 'entity',
            props: ['type' => $type, 'id' => $id, 'title' => 'A record'],
        );

        $pipeline = $card('pipeline', $uuid);
        // Its uuid is the secret of its webhook: nothing reads a project by it.
        $project = $card('agent_project', $uuid);
        $named = $card('lead', 'acme-renewal');
        $zero = $card('lead', 0);
        $traversal = $card('category', '../settings');
        $categoryUuid = $card('category', $uuid);

        $this->assertFalse($pipeline['success']);
        $this->assertStringContainsString('props.id must be the numeric id of the pipeline', $pipeline['error']);
        $this->assertFalse($project['success']);
        $this->assertFalse($named['success'], 'A name is not an id');
        $this->assertStringContainsString('props.id must be the numeric id or the uuid of the lead', $named['error']);
        $this->assertFalse($zero['success']);
        $this->assertFalse($traversal['success'], 'A slug is one path segment');
        $this->assertFalse($categoryUuid['success'], 'The admin reads no category by its uuid');
        $this->assertStringContainsString('props.id must be the slug of the category', $categoryUuid['error']);
        // For a type that opens by slug the type decides: digits are a slug there too.
        $this->assertTrue($card('category', '2024')['success']);
    }

    public function testRecordsTakesWhatToListNeverTheRows(): void
    {
        $result = new RenderArtifactTool()(
            component: 'records',
            props: json_encode(['type' => 'lead', 'filter' => ['statusId' => [3, '4'], 'pipelineId' => 2], 'limit' => 5]),
            title: 'Open leads',
        );
        $searched = new RenderArtifactTool()(
            component: 'records',
            props: json_encode(['type' => 'people', 'search' => 'cooper']),
        );
        $rows = new RenderArtifactTool()(
            component: 'records',
            props: json_encode(['type' => 'lead', 'rows' => [['title' => 'Acme']]]),
        );

        $this->assertTrue($result['success']);
        $this->assertStringContainsString(
            '"props":{"type":"lead","filter":{"statusId":[3,"4"],"pipelineId":2},"limit":5}',
            $result['block']
        );
        $this->assertTrue($searched['success']);
        $this->assertFalse($rows['success']);
        $this->assertStringContainsString('props.rows is not a valid prop — allowed: type, filter, search, limit', $rows['error']);
    }

    /**
     * Dropped instead of refused, a misspelled filter would leave `{type: lead}`: every lead in the
     * company under a title about one person.
     */
    public function testAFilterTheTypeDoesNotTakeIsRefusedNotDropped(): void
    {
        $misspelled = new RenderArtifactTool()(
            component: 'records',
            props: json_encode(['type' => 'lead', 'filter' => ['person_id' => 1845]]),
        );
        $none = new RenderArtifactTool()(
            component: 'records',
            props: json_encode(['type' => 'role', 'filter' => ['name' => 'Sales']]),
        );

        $this->assertFalse($misspelled['success']);
        $this->assertStringContainsString(
            'props.filter.person_id is not a filter of lead lists — allowed: statusId, pipelineId, stageId, personId',
            $misspelled['error']
        );
        $this->assertFalse($none['success']);
        $this->assertStringContainsString('role lists take no filters', $none['error']);
    }

    public function testAFilterValueHasToBeTheKindTheFilterTakes(): void
    {
        $result = new RenderArtifactTool()(
            component: 'records',
            props: json_encode([
                'type' => 'order',
                'filter' => [
                    'status' => ['shipped'],
                    'personId' => [1, 2],
                    'orderTypeId' => ['wholesale'],
                ],
            ]),
        );
        $flag = new RenderArtifactTool()(
            component: 'records',
            props: json_encode(['type' => 'product', 'filter' => ['published' => 'yes']]),
        );
        $list = new RenderArtifactTool()(
            component: 'records',
            props: json_encode(['type' => 'lead', 'filter' => [3, 4]]),
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString(
            'props.filter.status[0] must be one of: pending, completed, draft, canceled, failed',
            $result['error']
        );
        $this->assertStringContainsString('props.filter.personId takes one value, not a list', $result['error']);
        $this->assertStringContainsString('props.filter.orderTypeId[0] must be the numeric id, not the uuid', $result['error']);
        $this->assertFalse($flag['success']);
        $this->assertStringContainsString('props.filter.published must be true or false', $flag['error']);
        $this->assertFalse($list['success']);
        $this->assertStringContainsString('props.filter must be an object of filters', $list['error']);
    }

    /**
     * Every column a list filters by is an integer one. The admin refuses to run a list given a uuid
     * there, and each tool now hands the model a lead's or a person's uuid next to its id.
     */
    public function testAListIsNarrowedByIntegerIdsOnly(): void
    {
        $byUuid = new RenderArtifactTool()(
            component: 'records',
            props: json_encode(['type' => 'lead', 'filter' => ['personId' => '5f1c9c1e-8a4d-4c2e-9b1f-0a1b2c3d4e5f']]),
        );
        $byId = new RenderArtifactTool()(
            component: 'records',
            props: json_encode(['type' => 'lead', 'filter' => ['personId' => '1845', 'ownerId' => 0, 'statusId' => [3, '4']]]),
        );

        $tooLong = new RenderArtifactTool()(
            component: 'records',
            props: json_encode(['type' => 'lead', 'filter' => ['ownerId' => 1_000_000_000_000_000]]),
        );

        $this->assertFalse($byUuid['success']);
        $this->assertStringContainsString('props.filter.personId must be the numeric id, not the uuid', $byUuid['error']);
        $this->assertTrue($byId['success']);
        $this->assertFalse($tooLong['success'], 'A number and the same number as text are held to one rule');
    }

    /**
     * The API answers a search on these lists with an error, so the admin never runs one and the card
     * would say the list cannot be shown.
     */
    public function testAListThatCannotBeSearchedSaysWhatToFilterBy(): void
    {
        $result = new RenderArtifactTool()(
            component: 'records',
            props: json_encode(['type' => 'participant', 'search' => 'cooper']),
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString(
            'props.search is not available for participant lists — narrow the list with a filter instead: prospect, personId',
            $result['error']
        );
    }

    /**
     * The admin's search ignores every other clause: sent together, the list would show the search
     * alone under a title that promised the filter too.
     */
    public function testSearchAndFilterAreNotSentTogether(): void
    {
        $both = new RenderArtifactTool()(
            component: 'records',
            props: json_encode(['type' => 'lead', 'search' => 'acme', 'filter' => ['pipelineId' => 2]]),
        );
        $emptyFilter = new RenderArtifactTool()(
            component: 'records',
            props: '{"type":"lead","search":"acme","filter":{}}',
        );

        $this->assertFalse($both['success']);
        $this->assertStringContainsString('props.search cannot be combined with props.filter', $both['error']);
        $this->assertTrue($emptyFilter['success']);
        // `{}` decodes to an empty PHP array; written back as `[]` it would read as a list.
        $this->assertStringNotContainsString('"filter"', $emptyFilter['block']);
    }

    public function testOnlyTypesTheAdminCanListAreListed(): void
    {
        $result = new RenderArtifactTool()(component: 'records', props: json_encode(['type' => 'warehouse']));
        $tooMany = new RenderArtifactTool()(component: 'records', props: json_encode(['type' => 'lead', 'limit' => 26]));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('props.type must be one of: lead, deal, people', $result['error']);
        $this->assertFalse($tooMany['success']);
        $this->assertStringContainsString('props.limit must be a whole number from 1 to 25', $tooMany['error']);
    }

    public function testAMetricIsNamedNeverComputed(): void
    {
        $result = new RenderArtifactTool()(
            component: 'metric',
            props: json_encode(['metric' => 'leads.by_status', 'window' => 'last_30d', 'as' => 'table']),
            title: 'Leads by status',
        );
        $invented = new RenderArtifactTool()(
            component: 'metric',
            props: json_encode(['metric' => 'inventory.low_stock']),
        );
        $numbers = new RenderArtifactTool()(
            component: 'metric',
            props: json_encode(['metric' => 'orders.revenue', 'value' => 12500]),
        );

        $this->assertTrue($result['success']);
        $this->assertStringContainsString(
            '"component":"metric","title":"Leads by status","props":{"metric":"leads.by_status","window":"last_30d","as":"table"}',
            $result['block']
        );
        $this->assertFalse($invented['success']);
        $this->assertStringContainsString('props.metric must be one of: leads.total', $invented['error']);
        $this->assertFalse($numbers['success']);
        $this->assertStringContainsString('props.value is not a valid prop', $numbers['error']);
    }

    /**
     * The same checks guard a block the model wrote by hand, which never passes through the tool.
     */
    public function testAHandWrittenLiveBlockIsHeldToTheSameRules(): void
    {
        $valid = "```kanvas-artifact\n"
            . '{"version":1,"component":"records","title":"Open leads","props":{"type":"lead","filter":{"pipelineId":2}}}'
            . "\n```";
        $widened = "```kanvas-artifact\n"
            . '{"version":1,"component":"records","title":"Leads of Jane","props":{"type":"lead","filter":{"person":1845}}}'
            . "\n```";
        $wrongRecord = "```kanvas-artifact\n"
            . '{"version":1,"component":"entity","props":{"type":"pipeline","id":"5f1c9c1e-8a4d-4c2e-9b1f-0a1b2c3d4e5f","title":"Sales"}}'
            . "\n```";

        $service = new ArtifactBlockService();

        // With prose around it: a reply that is nothing but a block comes back as written either way.
        $this->assertSame("Aquí están:\n\n" . $valid, $service->stripInvalidBlocks("Aquí están:\n\n" . $valid));
        $this->assertSame("Aquí están:\n\n", $service->stripInvalidBlocks("Aquí están:\n\n" . $widened));
        $this->assertSame("Mira:\n\n", $service->stripInvalidBlocks("Mira:\n\n" . $wrongRecord));
    }

    /**
     * The admin lifts props written beside `props`, takes `filters` for `filter` and a count sent as
     * text. Held to the tool's stricter rules, the filter over a reply would strip cards it draws.
     */
    public function testAHandWrittenLiveBlockIsReadTheWayTheAdminReadsIt(): void
    {
        $beside = "```kanvas-artifact\n"
            . '{"version":1,"component":"metric","title":"Leads","metric":"leads.total","window":"last_30d"}'
            . "\n```";
        $aliased = "```kanvas-artifact\n"
            . '{"version":1,"component":"records","type":"lead","props":{"filters":{"pipelineId":2},"limit":"5"}}'
            . "\n```";
        $bothSpellings = "```kanvas-artifact\n"
            . '{"version":1,"component":"records","props":{"type":"lead","filter":{"pipelineId":2},"filters":{"stageId":3}}}'
            . "\n```";
        $notAnObject = "```kanvas-artifact\n"
            . '{"version":1,"component":"records","type":"lead","props":["lead"]}'
            . "\n```";

        $service = new ArtifactBlockService();

        // With prose around them: a reply that is nothing but a block comes back as written either way.
        $this->assertSame("Mira:\n\n" . $beside, $service->stripInvalidBlocks("Mira:\n\n" . $beside));
        $this->assertSame("Mira:\n\n" . $aliased, $service->stripInvalidBlocks("Mira:\n\n" . $aliased));
        $this->assertSame("Mira:\n\n", $service->stripInvalidBlocks("Mira:\n\n" . $bothSpellings));
        $this->assertSame("Mira:\n\n", $service->stripInvalidBlocks("Mira:\n\n" . $notAnObject));
    }

    /**
     * A model writing the fence by hand can put anything where the component name goes. Read as a
     * string, a list there was a fatal that took the whole reply down with the bad card.
     */
    public function testABlockWhoseComponentIsNotANameIsStrippedLikeAnyOther(): void
    {
        $block = "```kanvas-artifact\n"
            . '{"version":1,"component":["records"],"props":{"type":"lead"}}'
            . "\n```";

        $this->assertSame("Mira:\n\n", new ArtifactBlockService()->stripInvalidBlocks("Mira:\n\n" . $block));
    }

    /**
     * The admin refuses to draw text over these lengths, so a callout that runs long is an error the
     * model can shorten rather than a card that never appears.
     */
    public function testTextTheAdminCapsIsCappedHere(): void
    {
        $long = new RenderArtifactTool()(
            component: 'callout',
            props: ['variant' => 'warning', 'text' => str_repeat('a', 501)],
        );
        $untitled = new RenderArtifactTool()(
            component: 'entity',
            props: ['type' => 'lead', 'id' => 12, 'title' => ''],
        );

        $heading = new RenderArtifactTool()(
            component: 'callout',
            props: ['variant' => 'info', 'heading' => str_repeat('a', 81), 'text' => '   '],
        );
        $button = new RenderArtifactTool()(
            component: 'actions',
            props: ['items' => [['label' => '', 'message' => str_repeat('a', 501)]]],
        );
        $column = new RenderArtifactTool()(
            component: 'table',
            props: ['columns' => [['key' => '', 'label' => 'Name']], 'rows' => []],
        );

        $this->assertFalse($long['success']);
        $this->assertStringContainsString('props.text is 501 characters; the renderer caps it at 500', $long['error']);
        $this->assertFalse($untitled['success']);
        $this->assertStringContainsString('props.title must not be empty', $untitled['error']);
        $this->assertFalse($heading['success']);
        $this->assertStringContainsString('props.heading is 81 characters; the renderer caps it at 80', $heading['error']);
        $this->assertStringContainsString('props.text must not be empty', $heading['error']);
        $this->assertFalse($button['success']);
        $this->assertStringContainsString('props.items[0].label must not be empty', $button['error']);
        $this->assertStringContainsString('props.items[0].message is 501 characters', $button['error']);
        $this->assertFalse($column['success']);
        $this->assertStringContainsString('props.columns[0].key must not be empty', $column['error']);
    }

    /**
     * `{}` decodes to an empty PHP array. Written back as `[]` the admin reads a list where a row goes
     * and refuses the whole table.
     */
    public function testAnEmptyRowStaysAnObject(): void
    {
        $result = new RenderArtifactTool()(
            component: 'table',
            props: '{"columns":[{"key":"a","label":"A"}],"rows":[{"a":1},{}]}',
        );

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('"rows":[{"a":1},{}]', $result['block']);
    }

    /**
     * A count sent as text is read as the admin reads it, but only a short one: a digit string too
     * long for an integer is not a row count, and casting it would be an error, not a refusal.
     */
    public function testAnAbsurdLimitIsRefusedNotCast(): void
    {
        $block = "```kanvas-artifact\n"
            . '{"version":1,"component":"records","props":{"type":"lead","limit":"99999999999999999999"}}'
            . "\n```";

        $this->assertSame("Mira:\n\n", new ArtifactBlockService()->stripInvalidBlocks("Mira:\n\n" . $block));
    }

    /**
     * A table shows a boolean cell; a chart plots none, and the admin refuses the whole chart for one.
     */
    public function testAChartPlotsNoBooleans(): void
    {
        $chart = new RenderArtifactTool()(
            component: 'chart',
            props: [
                'kind' => 'bar',
                'xKey' => 'month',
                'series' => [['key' => 'won']],
                'data' => [['month' => 'Jan', 'won' => true]],
            ],
        );
        $table = new RenderArtifactTool()(
            component: 'table',
            props: ['columns' => [['key' => 'won', 'label' => 'Won']], 'rows' => [['won' => true]]],
        );

        $this->assertFalse($chart['success']);
        $this->assertStringContainsString('props.data[0].won must be a string, number or null', $chart['error']);
        $this->assertTrue($table['success']);
    }

    /**
     * With no tenant there is nothing to check an id against, and a context-free caller still gets a
     * block validated for shape rather than a fatal on an unset property.
     */
    public function testWithoutATenantAnEntityIsOnlyCheckedForShape(): void
    {
        $result = new RenderArtifactTool()(
            component: 'entity',
            props: ['type' => 'lead', 'id' => 987654321, 'title' => 'A lead nobody looked up'],
        );

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('"id":987654321', $result['block']);
    }
}
