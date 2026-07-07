<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Actions\AnonymousAgentChatAction;

class PublicAgentChatMutation
{
    /**
     * @param array{input: array{agent: mixed, token: string, message: string}} $request
     *
     * @return array{token: string, reply: string, turns_remaining: int}
     */
    public function chat(mixed $root, array $request): array
    {
        $app = app(Apps::class);
        $input = $request['input'];

        $agent = Agent::getById((int) $input['agent'], $app);

        return new AnonymousAgentChatAction(
            app: $app,
            agent: $agent,
            token: (string) $input['token'],
            message: (string) $input['message'],
        )->execute();
    }
}
