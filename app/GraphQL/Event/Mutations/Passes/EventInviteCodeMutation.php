<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Passes;

use App\GraphQL\Concerns\ResolvesActingContext;
use Baka\Support\Str;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Passes\Jobs\GenerateEventInviteCodesJob;
use Kanvas\Exceptions\ValidationException;

class EventInviteCodeMutation
{
    use ResolvesActingContext;

    private const int MAX_QUANTITY = 1000;
    private const int CODES_PER_JOB = 100;
    private const string PREFIX_PATTERN = '/^[A-Z0-9]{2,10}$/';

    public function generate(mixed $root, array $request): array
    {
        $ctx = $this->actingContext();
        $input = $request['input'];
        $quantity = (int) $input['quantity'];

        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw new ValidationException('quantity must be between 1 and ' . self::MAX_QUANTITY . '.');
        }

        $prefix = $this->normalizePrefix($input['prefix'] ?? null);
        $uses = (int) ($input['uses'] ?? 1);

        $event = Event::getByIdFromCompanyApp((int) $input['event_id'], $ctx->company, $ctx->app);

        $days = EventVersion::query()
            ->fromApp($ctx->app)
            ->fromCompany($ctx->company)
            ->daysOfEvent($event->getId())
            ->get();

        if ($uses < 1 || $uses > $days->count()) {
            throw new ValidationException('uses must be between 1 and the number of days of the event (' . $days->count() . ').');
        }

        $eventVersion = $days->first(fn (EventVersion $day) => $day->end_at === null || ! $day->end_at->isPast());

        if ($eventVersion === null) {
            throw new ValidationException('Cannot issue invite codes for an event whose days have all finished.');
        }

        for ($remaining = $quantity; $remaining > 0; $remaining -= self::CODES_PER_JOB) {
            GenerateEventInviteCodesJob::dispatch(
                $ctx->app,
                $ctx->user,
                $eventVersion,
                min($remaining, self::CODES_PER_JOB),
                $prefix,
                $uses
            );
        }

        return [
            'queued' => $quantity,
            'message' => 'Invite code generation queued.',
        ];
    }

    private function normalizePrefix(?string $prefix): ?string
    {
        $prefix = Str::trimToNull($prefix === null ? null : strtoupper($prefix));

        if ($prefix !== null && ! preg_match(self::PREFIX_PATTERN, $prefix)) {
            throw new ValidationException('prefix must be 2 to 10 characters, letters and digits only.');
        }

        return $prefix;
    }
}
