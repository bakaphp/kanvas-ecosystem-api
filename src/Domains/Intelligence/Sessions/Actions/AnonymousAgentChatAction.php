<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session as SessionData;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;

/**
 * Run one anonymous chat turn against a public-enabled customer-facing agent, inside the agent's
 * (demo) company. The session is keyed by a client token so the same visitor resumes across visits;
 * a per-token turn cap bounds the free LLM spend. Everything created here stays in the demo company.
 */
final class AnonymousAgentChatAction
{
    private const int DEFAULT_TURN_CAP = 50;
    private const string TURN_CAP_SETTING = 'public_chat_turn_cap';
    private const string TURN_COUNTER_KEY = 'anon_turns';
    private const string PUBLIC_CHAT_FLAG = 'public_chat_enabled';

    public function __construct(
        private readonly Apps $app,
        private readonly Agent $agent,
        private readonly string $token,
        private readonly string $message,
    ) {
    }

    /**
     * @return array{token: string, reply: string, turns_remaining: int}
     */
    public function execute(): array
    {
        if (! $this->agent->conversesWithCustomer()) {
            throw new ValidationException('This agent is not available for public chat.');
        }

        if (! (bool) $this->agent->get(self::PUBLIC_CHAT_FLAG)) {
            throw new ValidationException('Public chat is not enabled for this agent.');
        }

        $company = $this->agent->company;
        $guestUser = $company->getAiAgentUserOrFail();

        $session = $this->resolveSession($company, $guestUser);

        $turnCap = $this->resolveTurnCap();

        $content = (array) ($session->content ?: []);
        $turnsUsed = (int) ($content[self::TURN_COUNTER_KEY] ?? 0);
        if ($turnsUsed >= $turnCap) {
            throw new ValidationException('Demo limit reached — sign up to keep going.');
        }

        $reply = new AgentChatKernel(
            agent: $this->agent,
            session: $session,
            message: $this->message,
            user: $guestUser,
            persistConversation: true,
        )->execute();

        $this->incrementTurns($session, $turnsUsed);

        return [
            'token' => $this->token,
            'reply' => ChatHelper::extractTextFromResponse($reply),
            'turns_remaining' => max(0, $turnCap - ($turnsUsed + 1)),
        ];
    }

    /**
     * Demo turn cap is an app setting (public_chat_turn_cap) so it can be tuned per app without a
     * deploy; falls back to the default when unset or non-positive.
     */
    private function resolveTurnCap(): int
    {
        $configured = (int) $this->app->get(self::TURN_CAP_SETTING);

        return $configured > 0 ? $configured : self::DEFAULT_TURN_CAP;
    }

    /**
     * Anon sessions are keyed by the client token as the session uuid — CreateSessionAction derives
     * the uuid from the channel slug (identical for every no-channel session), so we create directly.
     */
    private function resolveSession(Companies $company, Users $guestUser): Session
    {
        $existing = Session::query()
            ->fromApp($this->app)
            ->where('uuid', $this->token)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $userPayload = ['name' => 'Guest', 'id' => $guestUser->getId(), 'email' => null];

        $content = new CreateContentSessionAction(
            new SessionData(
                app: $this->app,
                company: $company,
                agent: $this->agent,
                entity_namespace: Users::class,
                entity_id: (string) $guestUser->getId(),
                user: $userPayload,
                canal_id: $this->token,
            )
        )->execute();

        return Session::create([
            'uuid' => $this->token,
            'canal_id' => $this->token,
            'apps_id' => $this->app->getId(),
            'companies_id' => $company->getId(),
            'agents_id' => $this->agent->getId(),
            'entity_namespace' => Users::class,
            'entity_id' => $guestUser->getId(),
            'user' => $userPayload,
            'content' => $content,
        ]);
    }

    private function incrementTurns(Session $session, int $turnsUsed): void
    {
        $content = (array) ($session->content ?: []);
        $content[self::TURN_COUNTER_KEY] = $turnsUsed + 1;
        $session->content = $content;
        $session->saveQuietly();
    }
}
