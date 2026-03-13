<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VoiceBridge\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VoiceBridge\Actions\InitVoiceSessionAction;
use Kanvas\Connectors\VoiceBridge\Actions\TriggerVoiceCallAction;
use Kanvas\Connectors\VoiceBridge\Enums\ConfigurationEnum as VoiceBridgeConfigurationEnum;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Throwable;

class LeadVoiceFollowUpJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        protected Lead $lead,
        protected Apps $app,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        if ($this->lead->get(LeadsConfigurationEnum::IS_ENGAGEMENT->value)) {
            return;
        }

        $phone = $this->lead->people->getCellPhones()->first()?->value
            ?? $this->lead->people->getAllPhones()->first()?->value;

        if (empty($phone)) {
            return;
        }

        if (empty($this->app->get(VoiceBridgeConfigurationEnum::API_KEY->value))) {
            return;
        }

        try {
            $agent = Agent::fromApp($this->app)
                ->fromCompany($this->lead->company)
                ->where('name', 'voiceOutreachAgent')
                ->firstOrFail();

            InitVoiceSessionAction::fromLead($this->lead, $agent)->execute();
            TriggerVoiceCallAction::fromLead($this->lead)->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
