<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Signals\Actions;

use Illuminate\Support\Facades\Cache;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\NervousSystem\Orchestrator\Signals\DataTransferObject\InboundSignal;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Throwable;

/**
 * One file per signal, attached to every message the signal is routed to. Handed around unexecuted so
 * the upload waits until a message is really about to be written: a duplicate or a signal nobody
 * reads stores nothing, and a webhook delivered twice reuses the first file through the cache.
 *
 * Null means "keep the text in the message": the kind is not stored as a file, or storage refused it.
 */
class StoreSignalFileAction
{
    private const int REUSE_HOURS = 24;

    private bool $attempted = false;
    private ?Filesystem $file = null;

    public function __construct(
        private readonly InboundSignal $signal,
        private readonly ReceiverWebhook $receiver
    ) {
    }

    public function execute(): ?Filesystem
    {
        if ($this->attempted) {
            return $this->file;
        }

        $this->attempted = true;

        if (! $this->signal->kind->isStoredAsFile()) {
            return null;
        }

        try {
            $this->file = $this->alreadyStored() ?? $this->store();
        } catch (Throwable $e) {
            report($e);
        }

        return $this->file;
    }

    private function alreadyStored(): ?Filesystem
    {
        $id = (int) Cache::get($this->cacheKey());
        if ($id <= 0) {
            return null;
        }

        return Filesystem::query()
            ->fromApp($this->receiver->app)
            ->fromCompany($this->receiver->company)
            ->notDeleted()
            ->where('id', $id)
            ->first();
    }

    private function store(): Filesystem
    {
        $file = new FilesystemServices($this->receiver->app, $this->receiver->company)->uploadContents(
            $this->signal->document(),
            $this->signal->fileName(),
            $this->receiver->user
        );

        Cache::put($this->cacheKey(), $file->getId(), now()->addHours(self::REUSE_HOURS));

        return $file;
    }

    private function cacheKey(): string
    {
        return sprintf(
            'signal-file:%d:%s',
            $this->receiver->getId(),
            sha1($this->signal->source->value . '|' . ($this->signal->externalId !== '' ? $this->signal->externalId : $this->signal->content))
        );
    }
}
