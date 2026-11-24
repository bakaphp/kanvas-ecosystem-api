<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Throwable;
use Twilio\Base\PhoneNumberCapabilities;
use Twilio\Rest\Client;

/**
 * One Twilio number, with the updates it received recorded instead of sent.
 */
class FakeTwilioNumbersClient extends Client
{
    public array $updates = [];
    public object $phone;
    public array $numbers = [];
    public ?object $messagingFake = null;
    public ?Throwable $updateFailure = null;

    public function __construct(?string $voiceUrl = null, ?string $smsUrl = null, bool $sms = true)
    {
        parent::__construct('ACfake', 'fake-token');

        $this->phone = (object) [
            'sid' => 'PNfake',
            'voiceUrl' => $voiceUrl,
            'smsUrl' => $smsUrl,
            'capabilities' => new PhoneNumberCapabilities([
                'sms' => $sms,
                'voice' => true,
            ]),
        ];
        $this->numbers = [$this->phone];
    }

    public function __get(string $name)
    {
        if ($name === 'messaging') {
            return $this->messagingFake;
        }

        $numbers = $this->numbers;

        return new class ($numbers) {
            public function __construct(private array $numbers)
            {
            }

            public function read(array $options, int $limit): array
            {
                return $this->numbers;
            }
        };
    }

    public function __call(string $name, array $arguments)
    {
        $client = $this;

        return new class ($client) {
            public function __construct(private FakeTwilioNumbersClient $client)
            {
            }

            public function update(array $values): void
            {
                if ($this->client->updateFailure !== null) {
                    throw $this->client->updateFailure;
                }

                $this->client->updates[] = $values;
            }
        };
    }
}
