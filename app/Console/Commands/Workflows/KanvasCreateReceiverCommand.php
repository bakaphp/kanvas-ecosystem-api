<?php

declare(strict_types=1);

namespace App\Console\Commands\Workflows;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;

use function Laravel\Prompts\select;

class KanvasCreateReceiverCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:create-receiver';

    public function handle(): void
    {
        $this->info('Creating Receiver...');
        $app = select(
            label: 'Select the app for the receiver: ',
            options: Apps::pluck('name', 'id'),
        );
        $action = select(
            label: 'Select the action for the receiver: ',
            options: WorkflowAction::pluck('name', 'id'),
        );
        $userId = $this->ask('Enter the user ID for the receiver: ');
        $companyId = $this->ask('Enter the company ID for the receiver: ');
        $name = $this->ask('Enter the name for the receiver: ');
        $description = $this->ask('Enter the description for the receiver: ');
        $company = Companies::getById($companyId);
        $user = UsersRepository::getUserOfCompanyById($company, (int)$userId);

        $receiver = ReceiverWebhook::create([
            'apps_id' => $app,
            'action_id' => $action,
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'name' => $name,
            'description' => $description,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->info('Receiver created successfully!');
        $url = config('app.url') . '/receiver/' . $receiver->uuid;
        $this->info('Webhook URL: ' . $url);
    }
}
