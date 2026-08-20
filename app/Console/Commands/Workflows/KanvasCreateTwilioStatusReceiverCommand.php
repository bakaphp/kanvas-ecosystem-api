<?php

declare(strict_types=1);

namespace App\Console\Commands\Workflows;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Webhooks\ProcessTwilioMessageStatusWebhookJob;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;

class KanvasCreateTwilioStatusReceiverCommand extends Command
{
    protected $signature = 'kanvas:create-twilio-status-receiver
        {id : User ID that will own and process the receiver calls}
        {app : App ID where the receiver will be created}
        {companies : Company ID where the receiver will be created}';

    protected $description = 'Create the receiver used by Twilio message status callbacks';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app'));
        $company = Companies::getById((int) $this->argument('companies'));
        $user = UsersRepository::getUserOfCompanyById($company, (int) $this->argument('id'));

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => ProcessTwilioMessageStatusWebhookJob::class],
            ['name' => 'ProcessTwilioMessageStatusWebhookJob'],
        );

        $receiver = ReceiverWebhook::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('action_id', $action->getId())
            ->where('is_deleted', false)
            ->first();

        if ($receiver === null) {
            $receiver = ReceiverWebhook::create([
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'users_id' => $user->getId(),
                'action_id' => $action->getId(),
                'name' => 'Twilio Message Status Callback',
                'description' => 'Receives Twilio delivery status callbacks for outbound messages.',
                'configuration' => [],
                'is_active' => true,
                'is_deleted' => false,
            ]);

            $this->info('Twilio status receiver created.');
        } else {
            $this->info('Twilio status receiver already exists.');
        }

        $this->line('Receiver ID: ' . $receiver->getId());
        $this->line('Receiver UUID: ' . $receiver->uuid);
        $this->line('Webhook URL: ' . $receiver->getUrl());

        return self::SUCCESS;
    }
}
