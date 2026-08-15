<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ArtifactsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\DeleteDealTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetDealRefTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\LeadRefTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\VehicleInterestTool;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * lead_id is an LLM-supplied integer on every CRM tool, so a prompt-injected prospect can name any
 * id on the platform. Before the fix these tools resolved it with a bare Lead::getById(), handing
 * another company's prospect PII (name, emails, phones, address, owner email) straight back into
 * the chat — and letting the write/send tools act on that lead. Every tool must resolve against the
 * tenant on its own context, so a foreign id is indistinguishable from a hallucinated one.
 */
class LeadToolTenantScopingTest extends TestCase
{
    public function testLeadRefToolDoesNotReturnAnotherCompanysLead(): void
    {
        $foreignLead = $this->makeForeignLead();

        $result = $this->withTenant(new LeadRefTool())->__invoke(lead_id: $foreignLead->getId());

        $this->assertSame('error', $result['status']);
        $this->assertArrayNotHasKey('people', $result);
    }

    public function testReadToolsDoNotReturnAnotherCompanysLead(): void
    {
        $foreignLead = $this->makeForeignLead();

        foreach ([new VehicleInterestTool(), new ArtifactsTool()] as $tool) {
            $result = $this->withTenant($tool)->__invoke(lead_id: $foreignLead->getId());

            $this->assertSame(
                'error',
                $result['status'] ?? null,
                $tool::class . ' resolved a lead outside the tool tenant',
            );
        }
    }

    public function testToolWithoutContextFailsClosedInsteadOfResolvingGlobally(): void
    {
        $ownLead = Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create();

        $result = new LeadRefTool()->__invoke(lead_id: $ownLead->getId());

        $this->assertSame('error', $result['status']);
    }

    public function testDealToolsDoNotReachAnotherCompanysDeal(): void
    {
        $otherCompany = Companies::factory()->create();
        $foreignDeal = new Deal();
        $foreignDeal->apps_id = app(Apps::class)->getId();
        $foreignDeal->companies_id = $otherCompany->getId();
        $foreignDeal->users_id = auth()->user()->getId();
        $foreignDeal->owner_id = auth()->user()->getId();
        $foreignDeal->title = 'Foreign Deal ' . uniqid();
        $foreignDeal->is_deleted = 0;
        $foreignDeal->saveOrFail();

        $read = $this->withTenant(new GetDealRefTool())->__invoke(deal_id: $foreignDeal->getId());
        $this->assertSame('error', $read['status']);

        // delete_deal is destructive, so a foreign id must not even reach the soft-delete.
        $deleted = $this->withTenant(new DeleteDealTool())->__invoke(deal_id: $foreignDeal->getId());
        $this->assertSame('error', $deleted['status']);
        $this->assertSame(0, (int) $foreignDeal->fresh()->is_deleted);
    }

    public function testLeadInTheToolTenantStillResolves(): void
    {
        $ownLead = Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create();

        $result = $this->withTenant(new LeadRefTool())->__invoke(lead_id: $ownLead->getId());

        $this->assertSame($ownLead->getId(), $result['lead_id']);
    }

    private function makeForeignLead(): Lead
    {
        $otherCompany = Companies::factory()->create();

        return Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($otherCompany->getId())
            ->create();
    }

    /**
     * @template T of object
     *
     * @param T $tool
     *
     * @return T
     */
    private function withTenant(object $tool): object
    {
        /** @var Users $user */
        $user = auth()->user();

        return $tool->withContext(app(Apps::class), $user->getCurrentCompany(), $user);
    }
}
