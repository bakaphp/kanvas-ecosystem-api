<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Gmail\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Google\Service\Gmail as GmailService;
use Kanvas\Connectors\Gmail\Support\GmailMessageParser;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Users\Models\Users;

/** Downloads one Gmail attachment and stores it as a real Kanvas Filesystem entry, same as any other uploaded document. */
class DownloadAttachmentAction extends AbstractGmailAction
{
    public function __construct(
        AppInterface $app,
        protected CompanyInterface $company,
        protected Users $user,
        protected string $messageId,
        protected string $attachmentId,
        protected string $filename,
        ?GmailService $service = null,
        protected ?FilesystemServices $filesystemServices = null,
    ) {
        parent::__construct($app, $service);
    }

    /**
     * @return array{filesystem_id: int, filename: string, url: string, size: int}
     */
    public function execute(): array
    {
        $body = $this->service()->users_messages_attachments->get('me', $this->messageId, $this->attachmentId);
        $rawBytes = GmailMessageParser::decodeBase64Url((string) $body->getData());

        $filesystem = $this->filesystemServices()->createFileSystemFromBase64(
            base64_encode($rawBytes),
            $this->filename,
            $this->user,
        );

        return [
            'filesystem_id' => $filesystem->getId(),
            'filename' => $filesystem->name,
            'url' => $filesystem->url,
            'size' => (int) $filesystem->size,
        ];
    }

    private function filesystemServices(): FilesystemServices
    {
        return $this->filesystemServices ??= new FilesystemServices($this->app, $this->company);
    }
}
