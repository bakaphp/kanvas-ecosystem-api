<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ConvertLeadToDealTool;
use Tests\TestCase;

class ConvertLeadToDealToolTest extends TestCase
{
    private function makeLead(): Lead
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create(['title' => 'Lead ' . uniqid()]);
    }

    /**
     * Lead tools resolve their lead against the tenant on their context, so a bare instance
     * (no withContext) intentionally resolves nothing — mirror what the agent wiring does.
     *
     * @template T of object
     *
     * @param T $tool
     *
     * @return T
     */
    private function withTenant(object $tool): object
    {
        $user = auth()->user();

        return $tool->withContext(app(Apps::class), $user->getCurrentCompany(), $user);
    }

    public function testConvertsLeadToDealAndLinksBack(): void
    {
        $lead = $this->makeLead();

        $result = $this->withTenant(new ConvertLeadToDealTool())->__invoke(lead_id: $lead->getId());

        $this->assertSame('success', $result['status']);

        $deal = Deal::getById((int) $result['deal_id']);
        $this->assertSame($lead->getId(), $deal->leads_id);
        $this->assertSame($lead->people_id, $deal->people_id);
        $this->assertSame($lead->title, $deal->title);

        // lead is stamped with the resulting deal id for traceability + double-convert guard
        $lead->refresh();
        $this->assertSame(
            (int) $result['deal_id'],
            (int) $lead->get(ConfigurationEnum::CONVERTED_TO_DEAL_ID->value),
        );

        // the source lead is closed on conversion (Lead::close() → leads_status_id 6)
        $this->assertSame(6, $lead->leads_status_id);
    }

    public function testSecondConversionIsNoopReturningSameDeal(): void
    {
        $lead = $this->makeLead();

        $first = $this->withTenant(new ConvertLeadToDealTool())->__invoke(lead_id: $lead->getId());
        $second = $this->withTenant(new ConvertLeadToDealTool())->__invoke(lead_id: $lead->getId());

        $this->assertSame('noop', $second['status']);
        $this->assertSame((int) $first['deal_id'], (int) $second['deal_id']);
    }

    public function testHallucinatedLeadIdReturnsError(): void
    {
        $result = $this->withTenant(new ConvertLeadToDealTool())->__invoke(lead_id: 999999999);

        $this->assertSame('error', $result['status']);
    }
}
