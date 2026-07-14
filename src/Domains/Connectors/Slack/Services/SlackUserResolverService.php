<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Connectors\Slack\Client;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Throwable;

class SlackUserResolverService
{
    public function __construct(
        private readonly Client $client,
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
    ) {
    }

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

    private function emailOf(string $slackUserId): ?string
    {
        try {
            $profile = $this->client->userInfo($slackUserId);
        } catch (Throwable) {
            // A missing `users:read.email` scope, a deactivated member, a rate limit — all mean the
            // same thing to the caller: we can't say who this is.
            return null;
        }

        $email = $profile['profile']['email'] ?? null;

        return is_string($email) && $email !== '' ? $email : null;
    }
}
