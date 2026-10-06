<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\PullRoadsideProviderCaseAction;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Throwable;

/**
 * The provider ships no webhooks, so a case that moves on their side only reaches us if we ask.
 * This job asks once, records the snapshot, and re-queues itself until our own case reaches a
 * terminal status — the terminal slugs are passed in rather than known here so the job does not
 * have to re-derive the order type's endings.
 *
 * Runs on the default queue on purpose: the existing workers already consume it, so this needs no
 * new queue service.
 */
final class PollRoadsideProviderCaseJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<int, string> $terminalStatusSlugs statuses after which polling stops
     */
    public function __construct(
        public readonly Apps $app,
        public readonly Model $entity,
        public readonly array $terminalStatusSlugs,
        public readonly int $remainingPolls = 120,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        if ($this->remainingPolls <= 0 || $this->hasReachedTerminalStatus()) {
            return;
        }

        try {
            $snapshot = new PullRoadsideProviderCaseAction($this->entity)->execute();
            $this->entity->set(CustomFieldEnum::ROADSIDE_PROVIDER_SNAPSHOT->value, $snapshot);
            $this->entity->set(CustomFieldEnum::ROADSIDE_PROVIDER_SYNC_ERROR->value, null);
        } catch (Throwable $exception) {
            // A provider outage must not end the poll loop — the case is still open on our side and
            // the next tick may well succeed. Record it and keep going.
            report($exception);
            $this->entity->set(CustomFieldEnum::ROADSIDE_PROVIDER_SYNC_ERROR->value, $exception->getMessage());
        }

        $this->rescheduleNextPoll();
    }

    private function hasReachedTerminalStatus(): bool
    {
        $this->entity->refresh();

        $status = $this->entity->orderStatus;

        return $status !== null && in_array((string) $status->slug, $this->terminalStatusSlugs, true);
    }

    private function rescheduleNextPoll(): void
    {
        if ($this->hasReachedTerminalStatus()) {
            return;
        }

        $interval = (int) ($this->app->get(ConfigurationEnum::ROADSIDE_PROVIDER_POLL_INTERVAL->value)
            ?? ConfigurationEnum::ROADSIDE_PROVIDER_DEFAULT_POLL_INTERVAL->value);

        self::dispatch(
            $this->app,
            $this->entity,
            $this->terminalStatusSlugs,
            $this->remainingPolls - 1,
        )->delay(now()->addSeconds(max($interval, 30)));
    }
}
