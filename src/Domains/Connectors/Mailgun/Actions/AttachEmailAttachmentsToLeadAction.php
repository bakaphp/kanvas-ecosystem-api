<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Actions;

use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Throwable;

class AttachEmailAttachmentsToLeadAction
{
    public function __construct(
        protected ReceiverWebhookCall $webhookRequest,
        protected Lead $lead,
    ) {
    }

    /**
     * @return array<int, string> names of the files attached to the lead
     */
    public function execute(): array
    {
        $files = $this->capturedFiles();

        if ($files === []) {
            return [];
        }

        $inlineFields = $this->inlineFields();
        $attached = [];

        foreach ($files as $file) {
            $field = is_string($file['field'] ?? null) ? $file['field'] : '';

            if ($field !== '' && in_array($field, $inlineFields, true)) {
                continue;
            }

            $filesystemId = (int) ($file['filesystem_id'] ?? 0);

            if ($filesystemId === 0) {
                continue;
            }

            $name = is_string($file['name'] ?? null) && $file['name'] !== '' ? $file['name'] : 'attachment';

            if ($this->attach($filesystemId, $name)) {
                $attached[] = $name;
            }
        }

        return $attached;
    }

    /**
     * @return array<int, array<array-key, mixed>>
     */
    protected function capturedFiles(): array
    {
        $payload = $this->webhookRequest->payload;
        $files = is_array($payload) ? ($payload['uploaded_files'] ?? null) : null;

        if (! is_array($files)) {
            return [];
        }

        return array_values(array_filter($files, 'is_array'));
    }

    /**
     * Multipart field names of inline attachments. `content-id-map` maps each content-id
     * referenced in the email body to its field name (e.g. `attachment-1`).
     *
     * @return array<int, string>
     */
    protected function inlineFields(): array
    {
        $payload = $this->webhookRequest->payload;
        $map = is_array($payload) ? ($payload['content-id-map'] ?? null) : null;

        if (is_string($map)) {
            $decoded = json_decode($map, true);
            $map = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($map)) {
            return [];
        }

        $fields = [];

        foreach ($map as $value) {
            if (is_string($value) && $value !== '') {
                $fields[] = $value;
            }
        }

        return $fields;
    }

    protected function attach(int $filesystemId, string $name): bool
    {
        try {
            /** @var Filesystem $filesystem */
            $filesystem = Filesystem::getById($filesystemId, $this->lead->app);
            $this->lead->addFile($filesystem, $name);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
