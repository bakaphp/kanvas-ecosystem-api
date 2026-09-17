<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Guild\Leads\Actions\SummarizeLeadConversationAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\ConfigurationEnum;

class SummarizeLeadConversationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;
    public int $uniqueFor = 600;

    public function __construct(
        public readonly Lead $lead,
    ) {
    }

    /**
     * Only closed leads of companies with AI enabled: an open lead's summary would go stale, and the
     * summary runs on the platform Gemini key.
     */
    public static function dispatchIfEligible(Lead $lead): void
    {
        if ($lead->hasOpenLeadStatus() || ! $lead->company->get(ConfigurationEnum::AI_ENABLE->value)) {
            return;
        }

        self::dispatch($lead);
    }

    public function uniqueId(): string
    {
        return (string) $this->lead->getId();
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->lead->app);

        new SummarizeLeadConversationAction($this->lead)->execute();
    }
}
