<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Actions;

use Illuminate\Http\UploadedFile;
use Kanvas\Connectors\Slack\Client;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Social\Messages\Models\Message;

/**
 * Download one Slack file attachment (with the bot token) and re-upload it into Kanvas Filesystem,
 * attached to the message — mirrors Twilio's DownloadMessageFileAction. Slack's url_private can't be
 * passed through to the agent's URL-fetcher because it needs an Authorization header, so the bytes
 * have to be pulled here and re-hosted on a Kanvas-owned URL.
 */
class DownloadMessageFileAction
{
    private readonly FilesystemServices $filesystemService;

    /**
     * @param array<string, mixed> $file A Slack file object from the event payload.
     */
    public function __construct(
        private readonly Message $message,
        private readonly Client $client,
        private readonly array $file,
    ) {
        $this->filesystemService = new FilesystemServices(
            $this->message->app,
            $this->message->company ?? null,
        );
    }

    public function execute(): Filesystem
    {
        // url_private_download forces a raw download; url_private is the inline-view variant. Either
        // needs the bot token, so both go through the Client's authed fetch.
        $url = (string) ($this->file['url_private_download'] ?? $this->file['url_private'] ?? '');

        if ($url === '') {
            throw new ValidationException('Slack file has no downloadable URL');
        }

        $bytes = $this->client->downloadFile($url);

        $filename = $this->filename();
        $tempPath = sys_get_temp_dir() . '/' . $filename;
        file_put_contents($tempPath, $bytes);

        // Attribute the upload to the company's AI user when present (mirrors Twilio), else the author.
        $user = $this->message->company?->getAiAgentUser() ?? $this->message->user;

        try {
            $uploadedFile = new UploadedFile(
                $tempPath,
                $filename,
                (string) ($this->file['mimetype'] ?? 'application/octet-stream'),
                null,
                true,
            );

            $filesystem = $this->filesystemService->upload($uploadedFile, $user);
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }

        $this->message->addFile($filesystem, $filesystem->name);

        return $filesystem;
    }

    /**
     * Slack always sends `name` (the original filename, extension included). Fall back to id+filetype
     * for the rare payload that omits it, and prefix so concurrent downloads can't collide on temp path.
     */
    private function filename(): string
    {
        $name = trim((string) ($this->file['name'] ?? ''));

        if ($name === '') {
            $id = (string) ($this->file['id'] ?? 'file');
            $ext = (string) ($this->file['filetype'] ?? 'bin');
            $name = $id . '.' . $ext;
        }

        return uniqid('slack_') . '_' . $name;
    }
}
