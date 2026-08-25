<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Yusen\Webhooks;

use Kanvas\Connectors\Yusen\Jobs\ProcessYusenInventoryBalanceJob;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

/**
 * Inbound endpoint for Yusen's Manhattan ILSNET `Item Balance` document.
 *
 * Yusen can deliver it either way and both are accepted:
 *   - multipart upload — the receiver pipeline stores it and leaves the id in
 *     `payload.uploaded_files`
 *   - raw `application/xml` body — kept verbatim in the webhook call's `raw_payload`
 *
 * The work itself is handed to ProcessYusenInventoryBalanceJob so this returns inside the
 * uploader's ack window regardless of catalog size.
 */
#[WorkflowAction(
    name: 'Yusen Inventory Balance Receiver',
    description: 'Receiver for Yusen Logistics\' Item Balance XML. Parses the 3PL\'s physical count and '
        . 'reports where it disagrees with Kanvas and with NetSuite. Read-only: it writes no stock, '
        . 'nothing is pushed back to Yusen, and no customer is contacted.',
    integration: IntegrationsEnum::YUSEN,
)]
class YusenInventoryBalanceWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public static function capturesFiles(): bool
    {
        return true;
    }

    #[Override]
    public function execute(): array
    {
        $upload = $this->resolveXmlUpload($this->webhookRequest->payload ?? []);
        $rawXml = $upload === null ? $this->resolveRawXml() : null;

        if ($upload === null && $rawXml === null) {
            return [
                'dispatched' => false,
                'reason' => 'no_xml_payload',
            ];
        }

        ProcessYusenInventoryBalanceJob::dispatch(
            $this->receiver->app,
            $this->receiver->company,
            $upload['id'] ?? null,
            $rawXml,
            $upload['name'] ?? null,
        );

        return [
            'dispatched' => true,
            'filesystem_id' => $upload['id'] ?? null,
            'source' => $upload !== null ? 'upload' : 'raw_body',
        ];
    }

    /**
     * The first captured `.xml` attachment, id and name together — an email can carry a signature
     * image or a cover PDF alongside the balance, and reporting one file's name against another
     * file's contents would make a mismatch impossible to trace back.
     *
     * @return array{id: int, name: string}|null
     */
    private function resolveXmlUpload(array $payload): ?array
    {
        $files = $payload['uploaded_files'] ?? [];

        if (! is_array($files)) {
            return null;
        }

        foreach ($files as $file) {
            if (! is_array($file) || ! isset($file['filesystem_id'])) {
                continue;
            }

            $name = (string) ($file['name'] ?? '');

            if (str_ends_with(strtolower($name), '.xml')) {
                return ['id' => (int) $file['filesystem_id'], 'name' => $name];
            }
        }

        return null;
    }

    private function resolveRawXml(): ?string
    {
        $raw = trim((string) ($this->webhookRequest->raw_payload ?? ''));

        return str_contains($raw, '<WMWROOT') ? $raw : null;
    }
}
