<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Github\Handlers;

use Baka\Support\Str;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Github\Client;
use Kanvas\Connectors\Github\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;
use Throwable;

/**
 * Stores a GitHub token for the app and proves it works before saving it for good.
 *
 * Validation reads one repository's releases rather than hitting /user: a token can be valid and still
 * be unable to see the repository we care about, and finding that out at setup time is the whole point
 * of this handler.
 */
class GithubHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $token = Str::trimToNull($this->data['github_token'] ?? null);

        if ($token === null) {
            throw new ValidationException('A GitHub token is required.');
        }

        $repository = Str::trimToNull($this->data['repository'] ?? null);

        if ($repository === null) {
            throw new ValidationException(
                'A repository is required, as owner/repo or its URL — it is what the token is validated against.'
            );
        }

        $this->validate($token, Client::normalizeRepository($repository));

        $this->app->set(ConfigurationEnum::TOKEN->value, $token);

        return true;
    }

    private function validate(string $token, string $repository): void
    {
        try {
            new Client($token)->releases($repository);
        } catch (Throwable $e) {
            // The client reports the HTTP status; 401/403 is a bad or under-scoped token, 404 is a
            // repository this token cannot see — which for a private repo looks identical to a typo.
            throw new ValidationException(
                'Could not read releases for ' . $repository . ' with that token. '
                . 'Check the token grants read access to that repository — a fine-grained token must '
                . 'list it explicitly and be approved by the organization. (' . $e->getMessage() . ')'
            );
        }
    }
}
