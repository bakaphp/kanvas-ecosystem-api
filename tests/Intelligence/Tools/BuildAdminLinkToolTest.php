<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\AdminLinks\Enums\AdminLinkSectionEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\BuildAdminLinkTool;
use NeuronAI\Tools\HasRunKey;
use Tests\TestCase;

class BuildAdminLinkToolTest extends TestCase
{
    private const HOST = 'https://admin.kanvas.dev';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kanvas.app.frontend_url', self::HOST);
    }

    public function test_it_links_to_a_record(): void
    {
        $lead = Lead::query()->fromApp(app(Apps::class))->firstOrFail();

        $result = $this->toolFor($lead->company)('lead', $lead->uuid);

        $this->assertSame('success', $result['status']);
        $this->assertStringEndsWith('/leads/' . $lead->uuid, $result['url']);
        $this->assertTrue($result['requires_company']);
        $this->assertSame('CRM', $result['section_permission']);
    }

    public function test_it_links_to_a_list_screen_when_no_id_is_given(): void
    {
        $result = $this->tool()('lead');

        $this->assertSame('success', $result['status']);
        $this->assertStringEndsWith('/leads', $result['url']);
    }

    public function test_it_appends_a_tab(): void
    {
        $lead = Lead::query()->fromApp(app(Apps::class))->firstOrFail();

        $result = $this->toolFor($lead->company)('lead', $lead->uuid, 'history');

        $this->assertSame('success', $result['status']);
        $this->assertStringEndsWith('/leads/' . $lead->uuid . '?tab=history', $result['url']);
    }

    /**
     * `plans` is the *subscription* plan screen. Addressing sections by segment would hand a PM
     * asking for a Nervous System plan a working link to the wrong page — the worst failure mode
     * available, because nothing about the result looks wrong.
     */
    public function test_a_section_cannot_be_addressed_by_its_url_segment(): void
    {
        $result = $this->tool()('plans');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Unknown section', $result['message']);
    }

    /**
     * The failure that shipped: every lead tool hands the agent the numeric id while the leads route
     * keys on the uuid, so rejecting the numeric id left the PM with no way to link a lead it was
     * looking straight at — it opened a plan and a task to have another agent go fetch the uuid.
     */
    public function test_it_resolves_a_record_from_whatever_identifier_the_caller_holds(): void
    {
        $lead = Lead::query()->fromApp(app(Apps::class))->firstOrFail();
        $tool = $this->toolFor($lead->company);

        $fromNumericId = $tool('lead', (string) $lead->getId());
        $fromUuid = $tool('lead', $lead->uuid);

        $this->assertSame('success', $fromNumericId['status'], json_encode($fromNumericId));
        $this->assertStringEndsWith('/leads/' . $lead->uuid, $fromNumericId['url']);
        $this->assertSame($fromUuid['url'], $fromNumericId['url']);
    }

    public function test_it_says_the_record_does_not_exist_rather_than_complaining_about_the_id_shape(): void
    {
        $result = $this->tool()('lead', '999999999');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('No lead with identifier', $result['message']);
        $this->assertStringContainsString('Do not retry', $result['message']);
    }

    /** Sections with no model behind them still validate the shape rather than emitting a dead link. */
    public function test_it_reports_an_identifier_that_does_not_fit_an_unresolvable_route(): void
    {
        $result = $this->tool()('attribute', '42');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('not valid', $result['message']);
    }

    public function test_it_reports_an_unknown_section_instead_of_guessing(): void
    {
        $result = $this->tool()('nervous_system_task');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Unknown section', $result['message']);
    }

    public function test_it_reports_a_missing_admin_host_instead_of_inventing_one(): void
    {
        config()->set('kanvas.app.frontend_url', null);

        $result = $this->tool()('lead');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('no admin URL configured', $result['message']);
    }

    public function test_it_refuses_to_run_without_tenant_context(): void
    {
        $result = new BuildAdminLinkTool()('lead');

        $this->assertSame('error', $result['status']);
    }

    /** Every section the model can be asked for has to be one the tool actually accepts. */
    public function test_every_advertised_section_resolves(): void
    {
        foreach (AdminLinkSectionEnum::aliases() as $alias) {
            $this->assertNotNull(
                AdminLinkSectionEnum::tryFromAlias($alias),
                $alias . ' is advertised to the model but does not resolve.'
            );
        }
    }

    /**
     * NeuronAI caps a tool at 10 runs per turn keyed on the tool NAME by default. A status report that
     * links a dozen projects is a dozen DISTINCT calls, so without an input-keyed budget the eleventh
     * throws ToolRunsExceededException and takes the whole turn down with it.
     */
    public function test_its_run_budget_is_keyed_by_inputs_not_by_tool_name(): void
    {
        $tool = $this->tool();
        $this->assertInstanceOf(HasRunKey::class, $tool);

        $one = $tool->setInputs(['section' => 'agent_project', 'id' => '11'])->getRunKey();
        $two = $tool->setInputs(['section' => 'agent_project', 'id' => '12'])->getRunKey();
        $oneAgain = $tool->setInputs(['section' => 'agent_project', 'id' => '11'])->getRunKey();

        $this->assertNotEquals($one, $two, 'Distinct records must not share a run budget.');
        $this->assertEquals($oneAgain, $one, 'Identical calls must collapse to one key so a loop stays capped.');
    }

    private function tool(): BuildAdminLinkTool
    {
        return $this->toolFor(static::$cachedUser->getCurrentCompany());
    }

    private function toolFor(Companies $company): BuildAdminLinkTool
    {
        return new BuildAdminLinkTool()->withContext(app(Apps::class), $company, static::$cachedUser);
    }
}
