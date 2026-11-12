<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Email\Providers\Mailgun\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Client;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\DataTransferObject\EmailValidationResult;

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
