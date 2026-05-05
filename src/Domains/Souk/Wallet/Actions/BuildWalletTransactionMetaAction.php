<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet\Actions;

use Kanvas\Souk\Wallet\Enums\TransactionSourceEnum;

class BuildWalletTransactionMetaAction
{
    public function __construct(
        protected readonly ?TransactionSourceEnum $source = null,
        protected readonly ?string $idempotencyKey = null,
        protected readonly ?int $actorUserId = null,
        protected readonly ?string $externalReference = null,
        protected readonly ?string $reason = null,
        protected readonly array $additional = [],
    ) {
    }

    public function execute(): array
    {
        $idempotencyKey = $this->resolveIdempotencyKey();

        $audit = [
            'source' => $this->source?->value,
            'idempotency_key' => $idempotencyKey,
            'actor_user_id' => $this->actorUserId,
            'external_reference' => $this->externalReference,
            'reason' => $this->reason,
        ];

        return array_merge($audit, $this->additional);
    }

    private function resolveIdempotencyKey(): ?string
    {
        if ($this->idempotencyKey !== null) {
            return $this->idempotencyKey;
        }

        if ($this->source !== null && $this->externalReference !== null) {
            return hash(
                'sha256',
                "{$this->source->value}:{$this->externalReference}:{$this->actorUserId}"
            );
        }

        return null;
    }
}
