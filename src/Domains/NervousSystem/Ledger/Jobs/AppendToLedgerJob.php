<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Ledger\Enums\LedgerQueueEnum;

class AppendToLedgerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Apps $app,
        public readonly ?Companies $company,
        public readonly string $sourceDomain,
        public readonly string $eventType,
        public readonly EventStatusEnum $status,
        public readonly ?string $sourceEntityType,
        public readonly ?int $sourceEntityId,
        public readonly ?string $actorType,
        public readonly ?int $actorId,
        public readonly ?array $payload,
        public readonly int $payloadSchemaVersion,
        public readonly ?array $result,
        public readonly ?array $error,
        public readonly ?int $durationMs,
        public readonly ?string $correlationId,
        public readonly ?string $causationId,
        public readonly ?string $occurredAt,
    ) {
        $this->onQueue(LedgerQueueEnum::LEDGER->value);
    }

    public function handle(): void
    {
        $data = new EventData(
            app: $this->app,
            company: $this->company,
            sourceDomain: $this->sourceDomain,
            eventType: $this->eventType,
            status: $this->status,
            sourceEntityType: $this->sourceEntityType,
            sourceEntityId: $this->sourceEntityId,
            actorType: $this->actorType,
            actorId: $this->actorId,
            payload: $this->payload,
            payloadSchemaVersion: $this->payloadSchemaVersion,
            result: $this->result,
            error: $this->error,
            durationMs: $this->durationMs,
            correlationId: $this->correlationId,
            causationId: $this->causationId,
            occurredAt: $this->occurredAt !== null ? Carbon::parse($this->occurredAt) : null,
        );

        new AppendEventAction($data)->execute();
    }
}
