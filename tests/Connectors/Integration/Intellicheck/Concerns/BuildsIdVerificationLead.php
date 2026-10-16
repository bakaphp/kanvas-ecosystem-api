<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intellicheck\Concerns;

use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStage;
use Kanvas\Apps\Actions\SyncEmailTemplateAction;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Intellicheck\Actions\VerifyPeopleIdAction;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel;

/**
 * An ID-verification engagement needs a pipeline, a submitted stage, an action, a company action and
 * a channel before `CreateEngagementAction` will produce one — which is why every test in this folder
 * had grown its own copy of this.
 */
trait BuildsIdVerificationLead
{
    /**
     * `$assignOwner` false leaves `leads_owner_id = 0`, the unassigned-lead shape
     * `resolveEngagementUser()` has to fall through.
     */
    protected function makeLead(bool $assignOwner = true): Lead
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        if ($assignOwner) {
            $lead->leads_owner_id = $user->getId();
            $lead->users_id = $user->getId();
            $lead->saveQuietly();
            $lead->refresh();
        }

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

    protected function makePerson(Lead $lead, ?int $usersId = null): People
    {
        return People::factory()
            ->withAppId($lead->apps_id)
            ->withCompanyId($lead->companies_id)
            ->withUserId($usersId ?? auth()->user()->getId())
            ->create([
                'firstname' => 'Co',
                'lastname' => 'Buyer',
            ]);
    }

    protected function createEngagement(Lead $lead, People $people): ?Engagement
    {
        return new VerifyPeopleIdAction($people, $lead)->resolveEngagement(reuseExistingEngagement: true);
    }
}
