<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Queries\Agent;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\Voice\GetVoiceAgentTwilioCredentialsAction;
use Kanvas\Intelligence\Agents\Repositories\AgentsRepository;
use Kanvas\Intelligence\Agents\Services\VoiceRuntimeConfig;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;

/**
 * @guardByAppKey alone is not enough here: a cross-app key could read any company's Twilio keys.
 * The runtime also proves who it is with the token Kanvas calls it with.
 */
class VoiceAgentTwilioQuery
{
    public const string TOKEN_HEADER = 'X-Voice-Runtime-Token';

    /**
     * @param array{agent_uuid: string} $args
     *
     * @return array{account_sid: string, auth_token: string}
     */
    public function __invoke(mixed $root, array $args): array
    {
        if (! VoiceRuntimeConfig::acceptsToken(request()->header(self::TOKEN_HEADER))) {
            throw new AuthorizationException('Only the voice runtime can read Twilio credentials.');
        }

        $agent = AgentsRepository::getByUuidForVoiceRuntime((string) $args['agent_uuid'], app(Apps::class));

        return new GetVoiceAgentTwilioCredentialsAction($agent)->execute();
    }
}
