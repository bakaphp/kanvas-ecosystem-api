<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\Mailgun\Client;
use Kanvas\Connectors\Mailgun\DataTransferObject\EmailValidationResult;

class EmailValidationService
{
    protected Client $client;

    public function __construct(AppInterface $app)
    {
        $this->client = new Client($app);
    }

    public function validate(string $email): EmailValidationResult
    {
        return EmailValidationResult::fromApiResponse($this->client->validateAddress($email));
    }
}
