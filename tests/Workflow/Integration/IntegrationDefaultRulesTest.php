<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Zoho\Handlers\ZohoHandler;
use Kanvas\Connectors\Zoho\Workflows\ZohoPullLeadActivity;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Integrations\Actions\RemoveIntegrationCompanyAction;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Models\Integrations;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleType;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

/**
 * Connecting an integration gives the company the rules its handler declares, disconnecting takes
 * them away, and the backfill command restores them for tenants connected before this existed.
 */
final class IntegrationDefaultRulesTest extends TestCase
{
    use DatabaseTransactions;
    use HasIntegrationCompany;

    protected $connectionsToTransact = [null, 'workflow'];

    public function testConnectingZohoCreatesItsPullRuleAndDisconnectingRemovesIt(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $this->catalogueZohoPull();

        $this->setIntegration($app, IntegrationsEnum::ZOHO, ZohoHandler::class, $company, $user);

        $rule = $this->zohoPullRule($app, $company->getId());
        $this->assertNotNull($rule, 'connecting Zoho must create its default pull rule for the company');
        $this->assertFalse($rule->runAsync());
        $this->assertSame(
            ZohoPullLeadActivity::class,
            $rule->workflowActivities->first()->activity->actionClass()
        );

        $integrationCompany = $this->zohoIntegrationCompany($company->getId());
        new RemoveIntegrationCompanyAction($integrationCompany, $app)->execute();

        $this->assertNull($this->zohoPullRule($app, $company->getId()), 'disconnecting must soft-delete the default rule');
    }

    public function testTheBackfillCommandRestoresTheRuleForAnAlreadyConnectedTenant(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $this->catalogueZohoPull();

        $this->setIntegration($app, IntegrationsEnum::ZOHO, ZohoHandler::class, $company, $user);
        $rule = $this->zohoPullRule($app, $company->getId());
        $rule->is_deleted = 1;
        $rule->saveQuietly();

        $this->artisan('kanvas:workflow-seed-default-rules', [
            'app_id' => $app->getId(),
            '--company_id' => $company->getId(),
        ])->assertSuccessful();

        $restored = $this->zohoPullRule($app, $company->getId());
        $this->assertNotNull($restored);
        $this->assertSame($rule->getId(), $restored->getId(), 'the backfill restores the same rule instead of adding a second one');
    }

    public function testTheBackfillCommandFindsTheAppsCompaniesWithoutACompanyFlag(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $this->catalogueZohoPull();

        $this->setIntegration($app, IntegrationsEnum::ZOHO, ZohoHandler::class, $company, $user);
        $rule = $this->zohoPullRule($app, $company->getId());
        $rule->is_deleted = 1;
        $rule->saveQuietly();

        $this->artisan('kanvas:workflow-seed-default-rules', ['app_id' => $app->getId()])->assertSuccessful();

        $this->assertNotNull(
            $this->zohoPullRule($app, $company->getId()),
            'without --company_id the command must reach every company of the app through user_company_apps'
        );
    }

    private function catalogueZohoPull(): void
    {
        Action::factory()->create([
            'name' => 'Zoho Pull Lead',
            'model_name' => ZohoPullLeadActivity::class,
        ]);
    }

    private function zohoPullRule(Apps $app, int $companyId): ?Rule
    {
        return Rule::query()
            ->where('name', 'Zoho pull lead')
            ->where('rules_types_id', RuleType::getByName(WorkflowEnum::PULL->value)->getId())
            ->where('systems_modules_id', SystemModulesRepository::getByModelName(Lead::class, $app)->getId())
            ->where('apps_id', $app->getId())
            ->where('companies_id', $companyId)
            ->where('is_deleted', 0)
            ->first();
    }

    private function zohoIntegrationCompany(int $companyId): IntegrationsCompany
    {
        return IntegrationsCompany::query()
            ->where('companies_id', $companyId)
            ->where('integrations_id', Integrations::getByName(IntegrationsEnum::ZOHO->value)->getId())
            ->firstOrFail();
    }
}
