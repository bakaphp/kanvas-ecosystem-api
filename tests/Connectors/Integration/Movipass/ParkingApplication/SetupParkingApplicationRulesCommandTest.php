<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass\ParkingApplication;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationApprovalModeEnum;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;
use Kanvas\Connectors\Movipass\Workflows\Activities\AutoApproveCorporateLeadActivity;
use Kanvas\Connectors\Movipass\Workflows\Activities\PublishApprovedParkingActivity;
use Kanvas\Connectors\Movipass\Workflows\Activities\SetupApprovedCorporateCompanyActivity;
use Kanvas\Guild\Leads\Actions\CreateLeadReceiverAction;
use Kanvas\Guild\Leads\DataTransferObject\LeadReceiver as LeadReceiverData;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Tests\TestCase;

final class SetupParkingApplicationRulesCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'workflow'];

    private const string COMMAND = 'kanvas:movipass-setup-parking-application-rules';

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([AutoApproveCorporateLeadActivity::class, SetupApprovedCorporateCompanyActivity::class, PublishApprovedParkingActivity::class] as $activity) {
            Action::firstOrCreate(['model_name' => $activity], ['name' => class_basename($activity)]);
        }
    }

    public function testWiresBothLeadRulesWithTheirActivitiesAndIsIdempotent(): void
    {
        $app = app(Apps::class);

        $this->artisan(self::COMMAND, ['app_id' => $app->getId()])->assertSuccessful();
        $this->artisan(self::COMMAND, ['app_id' => $app->getId()])->assertSuccessful();

        $created = $this->ruleFor($app, WorkflowEnum::CREATED->value, 'Corporate applications: triage on lead created');
        $approved = $this->ruleFor(
            $app,
            WorkflowEnum::CORPORATE_APPLICATION_APPROVED->value,
            'Corporate applications: provision company and publish parking on approval'
        );

        $this->assertSame([AutoApproveCorporateLeadActivity::class], $this->activitiesOf($created));
        $this->assertSame(
            [SetupApprovedCorporateCompanyActivity::class, PublishApprovedParkingActivity::class],
            $this->activitiesOf($approved)
        );
        $this->assertSame(1, Rule::where('name', $created->name)->where('apps_id', $app->getId())->count());
    }

    public function testStampsTheReceiverAsManualApprovalAndPointsTheAppAtIt(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $receiver = new CreateLeadReceiverAction(new LeadReceiverData(
            app: $app,
            branch: $user->getCurrentBranch(),
            user: $user,
            agent: $user,
            name: 'parkingApplication',
            source: 'go-parking-web',
            isDefault: false,
        ))->execute();

        $this->artisan(self::COMMAND, ['app_id' => $app->getId(), '--receiver' => $receiver->getId()])->assertSuccessful();

        $this->assertSame(
            CorporateApplicationApprovalModeEnum::MANUAL->value,
            LeadReceiver::findOrFail($receiver->getId())->get(CorporateApplicationApprovalModeEnum::RECEIVER_KEY)
        );
        $this->assertSame($receiver->getId(), (int) $app->get(ConfigurationEnum::PARKING_RECEIVER_ID->value));

        $app->del(ConfigurationEnum::PARKING_RECEIVER_ID->value);
    }

    private function ruleFor(Apps $app, string $event, string $name): Rule
    {
        $leadModule = SystemModulesRepository::getByModelName(Lead::class, $app);

        return Rule::query()
            ->where('name', $name)
            ->where('apps_id', $app->getId())
            ->where('systems_modules_id', $leadModule->getId())
            ->whereHas('type', fn ($query) => $query->where('name', $event))
            ->where('is_deleted', 0)
            ->firstOrFail();
    }

    /**
     * @return list<class-string>
     */
    private function activitiesOf(Rule $rule): array
    {
        return $rule->workflowActivities()
            ->with('activity.action')
            ->get()
            ->map(fn ($ruleAction) => $ruleAction->activity->action->model_name)
            ->all();
    }
}
