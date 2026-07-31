<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Users;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Actions\CreateAppsAction;
use Kanvas\Apps\DataTransferObject\AppInput;
use Kanvas\Apps\Models\Apps;
use Tests\TestCase;

/**
 * Regression for https://github.com/bakaphp/kanvas-ecosystem-api/issues/10500
 *
 * `Users::default_company` is a plain, global column on the `users` row (it is not
 * scoped per app). A single physical user can be an owner/member of several apps in
 * the ecosystem, each with their own set of companies. When a *new* app is created
 * that has no company of its own yet, `currentCompanyId()` must not fall back to a
 * `default_company` (or cached hint) that actually belongs to a *different* app -
 * doing so silently scopes new resources to an unrelated tenant's company.
 */
final class CurrentCompanyIdAppScopingTest extends TestCase
{
    use DatabaseTransactions;

    public function testNewAppWithoutCompanyDoesNotInheritCompanyFromAnotherApp(): void
    {
        $user = Auth::user();

        // Sanity check: on the "original" app this user already has a real company.
        $originalCompanyId = $user->getCurrentCompany()->getId();
        $this->assertGreaterThan(0, $originalCompanyId);

        // Simulate "create a new app" - the owner is associated to the new app with
        // no company of its own (see CreateAppsAction::execute() / associateUser()).
        $newAppData = AppInput::from([
            'name' => 'Brand new tenant app',
            'url' => 'brand-new-tenant.test',
            'description' => 'App with no company associated to it',
            'domain' => 'brand-new-tenant.test',
            'is_actived' => 1,
            'ecosystem_auth' => 1,
            'payments_active' => 0,
            'is_public' => 1,
            'domain_based' => 0,
        ]);

        $newApp = new CreateAppsAction($newAppData, $user)->execute();

        // Switch the resolved "current app" to the freshly created one, the same
        // way the app container is bound per-request based on the incoming app key.
        app()->instance(Apps::class, $newApp);

        // The user has no company at all in the new app's scope, so resolving the
        // current company id must NOT leak the other app's company id.
        $this->assertNotSame($originalCompanyId, $user->currentCompanyId());
        $this->assertSame(0, $user->currentCompanyId());
    }
}
