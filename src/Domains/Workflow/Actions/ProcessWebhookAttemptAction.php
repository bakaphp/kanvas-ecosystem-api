<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Actions;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;

use function Sentry\captureException;

use Throwable;

class ProcessWebhookAttemptAction
{
    public function __construct(
        protected ReceiverWebhook $receiver,
        protected Request $request,
    ) {
    }

    public function execute(): ReceiverWebhookCall
    {
        $payload = $this->request->input();

        $uploadedFiles = $this->captureUploadedFiles();
        if ($uploadedFiles !== []) {
            $payload['uploaded_files'] = $uploadedFiles;
        }

        return ReceiverWebhookCall::create([
            'receiver_webhooks_id' => $this->receiver->getId(),
            'url' => $this->request->fullUrl(),
            'headers' => $this->request->headers->all(),
            'payload' => $payload,
        ]);
    }

    protected function captureUploadedFiles(): array
    {
        if (! $this->fileCaptureEnabled() || ! $this->requestIsAuthentic()) {
            return [];
        }

        $files = $this->request->allFiles();

        if ($files === []) {
            return [];
        }

        try {
            $filesystemService = new FilesystemServices($this->receiver->app, $this->receiver->company);
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        $user = $this->receiver->user;
        $captured = [];

        foreach ($files as $field => $file) {
            foreach (is_array($file) ? $file : [$file] as $uploadedFile) {
                if (! $uploadedFile instanceof UploadedFile) {
                    continue;
                }

                try {
                    $filesystem = $filesystemService->upload($uploadedFile, $user);
                    $captured[] = [
                        'filesystem_id' => $filesystem->getId(),
                        'name' => $uploadedFile->getClientOriginalName(),
                        'field' => (string) $field,
                    ];
                } catch (Throwable $e) {
                    captureException($e);
                }
            }
        }

        return $captured;
    }

    protected function fileCaptureEnabled(): bool
    {
        return (bool) ($this->receiver->configuration['capture_files'] ?? false);
    }

    protected function requestIsAuthentic(): bool
    {
        $jobClass = $this->receiver->action?->model_name;

        if (! is_string($jobClass) || ! is_a($jobClass, ProcessWebhookJob::class, true)) {
            return true;
        }

        return $jobClass::authenticateRequest($this->request, $this->receiver);
    }
}
