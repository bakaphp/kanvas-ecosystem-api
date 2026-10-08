<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Concerns;

use Baka\Support\Str;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\CreateUserAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * For commands declaring `--create-missing` and `--password`.
 */
trait CreatesDealerUsers
{
    /**
     * There is deliberately no default — a shared hardcoded password would be the
     * same on every dealer user this command ever created.
     */
    protected function newUserPasswordIsMissing(): bool
    {
        if (! (bool) $this->option('create-missing') || Str::trimToNull((string) $this->option('password')) !== null) {
            return false;
        }

        $this->error('--password is required when --create-missing=1 (pass --create-missing=0 to only map existing users).');

        return true;
    }

    /**
     * Null means skipped (already warned). A created user has `wasRecentlyCreated` set.
     */
    protected function findOrCreateDealerUser(
        Apps $app,
        Companies $company,
        string $email,
        string $firstname,
        string $lastname
    ): ?Users {
        try {
            return Users::getByEmail($email);
        } catch (ModelNotFoundException) {
        }

        if (! (bool) $this->option('create-missing')) {
            $this->warn("No Kanvas user for {$email} — skipped (create-missing disabled)");

            return null;
        }

        try {
            return $this->createDealerUser(
                $app,
                $company,
                $email,
                $firstname,
                $lastname
            );
        } catch (Throwable $e) {
            $this->warn("Failed to create user {$email}: " . $e->getMessage());

            return null;
        }
    }

    /**
     * The REGISTERED workflow is disabled, but CreateUserAction still sends the app's
     * welcome email when SEND_WELCOME_EMAIL is on — that one carries no password.
     */
    private function createDealerUser(
        Apps $app,
        Companies $company,
        string $email,
        string $firstname,
        string $lastname
    ): Users {
        /** @var CompaniesBranches $branch */
        $branch = $company->defaultBranch()->firstOrFail();

        $registerInput = RegisterInput::fromArray(
            [
                'firstname' => $firstname,
                'lastname' => $lastname,
                'displayname' => trim($firstname . ' ' . $lastname) ?: $email,
                'email' => $email,
                'password' => (string) $this->option('password'),
                'role_ids' => [RolesEnums::USER->value],
            ],
            $branch,
            $app
        );

        $action = new CreateUserAction($registerInput, $app);
        $action->disableWorkflow();

        return $action->execute();
    }
}
