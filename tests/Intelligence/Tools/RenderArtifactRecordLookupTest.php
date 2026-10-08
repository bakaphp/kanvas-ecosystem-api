<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Enums\ToolOutcomeEnum;
use Kanvas\Intelligence\Agents\Neuron\Tools\Common\RenderArtifactTool;
use Tests\TestCase;

/**
 * An `entity` card reads its record live, so the id in the block is a lookup key the model chose. With
 * a tenant in scope the tool checks it before the block exists: an invented id becomes an error the
 * model can act on, and a real one is rewritten to the identifier the record's page reads.
 */
final class RenderArtifactRecordLookupTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

    /**
     * Every lead tool hands the model the numeric id, while the lead page keys on the uuid.
     */
    public function testTheIdAToolReturnedBecomesTheOneThePageReads(): void
    {
        $lead = $this->lead($this->company());

        $result = $this->tool()(
            component: 'entity',
            props: ['type' => 'lead', 'id' => $lead->getId(), 'title' => 'Acme renewal'],
        );

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('"id":"' . $lead->uuid . '"', $result['block']);
    }

    public function testAUuidIsKeptAsItIs(): void
    {
        $lead = $this->lead($this->company());

        $result = $this->tool()(
            component: 'entity',
            props: json_encode(['type' => 'lead', 'id' => $lead->uuid, 'title' => 'Acme renewal']),
        );

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('"id":"' . $lead->uuid . '"', $result['block']);
    }

    public function testAnInventedIdIsRefusedBeforeItBecomesACard(): void
    {
        $result = $this->tool()(
            component: 'entity',
            props: ['type' => 'lead', 'id' => 2147483600, 'title' => 'A lead that does not exist'],
        );

        $this->assertFalse($result['success']);
        $this->assertSame(ToolOutcomeEnum::NOT_FOUND->value, $result['outcome']);
        $this->assertStringContainsString('No lead with id "2147483600" exists for this company', $result['error']);
        $this->assertArrayNotHasKey('block', $result);
    }

    /**
     * The id is the model's text and therefore prompt-injectable: another company's record has to be
     * indistinguishable from one that does not exist, or the card would confirm and link it.
     */
    public function testAnotherCompanysRecordIsNotFound(): void
    {
        $foreign = $this->lead(Companies::factory()->create());

        foreach ([$foreign->getId(), $foreign->uuid] as $id) {
            $result = $this->tool()(
                component: 'entity',
                props: ['type' => 'lead', 'id' => $id, 'title' => 'Somebody else\'s lead'],
            );

            $this->assertFalse($result['success']);
            $this->assertSame(ToolOutcomeEnum::NOT_FOUND->value, $result['outcome']);
        }
    }

    /**
     * No model stands behind a discount in the record resolver, so there is nothing to look up: the id
     * is held to the kind its page reads and written as given.
     */
    public function testATypeWithNoLookupIsCheckedForShapeOnly(): void
    {
        $result = $this->tool()(
            component: 'entity',
            props: ['type' => 'discount', 'id' => 12, 'title' => 'SUMMER20'],
        );

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('"id":12', $result['block']);
    }

    private function lead(Companies $company): Lead
    {
        return Lead::factory()
            ->withAppAndCompany(app(Apps::class)->getId(), $company->getId())
            ->create();
    }

    private function company(): Companies
    {
        return static::$cachedUser->getCurrentCompany();
    }

    private function tool(): RenderArtifactTool
    {
        return new RenderArtifactTool()->withContext(app(Apps::class), $this->company(), static::$cachedUser);
    }
}
