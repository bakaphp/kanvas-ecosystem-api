<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Services;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Throwable;

/**
 * Hangs an inbound email's attachments off whatever entity the receiver files it against — a Lead on
 * the shared inbox, a Message on an agent mailbox.
 *
 * ProcessWebhookAttemptAction already uploaded them into Filesystem and left the ids at
 * `payload.uploaded_files`; the multipart request is gone by the time any job runs, so this is the
 * last chance to keep them.
 */
class MailgunAttachmentService
{
    public function __construct(
        private readonly ReceiverWebhookCall $webhookRequest,
    ) {
    }

    /**
     * @return array<int, string> names of the files attached
     */
    public function attachTo(Model $entity): array
    {
        $payload = $this->webhookRequest->payload;
        $files = is_array($payload) ? ($payload['uploaded_files'] ?? null) : null;

        if (! is_array($files)) {
            return [];
        }

        $inlineFields = new MailgunPayloadService($payload)->inlineAttachmentFields();
        $attached = [];

        foreach ($files as $file) {
            if (! is_array($file) || in_array($file['field'] ?? '', $inlineFields, true)) {
                continue;
            }

            $filesystemId = (int) ($file['filesystem_id'] ?? 0);
            $name = is_string($file['name'] ?? null) && $file['name'] !== '' ? $file['name'] : 'attachment';

            if ($filesystemId > 0 && $this->attach($entity, $filesystemId, $name)) {
                $attached[] = $name;
            }
        }

        return $attached;
    }

    private function attach(Model $entity, int $filesystemId, string $name): bool
    {
        try {
            /** @var Filesystem $filesystem */
            $filesystem = Filesystem::getById($filesystemId, $entity->app);
            $entity->addFile($filesystem, $name);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
