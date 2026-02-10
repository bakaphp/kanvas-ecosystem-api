<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Actions;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;

class DownloadMessageFileAction
{
    protected FilesystemServices $filesystemService;
    protected string $extension;

    public function __construct(
        protected Message $message,
        protected string $fileUrl,
        protected string $type
    ) {
        $this->filesystemService = new FilesystemServices(
            $this->message->app,
            $this->message->company ?? null
        );

        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/3gpp' => '3gp',
            'audio/mpeg' => 'mp3',
            'audio/ogg' => 'ogg',
            'audio/amr' => 'amr',
        ];

        if (! isset($map[$this->type])) {
            throw new InvalidArgumentException("Unsupported media type: {$this->type}");
        }

        $this->extension = $map[$this->type];
    }

    public function execute(): array
    {
        $content = Http::get($this->fileUrl);
        $filename = uniqid() . '.' . $this->extension;

        $tempPath = 'temp/' . $filename;

        $agentUser = $this->message->app->get('kanvas_agent_user_id');
        if ($agentUser !== null) {
            $user = Users::getById($this->message->user_id);
        } else {
            $user = Users::getById($agentUser);
        }

        $filesystem = $this->filesystemService->uploadFileFromUrl($this->fileUrl, $user);

        return ['media' => $filesystem, 'type' => $this->type];
    }
}
