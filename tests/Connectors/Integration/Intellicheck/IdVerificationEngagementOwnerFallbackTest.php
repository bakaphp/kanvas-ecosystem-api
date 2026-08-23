<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intellicheck;

use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStage;
use Kanvas\Apps\Actions\SyncEmailTemplateAction;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Intellicheck\Activities\IdVerificationReportActivity;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel;
use Kanvas\Users\Models\Users;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Regression for KANVAS-ECOSYSTEM-5YW: unassigned leads carry leads_owner_id = 0, so
 * $lead->owner is null and the Engagement DTO (non-nullable Users $user) blew up with a
 * TypeError on every ID verification for those leads.
 */
final class IdVerificationEngagementOwnerFallbackTest extends TestCase
{
    private function invokeCreateEngagement(Lead $lead, People $people): ?Engagement
    {
        $activity = new ReflectionClass(IdVerificationReportActivity::class)->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(IdVerificationReportActivity::class, 'createIdVerificationEngagement');

        return $method->invoke($activity, $lead, $people);
    }

    public function testFallsBackToLeadUserWhenLeadHasNoOwner(): void
    {
        $lead = $this->makeLead();
        $lead->leads_owner_id = 0;
        $lead->users_id = auth()->user()->getId();
        $lead->saveQuietly();
        $lead->refresh();

        $this->assertNull($lead->owner, 'precondition: the lead is unassigned');

        $engagement = $this->invokeCreateEngagement($lead, $this->makePerson($lead, usersId: 0));

        $this->assertNotNull($engagement);
        $this->assertSame($lead->users_id, $engagement->users_id);
    }

    public function testReturnsNullWhenNeitherOwnerNorUserResolves(): void
    {
        $lead = $this->makeLead();
        $lead->leads_owner_id = 0;
        $lead->users_id = 0;
        $lead->saveQuietly();
        $lead->refresh();

        $this->assertNull($this->invokeCreateEngagement($lead, $this->makePerson($lead, usersId: 0)));
    }

    /**
     * A stale users_id pointing at someone outside the app used to reach CreateEngagementAction,
     * which follows the lead as that user and threw `User doesn't belong to this app` from the
     * workflow instead of skipping the engagement.
     */
    public function testAUserOutsideTheAppIsNotUsedAsTheEngagementOwner(): void
    {
        $lead = $this->makeLead();
        $lead->leads_owner_id = 0;
        $lead->users_id = 0;
        $lead->saveQuietly();
        $lead->refresh();

        $outsider = Users::factory()->create();

        $this->assertNull($this->invokeCreateEngagement($lead, $this->makePerson($lead, $outsider->getId())));
    }

    private function makeLead(): Lead
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $lead->company->set('company_manager', []);

        new SyncEmailTemplateAction($app, $user)->execute(overWrite: false);

        $pipeline = Pipeline::firstOrCreate([
            'slug' => ConfigurationEnum::ID_VERIFICATION->value,
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
        ], [
            'users_id' => $user->getId(),
            'name' => 'ID Verification',
            'weight' => 0,
        ]);

        PipelineStage::firstOrCreate([
            'pipelines_id' => $pipeline->getId(),
            'slug' => 'submitted',
        ], [
            'name' => 'Submitted',
            'weight' => 1,
        ]);

        $action = Action::firstOrCreate([
            'slug' => ConfigurationEnum::ID_VERIFICATION->value,
        ], [
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'pipelines_id' => $pipeline->getId(),
            'name' => 'ID Verification',
        ]);

        $branch = $company->defaultBranch ?? $company->branch()->firstOrFail();

        CompanyAction::firstOrCreate([
            'actions_id' => $action->getId(),
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
        ], [
            'users_id' => $user->getId(),
            'companies_branches_id' => $branch->getId(),
            'pipelines_id' => $pipeline->getId(),
            'name' => 'ID Verification',
        ]);

        new CreateChannelAction(new Channel(
            apps: $app,
            companies: $company,
            users: $user,
            entity_id: $lead->getId(),
            entity_namespace: Lead::class,
            name: (string) $lead->uuid,
            slug: (string) $lead->uuid,
            description: (string) $lead->uuid,
        ))->execute();

        return $lead;
    }

    private function makePerson(Lead $lead, int $usersId): People
    {
        return People::factory()
            ->withAppId($lead->apps_id)
            ->withCompanyId($lead->companies_id)
            ->withUserId($usersId)
            ->create([
                'firstname' => 'Unassigned',
                'lastname' => 'Buyer',
            ]);
    }
}
