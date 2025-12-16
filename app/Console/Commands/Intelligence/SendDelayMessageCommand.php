<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Social\Messages\Models\Message;

class SendDelayMessageCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:intelligence:send-delay-message {app_id} {company_id}';

    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $company = Companies::getById((int) $this->argument('company_id'));
        $this->overwriteAppService($app);
        $minutedMessages = $company->get(CompanyConfigurationEnum::MESSAGE_MINUTES_INTERVAL->value) ?? 60;

        $messages = Message::where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_locked', true)
            ->whereRaw("DATE_ADD(created_at, INTERVAL {$minutedMessages} MINUTE) <= NOW()")
            ->cursor();

        foreach ($messages as $message) {
            $communicationChannel = $message->get('communicationChannel');
            $fromNumber = $message->get('from_number');
            $title = $message->get('title');
            new SendMessageToLeadAction($message->entity)->execute(
                $communicationChannel,
                $message->message,
                $fromNumber,
                $title,
            );
            $message->is_locked = false;
            $message->save();
        }
    }
}
