<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\Actions;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\ClaudeAgent\Client;
use Kanvas\Connectors\ClaudeAgent\Traits\ResolvesClaudeClient;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Bring the session's artifacts back into Kanvas.
 *
 * Without this the sandbox is a one-way door: the agent builds a real file, describes it perfectly,
 * and it dies with the session. Everything under `/mnt/session/outputs/` is downloaded and attached
 * to `$entity` — a Plan for async work, the chat Session for a conversational turn, i.e. wherever a
 * human is already looking.
 *
 * Best-effort by design: work that produced a good answer must not fail because an attachment could
 * not be stored.
 */
class PullSessionOutputsAction
{
    use ResolvesClaudeClient;

    public const string FILE_FIELD = 'claude_task_outputs';

    /**
     * @param Model|null $entity Anything using HasFilesystemTrait (Plan, Session, Message). Null —
     *        an ad-hoc turn with no session, a task whose plan is gone — means nowhere to attach to,
     *        which is a no-op rather than an error.
     */
    public function __construct(
        protected readonly ?Model $entity,
        protected readonly ?Users $owner,
        protected readonly string $sessionId,
        protected readonly ?Client $client = null,
    ) {
    }

    /**
     * @return list<string> Names of the files attached.
     */
    public function execute(): array
    {
        if ($this->entity === null || $this->owner === null) {
            return [];
        }

        $client = $this->claudeClient($this->entity->app, $this->entity->company);

        try {
            $files = $client->listSessionFiles($this->sessionId)['data'] ?? [];
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        $attached = [];

        foreach ($files as $file) {
            if (! is_array($file)) {
                continue;
            }

            $name = $this->attach($client, $file);

            if ($name !== null) {
                $attached[] = $name;
            }
        }

        return $attached;
    }

    /**
     * Each file is isolated: one that fails to download or store must not cost us the rest.
     *
     * @param array<string, mixed> $file
     */
    protected function attach(Client $client, array $file): ?string
    {
        $fileId = (string) ($file['id'] ?? '');
        $filename = trim((string) ($file['filename'] ?? ''));

        if ($fileId === '' || $filename === '') {
            return null;
        }

        try {
            $contents = $client->downloadFile($fileId);

            // The Filesystem service builds an UploadedFile from a temp path; base64 is its
            // from-memory entry point, so we hand it the bytes that way rather than writing to
            // disk ourselves and duplicating the temp-file handling.
            $filesystem = new FilesystemServices($this->entity->app)
                ->createFileSystemFromBase64(base64_encode($contents), basename($filename), $this->owner);

            if ($filesystem instanceof Filesystem) {
                $this->entity->addFile($filesystem, self::FILE_FIELD);

                return $filename;
            }
        } catch (Throwable $e) {
            report($e);
        }

        return null;
    }
}
