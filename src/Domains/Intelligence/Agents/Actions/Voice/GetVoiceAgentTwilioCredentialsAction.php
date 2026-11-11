<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Voice;

use Kanvas\Connectors\Twilio\Client as TwilioClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * The Twilio credentials of a voice agent's company, for the voice runtime to place, stream and hang
 * up that company's calls with its own account. Only voice agents get an answer, and every read is
 * logged (never the values), because these are the company's live Twilio keys.
 */
class GetVoiceAgentTwilioCredentialsAction
{
    public function __construct(private readonly Agent $agent)
    {
    }

    /**
     * @return array{account_sid: string, auth_token: string}
     */
    public function execute(): array
    {
        if (empty($this->agent->voice_config)) {
            throw new ValidationException('This agent has no voice configuration.');
        }

        $company = $this->agent->company;
        if ($company === null) {
            throw new ValidationException('This agent has no company to read Twilio credentials from.');
        }

        [$accountSid, $authToken] = TwilioClient::getKeysFromCompany($company);

        logger()->info("voice runtime read the Twilio credentials of company {$this->agent->companies_id} for agent {$this->agent->uuid}");

        return ['account_sid' => $accountSid, 'auth_token' => $authToken];
    }
}
