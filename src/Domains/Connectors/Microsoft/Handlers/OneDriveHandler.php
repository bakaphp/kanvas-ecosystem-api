<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\Handlers;

use Kanvas\Connectors\Microsoft\Support\MicrosoftTokenStore;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Users\Models\Users;
use Override;

/**
 * Integration `onedrive`: the OneDrive every company of the app shares, connected by the app owner.
 */
class OneDriveHandler extends BaseMicrosoftHandler
{
    #[Override]
    protected function store(): MicrosoftTokenStore
    {
        return MicrosoftTokenStore::forAppOneDrive($this->app);
    }

    /**
     * Any company admin can call integrationCompany, but this connection is every company's drive.
     */
    #[Override]
    protected function authorize(Users $user): void
    {
        if (! $user->isOwnerOf($this->app)) {
            throw new ValidationException('Only the app owner can connect the app\'s OneDrive.');
        }
    }
}
