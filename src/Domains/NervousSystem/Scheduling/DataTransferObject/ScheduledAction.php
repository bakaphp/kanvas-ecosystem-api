<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Scheduling\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionTypeEnum;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

class ScheduledAction extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly Users $user,
        public readonly ScheduledActionTypeEnum $type,
        public readonly string $timezone,
        public readonly ?Carbon $runAt = null,
        public readonly ?Agent $agent = null,
        public readonly ?string $message = null,
        public readonly ?string $instruction = null,
        public readonly ?string $channel = null,
        public readonly ?string $sessionUuid = null,
        public readonly ?string $sourceEntityType = null,
        public readonly ?string $sourceEntityId = null,
        public readonly ?string $recurrenceCron = null,
        public readonly ?Carbon $recurrenceEndsAt = null,
        public readonly ?int $maxOccurrences = null,
    ) {
    }

    public function isRecurring(): bool
    {
        return $this->recurrenceCron !== null && $this->recurrenceCron !== '';
    }

    /**
     * The stored JSON payload for the row — the message for a reminder, the instruction for
     * an agent task.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return match ($this->type) {
            ScheduledActionTypeEnum::REMINDER => ['message' => (string) $this->message],
            ScheduledActionTypeEnum::AGENT_TASK => ['instruction' => (string) $this->instruction],
        };
    }
}
