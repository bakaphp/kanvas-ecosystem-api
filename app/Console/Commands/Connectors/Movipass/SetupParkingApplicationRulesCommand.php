<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Movipass;

use App\Console\Commands\Concerns\WiresAppWorkflowRules;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationApprovalModeEnum as ApprovalMode;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationEmailEnum as ApplicationEmail;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationFieldEnum as CorporateField;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;
use Kanvas\Connectors\Movipass\Enums\MovipassRolesEnum;
use Kanvas\Connectors\Movipass\Enums\ParkingApplicationFieldEnum;
use Kanvas\Connectors\Movipass\Workflows\Activities\AutoApproveCorporateLeadActivity;
use Kanvas\Connectors\Movipass\Workflows\Activities\PublishApprovedParkingActivity;
use Kanvas\Connectors\Movipass\Workflows\Activities\SetupApprovedCorporateCompanyActivity;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;

class SetupParkingApplicationRulesCommand extends Command
{
    use KanvasJobsTrait;
    use WiresAppWorkflowRules;

    protected $signature = 'kanvas:movipass-setup-parking-application-rules {app_id} {--receiver= : LeadReceiver id that takes parking applications}';

    protected $description = 'Wire the workflow rules (and optionally the receiver) for parking applications on an app';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $leadModule = SystemModulesRepository::getByModelName(Lead::class, $app);

        $this->wireAppRule(
            $app,
            $leadModule->getId(),
            WorkflowEnum::CREATED->value,
            'Corporate applications: triage on lead created',
            [AutoApproveCorporateLeadActivity::class],
        );

        $this->wireAppRule(
            $app,
            $leadModule->getId(),
            WorkflowEnum::CORPORATE_APPLICATION_APPROVED->value,
            'Corporate applications: provision company and publish parking on approval',
            [SetupApprovedCorporateCompanyActivity::class, PublishApprovedParkingActivity::class],
        );

        if ($this->option('receiver')) {
            $this->wireReceiver($app, (int) $this->option('receiver'));
        }

        return self::SUCCESS;
    }

    private function wireReceiver(Apps $app, int $receiverId): void
    {
        $receiver = LeadReceiver::getById($receiverId, $app);

        $defaults = [
            ApprovalMode::RECEIVER_KEY => ApprovalMode::MANUAL->value,
            CorporateField::RECEIVER_INVITE_ROLE_KEY => MovipassRolesEnum::PARKING_MANAGER->value,
            ApplicationEmail::WELCOME->receiverKey() => 'parking-welcome',
            ApplicationEmail::NEEDS_REVIEW->receiverKey() => 'parking-needs-review',
            ApplicationEmail::REJECTED->receiverKey() => 'parking-rejected',
        ] + ParkingApplicationFieldEnum::receiverLists();

        foreach ($defaults as $key => $value) {
            if (empty($receiver->get($key))) {
                $receiver->set($key, $value);
            }
        }

        $app->set(ConfigurationEnum::PARKING_RECEIVER_ID->value, $receiver->getId());

        $this->info("Receiver #{$receiver->getId()} ({$receiver->name}) takes parking applications, approval_mode=" . $receiver->get(ApprovalMode::RECEIVER_KEY));
    }
}
