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
    protected $signature = 'kanvas:sa-setup-receivers
        {--app= : App ID (required)}
        {--company= : Company ID (required)}
        {--user= : User ID (required)}
        {--rotation= : Rotation ID — must belong to the given app and company}
        {--receivers= : Comma-separated list of receiver names (optional)}';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $appId = (int) $this->option('app');
        $companyId = (int) $this->option('company');
        $userId = (int) $this->option('user');

        if (! $appId || ! $companyId || ! $userId) {
            $this->error('--app, --company and --user are required');

            return self::FAILURE;
        }

        $app = Apps::getById($appId);
        $company = Companies::getById($companyId);
        $user = Users::getById($userId);

        $rotationId = $this->option('rotation') ? (int) $this->option('rotation') : null;

        $defaultConfig = [
            'email_template' => EmailTemplatesEnum::LEAD_COMPANY_EMAIL->value,
            'notification_mode' => LeadNotificationModeEnum::NOTIFY_AGENTS->value,
            'notification_user_mode' => LeadNotificationUserModeEnum::NOTIFY_ROTATION_USERS->value,
        ];

        if ($rotationId) {
            $leadRotation = LeadRotation::findOrFail($rotationId);
            if ((int) $leadRotation->apps_id !== $app->getId() || (int) $leadRotation->companies_id !== $company->getId()) {
                $this->error("Rotation {$rotationId} does not belong to app {$app->getId()} / company {$company->getId()}");

                return self::FAILURE;
            }

            $leadRotation->config = array_merge((array) $leadRotation->config, $defaultConfig);
            $leadRotation->saveOrFail();
        } else {
            $leadRotation = LeadRotation::create([
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'name' => 'Lead Rotation',
                'hits' => 1,
                'leads_rotations_email' => '',
                'config' => $defaultConfig,
            ]);

            $rotationId = $leadRotation->getId();
        }

        $receiversOption = $this->option('receivers');
        $receivers = $receiversOption
            ? array_map('trim', explode(',', $receiversOption))
            : null;

        $this->setDefaultReceivers($app, $company, $user, $rotationId, $receivers);

        $this->info('Lead receivers processed successfully.');

        return self::SUCCESS;
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
