<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\VoiceBridge;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VoiceBridge\Enums\ConfigurationEnum;
use Kanvas\Connectors\VoiceBridge\Jobs\LeadVoiceFollowUpJob;
use Kanvas\Guild\Leads\Models\Lead;

class TriggerVoiceCallCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:voicebridge:trigger-call
                            {lead_id : Lead ID to trigger the voice call for}
                            {--app_id=1 : App ID}';

    protected $description = 'Trigger a VoiceBridge outbound call for a specific lead (sets transcript delay to 1 minute)';

    public function handle(): void
    {
        $lead = Lead::getById((int) $this->argument('lead_id'));
        $app = Apps::getById((int) $this->option('app_id'));

        $this->overwriteAppService($app);

        $company = $lead->company;
        $company->set(ConfigurationEnum::TRANSCRIPT_DELAY_MINUTES->value, 1);

        $this->info("Lead #{$lead->getId()} — {$lead->people->firstname} {$lead->people->lastname}");
        $this->info('Transcript delay set to 1 minute for company #' . $company->getId());

        LeadVoiceFollowUpJob::dispatchSync($lead, $app);

        $this->info('Voice call dispatched.');
    }
}
