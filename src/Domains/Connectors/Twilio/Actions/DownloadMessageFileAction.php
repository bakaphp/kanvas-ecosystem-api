<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Kanvas\Connectors\Twilio\Client;
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
        [$sid, $token] = Client::getKeysFromApp($this->message->app);

        $response = Http::withBasicAuth($sid, $token)
            ->timeout(30)
            ->throw()
            ->get($this->fileUrl);

        $filename = uniqid('twilio_') . '.' . $this->extension;
        $tempPath = sys_get_temp_dir() . '/' . $filename;
        file_put_contents($tempPath, $response->body());

        $agentUser = $this->message->company->get('ai-agent-user-id');
        if ($agentUser !== null) {
            $user = Users::getById($agentUser);
        } else {
            $user = Users::getById($this->message->user_id);
        }

        try {
            $uploadedFile = new UploadedFile(
                $tempPath,
                $filename,
                $this->type,
                null,
                true
            );

            $filesystem = $this->filesystemService->upload($uploadedFile, $user);
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }

        return [
            'media' => $filesystem,
            'type' => $this->type,
        ];
    }
}
