<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateDealTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\FindDealsBulkTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\FindLeadsBulkTool;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class FindLeadsAndDealsBulkToolTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    private Apps $currentApp;
    private Companies $currentCompany;
    private Users $actingUser;
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->actingUser = static::$cachedUser;
        $this->currentCompany = $this->actingUser->getCurrentCompany();
        $this->tag = 'Blk' . uniqid();
    }

    public function test_leads_resolve_a_whole_list_in_one_call_preserving_order(): void
    {
        $jorgelina = $this->makeLead('Jorgelina' . $this->tag, 'Duran' . $this->tag);
        $sandra = $this->makeLead('Sandra' . $this->tag, 'Pichardo' . $this->tag);

        $result = $this->leadTool()->__invoke(
            names: sprintf(
                'Jorgelina%1$s Duran%1$s, Sandra%1$s Pichardo%1$s, Noexiste%1$s Persona%1$s',
                $this->tag,
            ),
        );

        $this->assertSame(3, $result['searched']);
        $this->assertSame(2, $result['matched']);

        $this->assertTrue($result['results'][0]['found']);
        $this->assertSame($jorgelina->getId(), $result['results'][0]['matches'][0]['lead_id']);

        $this->assertTrue($result['results'][1]['found']);
        $this->assertSame($sandra->getId(), $result['results'][1]['matches'][0]['lead_id']);

        $this->assertFalse($result['results'][2]['found']);
        $this->assertContains('Noexiste' . $this->tag . ' Persona' . $this->tag, $result['not_found']);
    }

    public function test_leads_match_on_the_lead_title_too(): void
    {
        $lead = $this->makeLead('Aura' . $this->tag, 'Lluberes' . $this->tag);
        $lead->title = 'Renovacion' . $this->tag . ' Contrato' . $this->tag;
        $lead->saveOrFail();

        $result = $this->leadTool()->__invoke(
            names: 'Renovacion' . $this->tag . ' Contrato' . $this->tag,
        );

        $this->assertTrue($result['results'][0]['found']);
        $this->assertSame($lead->getId(), $result['results'][0]['matches'][0]['lead_id']);
    }

    /** "Do we have a lead for this person" must say yes for a closed lead, not a false blank. */
    public function test_leads_include_closed_by_default_and_are_filterable(): void
    {
        $closed = $this->makeLead('Cerrada' . $this->tag, 'Historica' . $this->tag);
        $closed->status = 2;
        $closed->saveOrFail();

        $name = 'Cerrada' . $this->tag . ' Historica' . $this->tag;

        $byDefault = $this->leadTool()->__invoke(names: $name);
        $this->assertTrue($byDefault['results'][0]['found'], 'A closed lead still counts as found by default');
        $this->assertFalse($byDefault['results'][0]['matches'][0]['is_open']);

        $openOnly = $this->leadTool()->__invoke(names: $name, status: 'open');
        $this->assertFalse($openOnly['results'][0]['found'], 'status=open must exclude the closed lead');
    }

    public function test_leads_do_not_leak_across_companies(): void
    {
        $otherCompany = Companies::factory()->create();
        $people = People::factory()
            ->withAppId($this->currentApp->getId())
            ->withCompanyId($otherCompany->getId())
            ->create(['firstname' => 'Foreign' . $this->tag, 'lastname' => 'Lead' . $this->tag]);

        Lead::factory()
            ->withAppId($this->currentApp->getId())
            ->withCompanyId($otherCompany->getId())
            ->create([
                'people_id' => $people->getId(),
                'title' => 'Foreign' . $this->tag . ' Lead' . $this->tag,
            ]);

        $result = $this->leadTool()->__invoke(names: 'Foreign' . $this->tag . ' Lead' . $this->tag);

        $this->assertFalse($result['results'][0]['found']);
    }

    public function test_leads_blank_input_returns_an_actionable_error(): void
    {
        $result = $this->leadTool()->__invoke(names: ' , , ');

        $this->assertSame(0, $result['searched']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_deals_resolve_a_whole_list_in_one_call(): void
    {
        $people = People::factory()
            ->withAppId($this->currentApp->getId())
            ->withCompanyId($this->currentCompany->getId())
            ->create(['firstname' => 'Claudia' . $this->tag, 'lastname' => 'Garcia' . $this->tag]);

        $created = new CreateDealTool($this->currentApp, $this->currentCompany, $this->actingUser)
            ->__invoke(
                title: 'Bienestar' . $this->tag,
                description: 'wellness program',
                people_id: $people->getId(),
            );

        $result = $this->dealTool()->__invoke(
            names: sprintf(
                'Claudia%1$s Garcia%1$s, Nadie%1$s Aqui%1$s',
                $this->tag,
            ),
        );

        $this->assertSame(2, $result['searched']);
        $this->assertSame(1, $result['matched']);

        $this->assertTrue($result['results'][0]['found']);
        $this->assertSame((int) $created['deal_id'], $result['results'][0]['matches'][0]['deal_id']);
        $this->assertSame('Bienestar' . $this->tag, $result['results'][0]['matches'][0]['title']);

        $this->assertFalse($result['results'][1]['found']);
        $this->assertContains('Nadie' . $this->tag . ' Aqui' . $this->tag, $result['not_found']);
    }

    public function test_deals_blank_input_returns_an_actionable_error(): void
    {
        $result = $this->dealTool()->__invoke(names: '');

        $this->assertSame(0, $result['searched']);
        $this->assertArrayHasKey('error', $result);
    }

    private function leadTool(): FindLeadsBulkTool
    {
        return new FindLeadsBulkTool()
            ->withContext($this->currentApp, $this->currentCompany, $this->actingUser);
    }

    private function dealTool(): FindDealsBulkTool
    {
        return new FindDealsBulkTool()
            ->withContext($this->currentApp, $this->currentCompany, $this->actingUser);
    }

    private function makeLead(string $first, string $last): Lead
    {
        $people = People::factory()
            ->withAppId($this->currentApp->getId())
            ->withCompanyId($this->currentCompany->getId())
            ->create(['firstname' => $first, 'lastname' => $last]);

        return Lead::factory()
            ->withAppId($this->currentApp->getId())
            ->withCompanyId($this->currentCompany->getId())
            ->create([
                'people_id' => $people->getId(),
                'title' => $first . ' ' . $last,
            ]);
    }
}
