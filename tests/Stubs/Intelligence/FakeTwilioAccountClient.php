<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use RuntimeException;

/**
 * A Twilio account (status, type, balance) on top of the numbers FakeTwilioNumbersClient serves.
 * A null balance makes the balance lookup fail, as it does for subaccounts that can't read it.
 */
class FakeTwilioAccountClient extends FakeTwilioNumbersClient
{
    public const string NUMBER = '+15312334854';

    public function __construct(
        public string $status = 'active',
        public string $type = 'Full',
        public ?string $balance = '25.50',
        public string $currency = 'USD',
        ?string $voiceUrl = null,
    ) {
        parent::__construct($voiceUrl);

        $this->numbers = [
            (object) [
                'phoneNumber' => self::NUMBER,
                'friendlyName' => null,
                'sid' => 'PNfake',
                'voiceUrl' => $voiceUrl,
            ],
        ];
    }

    public function __get(string $name)
    {
        if ($name !== 'api') {
            return parent::__get($name);
        }

        $account = new class ($this) {
            public object $balance;

            public function __construct(private FakeTwilioAccountClient $client)
            {
                $this->balance = new class ($client) {
                    public function __construct(private FakeTwilioAccountClient $client)
                    {
                    }

                    public function fetch(): object
                    {
                        if ($this->client->balance === null) {
                            throw new RuntimeException('Balance unavailable');
                        }

                        return (object) ['balance' => $this->client->balance, 'currency' => $this->client->currency];
                    }
                };
            }

            public function fetch(): object
            {
                return (object) [
                    'friendlyName' => 'Sally Dealer',
                    'status' => $this->client->status,
                    'type' => $this->client->type,
                ];
            }
        };

        return (object) ['v2010' => (object) ['account' => $account]];
    }
}
