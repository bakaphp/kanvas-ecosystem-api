<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Actions;

use Illuminate\Support\Facades\Http;
use Kanvas\Communication\Messages\Actions\StoreInboundFileAction;
use Kanvas\Connectors\Twilio\Client;
use Kanvas\Social\Messages\Models\Message;

class DownloadMessageFileAction
{
    public function __construct(
        protected Message $message,
        protected string $fileUrl,
        protected string $type
    ) {
    }

    public function execute(): array
    {
        [$sid, $token] = Client::getKeysFromApp($this->message->app);

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
