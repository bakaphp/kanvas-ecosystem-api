<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\SalesAssist;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Enums\EmailTemplatesEnum;
use Kanvas\Guild\Leads\Enums\LeadNotificationModeEnum;
use Kanvas\Guild\Leads\Enums\LeadNotificationUserModeEnum;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Guild\Leads\Models\LeadRotation;
use Kanvas\Users\Models\Users;

class SetupReceiversCommand extends Command
{
    protected $signature = 'kanvas:sa-setup-receivers {app_id} {company_id} {userId} {rotationId?} {--receivers= : Comma-separated list of receiver names (optional)}';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $company = Companies::getById((int) $this->argument('company_id'));
        $user = Users::getById((int) $this->argument('userId'));
        $rotationId = (int) $this->argument('rotationId');

        // Get receivers from option or use defaults
        $receiversOption = $this->option('receivers');
        $receivers = $receiversOption
            ? array_map('trim', explode(',', $receiversOption))
            : null;

        if (! $rotationId) {
            $leadRotation = LeadRotation::create([
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'name' => 'Lead Rotation',
                'hits' => 1,
                'leads_rotations_email' => '',
                'config' => [
                    'email_template' => EmailTemplatesEnum::LEAD_COMPANY_EMAIL->value,
                    'notification_mode' => LeadNotificationModeEnum::NOTIFY_AGENTS->value,
                    'notification_user_mode' => LeadNotificationUserModeEnum::NOTIFY_ROTATION_USERS->value,
                ]
            ]);

            $rotationId = $leadRotation->getId();
        }

        $this->setDefaultReceivers($app, $company, $user, $rotationId, $receivers);

        $this->info('Lead receivers processed successfully.');
    }

    public function setDefaultReceivers(Apps $app, Companies $company, UserInterface $user, int $rotationId, ?array $customReceivers = null): void
    {
        $defaultReceivers = [
            'contact',
            'finance',
            'service',
            'tradeIn',
            'lead',
            'piezas',
            'carPage',
            'offers',
        ];

        // Use custom receivers if provided, otherwise use defaults
        $receivers = $customReceivers ?? $defaultReceivers;

        $storedReceivers = [];

        foreach ($receivers as $receiverName) {
            $receiver = LeadReceiver::firstOrCreate([
                'companies_branches_id' => $company->defaultBranch()->firstOrFail()->getId(),
                'companies_id' => $company->getId(),
                'apps_id' => $app->getId(),
                'name' => $receiverName,
            ], [
                'users_id' => $user->getId(),
                'agents_id' => $user->getId(),
                'name' => $receiverName,
                'rotations_id' => $rotationId,
                'source_name' => $receiverName,
            ]);

            $storedReceivers[]  = "$receiver->uuid - $receiver->name";
        }

        foreach ($storedReceivers as $id) {
            $this->info($id);
        }
    }
}
