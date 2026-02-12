<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;

class SendUnrespondedAgentMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use KanvasJobsTrait;

    public function __construct(
        protected Channel $channel,
        protected Message $message,
        protected Agent $agent,
        protected Apps $app,
        protected array $params = [],
        protected string $actionClass = '',
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $lead = $this->message->entity();
        $cacheKey = "unresponded_agent_message:{$lead->getId()}:{$this->channel->getId()}";

        if (! Cache::has($cacheKey)) {
            return;
        }

        $aiMode = $lead->get('ai_mode');
        if ($aiMode !== IntelligenceModeEnum::SUPPORT->value) {
            Cache::forget($cacheKey);

            return;
        }

        Cache::forget($cacheKey);

        try {
            $action = new $this->actionClass(
                $this->channel,
                $this->message,
                $this->agent,
                null
            );

            $action->execute($this->params);
        } catch (Exception $e) {
            throw $e;
        }
    }
}
