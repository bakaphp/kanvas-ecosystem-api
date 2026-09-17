<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Services;

use GraphQL\Deferred;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Guild\Leads\Jobs\CreateLeadsFromReceiverJob;
use Kanvas\Guild\Leads\Jobs\CreateLeadsFromReceiverWithConfirmationJob;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Workflow\Models\ReceiverWebhook;

class LeadReceiverWebhookService
{
    /** @var array<int, LeadReceiver> */
    private array $receivers = [];

    /** @var array<int, string> */
    private array $uuids = [];

    private bool $resolved = false;

    public function load(LeadReceiver $receiver): Deferred
    {
        $this->receivers[$receiver->getId()] = $receiver;

        return new Deferred(function () use ($receiver): ?string {
            if (! $this->resolved) {
                $this->resolve();
            }

            return $this->uuids[$receiver->getId()] ?? null;
        });
    }

    private function resolve(): void
    {
        $this->resolved = true;

        $byTenant = [];
        foreach ($this->receivers as $receiver) {
            $byTenant[$receiver->apps_id . ':' . $receiver->companies_id][] = $receiver->getId();
        }

        foreach ($byTenant as $tenant => $receiverIds) {
            [$appId, $companyId] = explode(':', $tenant);
            $this->uuids += $this->uuidByReceiverId((int) $appId, (int) $companyId, $receiverIds);
        }
    }

    /**
     * @param int[] $receiverIds
     * @return array<int, string>
     */
    private function uuidByReceiverId(int $appId, int $companyId, array $receiverIds): array
    {
        // Only lead-intake webhooks can take a form payload; active first, then newest wins.
        $webhooks = ReceiverWebhook::query()
            ->where('apps_id', $appId)
            ->where('companies_id', $companyId)
            ->where('is_deleted', false)
            ->whereHas(
                'action',
                fn (Builder $query) => $query->whereIn('model_name', [
                    CreateLeadsFromReceiverJob::class,
                    CreateLeadsFromReceiverWithConfirmationJob::class,
                ])
            )
            ->orderByDesc('is_active')
            ->orderByDesc('id')
            ->get(['id', 'uuid', 'configuration']);

        $wanted = array_flip($receiverIds);
        $uuids = [];

        // configuration->receiver_id is an unindexed JSON key stored as int or string: match in PHP.
        foreach ($webhooks as $webhook) {
            $receiverId = (int) ($webhook->configuration['receiver_id'] ?? 0);

            if (! isset($wanted[$receiverId]) || isset($uuids[$receiverId])) {
                continue;
            }

            $uuids[$receiverId] = $webhook->uuid;
        }

        return $uuids;
    }
}
