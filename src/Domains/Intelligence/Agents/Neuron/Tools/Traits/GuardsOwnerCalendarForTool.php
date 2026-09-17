<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Kanvas\Event\Events\Enums\ConfigurationEnum;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Repositories\EventScheduleRepository;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Enums\ToolOutcomeEnum;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Users\Models\Users;

/**
 * get_user_availability is only advice: the LLM can skip it, and two conversations can pick the
 * same slot seconds apart. Serialising writes per owner makes the overlap re-check right before the
 * write the one that counts.
 */
trait GuardsOwnerCalendarForTool
{
    use ReportsToolOutcome;

    /**
     * @param callable(): array<string, mixed> $write
     * @return array<string, mixed>
     */
    protected function writeIfOwnerIsFree(
        Lead $lead,
        Users $owner,
        Carbon $start,
        Carbon $end,
        callable $write,
        ?int $excludeEventId = null,
    ): array {
        try {
            return Cache::lock('event-owner-calendar:' . $owner->getId(), 30)->block(
                10,
                function () use ($lead, $owner, $start, $end, $write, $excludeEventId): array {
                    $conflicts = new EventScheduleRepository()->getOverlappingForUser(
                        app: $lead->app,
                        company: $lead->company,
                        user: $owner,
                        start: $start,
                        end: $end,
                        excludeEventId: $excludeEventId,
                    );

                    if ($conflicts->isEmpty()) {
                        return $write();
                    }

                    // Only the times: the overlapping event belongs to some other prospect.
                    return $this->withOutcome(ToolOutcomeEnum::INVALID_ARGS, [
                        'status' => 'error',
                        'message' => 'That time overlaps an appointment already on the owner\'s calendar. '
                            . 'Nothing was booked. Call get_user_availability and offer the prospect one of the free slots.',
                        'conflicts' => $conflicts->map(fn (object $busy): array => [
                            'start' => $busy->start->toIso8601String(),
                            'end' => $busy->end->toIso8601String(),
                        ])->all(),
                    ]);
                }
            );
        } catch (LockTimeoutException) {
            return $this->withOutcome(ToolOutcomeEnum::TIMEOUT, [
                'status' => 'error',
                'message' => 'The owner\'s calendar is being updated by another booking right now. Nothing was booked. Try again.',
            ]);
        }
    }

    /**
     * The Event domain emails the lead on its own, so an agent that also sends a confirmation hands
     * the prospect two emails about the same appointment.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function appointmentSaved(array $payload, EventVersion $version): array
    {
        $leadNotified = ConfigurationEnum::emailsEnabled($version->app) && $version->participants()->exists();

        return $this->withOutcome(
            ToolOutcomeEnum::OK,
            [...$payload, 'lead_notified' => $leadNotified],
            $leadNotified
                ? 'The lead was already emailed the appointment details. Do not send a separate confirmation email; confirming in this conversation is fine.'
                : 'The lead was NOT emailed about this. Confirm the details with them in this conversation.'
        );
    }
}
