<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\Handlers;

use Kanvas\Connectors\Microsoft\Support\MicrosoftTokenStore;
use Kanvas\Users\Models\Users;
use Override;

/**
 * Integration `microsoft`: a company's own account, one consent for its Outlook and its own OneDrive.
 * They share the tokens, so disconnecting it stops both; the app's shared OneDrive is not touched.
 */
class MicrosoftHandler extends BaseMicrosoftHandler
{
    #[Override]
    protected function store(): MicrosoftTokenStore
    {
        return MicrosoftTokenStore::forCompanyMail($this->app, $this->company);
    }

    /**
     * The resolver already requires the user to belong to the company; that is all a company's own
     * connection needs.
     */
    #[Override]
    protected function authorize(Users $user): void
    {
    }
}
