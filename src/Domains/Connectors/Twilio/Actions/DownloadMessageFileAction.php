<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Actions;

use Baka\Http\Exceptions\SsrfException;
use Baka\Http\SafeUrl;
use Illuminate\Support\Facades\Http;
use Kanvas\Communication\Messages\Actions\StoreInboundFileAction;
use Kanvas\Connectors\Twilio\Client;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Social\Messages\Models\Message;

/**
 * Twilio media sits behind the account's own basic auth, so the request carries the account's
 * credentials: it only ever goes to Twilio.
 */
class DownloadMessageFileAction
{
    private const string TWILIO_HOST = '.twilio.com';

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
        $host = strtolower((string) parse_url($this->fileUrl, PHP_URL_HOST));

        if (parse_url($this->fileUrl, PHP_URL_SCHEME) !== 'https' || ! str_ends_with('.' . $host, self::TWILIO_HOST)) {
            throw new SsrfException('Twilio media is only downloaded from Twilio.');
        }

        SafeUrl::assertSafe($this->fileUrl);

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
