<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\Slack\Client;
use Kanvas\Connectors\Slack\Exceptions\SlackIdentityUnavailableException;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Throwable;

class SlackUserResolverService
{
    private const int EMAIL_CACHE_TTL = 3600;

    public function __construct(
        private readonly Client $client,
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
    ) {
    }

    /**
     * @throws SlackIdentityUnavailableException when Slack can't be reached to identify the sender —
     *   a transient failure the caller must NOT mistake for "this person has no account".
     */
    public function resolve(string $slackUserId): ?Users
    {
        $email = $this->emailOf($slackUserId);

        if ($email === null) {
            return null;
        }

        try {
            $user = UsersRepository::getUserOfAppByEmail($email, $this->app);
            UsersRepository::belongsToCompany($user, $this->company);

            return $user;
        } catch (ModelNotFoundException | ExceptionsModelNotFoundException) {
            return null;
        }
    }

    /**
     * A cache hit skips the `users.info` round-trip entirely — Slack rate-limits that endpoint, and
     * we'd otherwise hit it on every inbound message from the same person, turning a rate limit into
     * a spurious "can't identify you" for an already-known teammate.
     *
     * @throws SlackIdentityUnavailableException
     */
    private function emailOf(string $slackUserId): ?string
    {
        $cacheKey = 'slack:user-email:' . $this->app->getId() . ':' . $this->company->getId() . ':' . $slackUserId;

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $profile = $this->client->userInfo($slackUserId);
        } catch (Throwable $e) {
            // A rate limit, timeout, or network blip means we simply couldn't ask who this is — it
            // is NOT evidence the person lacks an account. Signal transient so the caller skips the
            // turn instead of telling an invited teammate to get invited.
            throw new SlackIdentityUnavailableException(
                'Could not reach Slack to identify user ' . $slackUserId,
                previous: $e,
            );
        }

        $email = $profile['profile']['email'] ?? null;

        if (! is_string($email) || $email === '') {
            // Slack answered but gave no email — a missing `users:read.email` scope or a member with
            // no email. Terminal, not transient; leave it uncached so a fixed scope resolves promptly.
            return null;
        }

        Cache::put($cacheKey, $email, self::EMAIL_CACHE_TTL);

        return $email;
    }
}
