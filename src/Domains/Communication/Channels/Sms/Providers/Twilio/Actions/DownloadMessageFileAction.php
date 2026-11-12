<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Sms\Providers\Twilio\Actions;

use Illuminate\Support\Facades\Http;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Client;
use Kanvas\Communication\Messages\Actions\StoreInboundFileAction;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Social\Messages\Models\Message;

/**
 * Twilio media sits behind the account's own basic auth: the same credentials that signed the
 * webhook, the company's first and the app's otherwise.
 */
class DownloadMessageFileAction
{
    public function __construct(
        protected Message $message,
        protected string $fileUrl,
        protected string $type
    ) {
    }

    /**
     * @return array{media: Filesystem, type: string}
     */
    public function execute(): array
    {
        [$sid, $token] = Client::getKeysFromCompanyOrApp($this->message->company, $this->message->app);

        $response = Http::withBasicAuth($sid, $token)
            ->timeout(30)
            ->throw()
            ->get($this->fileUrl);

        return [
            'media' => new StoreInboundFileAction(
                $this->message,
                $response->body(),
                $this->type,
                'twilio'
            )->execute(),
            'type' => $this->type,
        ];
    }
}
