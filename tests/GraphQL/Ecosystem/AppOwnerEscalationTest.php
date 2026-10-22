<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Actions\CreateAppKeyAction;
use Kanvas\Apps\DataTransferObject\AppKeyInput;
use Kanvas\Apps\Models\AppKey;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Users\Models\Users;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;
use Tests\Traits\ActsAsAppOwner;

/**
 * A user who is not an owner of the app — what every self-signup is — cannot reach app ownership:
 * not by invite, by granting roles, by editing system roles, by reaching other companies' users, or
 * by riding someone else's app key.
 */
final class AppOwnerEscalationTest extends TestCase
{
    use ActsAsAppOwner;

    public function testANonOwnerCannotInviteAdmins(): void
    {
        Notification::fake();
        $this->actingAsNonOwner();

        $this->graphQL(/** @lang GraphQL */ '
            mutation inviteAdmin($data: AdminInviteInput!) {
                inviteAdmin(input: $data) { id }
            }',
            ['data' => ['email' => fake()->unique()->safeEmail(), 'custom_fields' => []]]
        )->assertGraphQLErrorMessage('Only an owner of this app can invite admins.');
    }

    public function testANonOwnerAdminCannotInviteAnOwner(): void
    {
        Notification::fake();
        $this->assertTrue($this->actingAsNonOwner()->isAdmin());
        $owner = RolesRepository::getByMixedParamFromCompany(param: RolesEnums::OWNER->value, app: app(Apps::class));

        $this->graphQL(/** @lang GraphQL */ '
            mutation inviteUser($data: InviteInput!) {
                inviteUser(input: $data) { id }
            }',
            ['data' => [
                'role_id' => $owner->id,
                'email' => 'invite-' . uniqid() . '@kanvas.dev',
                'firstname' => fake()->firstName(),
                'custom_fields' => [],
            ]]
        )->assertGraphQLErrorMessage('Only an owner of this app can change owner roles.');
    }

    public function testOwningAnotherAppDoesNotMakeYouOwnerOfTheKeysApp(): void
    {
        $app = app(Apps::class);
        $user = $this->createUser();
        $otherAppScope = 'app_' . ($app->getId() + 100000) . '_company_0';

        Bouncer::scope()->onceTo($otherAppScope, fn () => $user->assign(RolesEnums::OWNER->value));
        Bouncer::scope()->to($otherAppScope);
        Bouncer::refreshFor($user);
        app()->instance(AppKey::class, $app->keys()->firstOrFail());

        $this->assertFalse($user->isAppOwner());
    }

    public function testAnOwnerOfTheKeysAppIsAppOwner(): void
    {
        $owner = $this->actingAsAppOwner();
        app()->instance(AppKey::class, app(Apps::class)->keys()->firstOrFail());

        $this->assertTrue($owner->isAppOwner());
    }

    public function testAnAppKeyDoesNotMakeItsUserAnOwner(): void
    {
        $app = app(Apps::class);
        $user = $this->createUser();

        new CreateAppKeyAction(new AppKeyInput('escalation-' . uniqid(), $app, $user), createUserInApp: false)->execute();

        $this->assertFalse($user->isOwnerOf($app));
    }

    public function testANonOwnerCannotGrantThemselvesOwner(): void
    {
        $user = $this->actingAsNonOwner();
        $owner = RolesRepository::getByMixedParamFromCompany(param: RolesEnums::OWNER->value, app: app(Apps::class));

        $this->graphQL(/** @lang GraphQL */ '
            mutation assignRoleToUser($userId: ID!, $roleIds: [ID!]!) {
                assignRoleToUser(userId: $userId, roleIds: $roleIds)
            }',
            ['userId' => $user->getId(), 'roleIds' => [$owner->id]]
        )->assertGraphQLErrorMessage('Only an owner of this app can change owner roles.');

        $this->assertFalse($user->isOwnerOf(app(Apps::class)));
    }

    public function testANonOwnerCannotChangeASystemRole(): void
    {
        $this->actingAsNonOwner();
        $admins = RolesRepository::getByMixedParamFromCompany(param: RolesEnums::ADMIN->value, app: app(Apps::class));

        $this->graphQL(/** @lang GraphQL */ '
            mutation updateRole($id: ID!, $input: RoleInput) {
                updateRole(id: $id, input: $input) { id }
            }',
            ['id' => $admins->id, 'input' => ['name' => RolesEnums::ADMIN->value, 'permissions' => []]]
        )->assertGraphQLErrorMessage('Only an owner of this app can change a system role.');
    }

    public function testANonOwnerCannotEditAUserOfAnotherCompany(): void
    {
        $outsider = $this->createUser();
        $this->actingAsNonOwner();

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation updateUser($id: ID!, $data: UpdateUserInput!) {
                updateUser(id: $id, data: $data) { id }
            }',
            ['id' => $outsider->getId(), 'data' => ['firstname' => 'Hijacked']]
        );

        $this->assertNotEmpty($response->json('errors'));
        $this->assertNotSame('Hijacked', $outsider->refresh()->firstname);
    }

    public function testAnAppLevelPasswordResetLeavesTheGlobalPasswordAlone(): void
    {
        $app = app(Apps::class);
        $user = $this->createUser();
        $globalHash = $user->password;

        $user->resetPassword('new-app-password-1', $app, onlyThisApp: true);

        $this->assertSame($globalHash, $user->refresh()->password);
        $this->assertTrue(Hash::check('new-app-password-1', $user->getAppProfile($app)->password));
    }

    public function testAnAppLevelEmailChangeStillRequiresAnEmail(): void
    {
        $app = app(Apps::class);
        $user = $this->createUser();

        $this->expectException(ValidationException::class);

        $user->updateEmail('not-an-email', $app, onlyThisApp: true);
    }

    public function testAnAppKeyCannotCarryAnotherUsersTokenWhenEnforcedGlobally(): void
    {
        config(['kanvas.app_key.enforce_user_match' => true]);
        app(Apps::class)->del(AppSettingsEnums::APP_KEY_ENFORCE_USER_MATCH->getValue());

        $this->appKeyWithAnotherUsersToken()
            ->assertGraphQLErrorMessage('This app key belongs to another user.');
    }

    public function testAnAppKeyCannotCarryAnotherUsersTokenWhenTheAppEnforcesIt(): void
    {
        config(['kanvas.app_key.enforce_user_match' => false]);
        app(Apps::class)->set(AppSettingsEnums::APP_KEY_ENFORCE_USER_MATCH->getValue(), true);

        try {
            $this->appKeyWithAnotherUsersToken()
                ->assertGraphQLErrorMessage('This app key belongs to another user.');
        } finally {
            app(Apps::class)->del(AppSettingsEnums::APP_KEY_ENFORCE_USER_MATCH->getValue());
        }
    }

    public function testAnAppCanOptOutOfEnforcementWhileTheDefaultIsOn(): void
    {
        config(['kanvas.app_key.enforce_user_match' => true]);
        app(Apps::class)->set(AppSettingsEnums::APP_KEY_ENFORCE_USER_MATCH->getValue(), false);
        Log::shouldReceive('warning')->once();

        try {
            $this->appKeyWithAnotherUsersToken()->assertGraphQLErrorFree();
        } finally {
            app(Apps::class)->del(AppSettingsEnums::APP_KEY_ENFORCE_USER_MATCH->getValue());
        }
    }

    public function testAnAppKeyCarryingAnotherUsersTokenIsOnlyLoggedUntilEnforced(): void
    {
        config(['kanvas.app_key.enforce_user_match' => false]);
        app(Apps::class)->del(AppSettingsEnums::APP_KEY_ENFORCE_USER_MATCH->getValue());
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message) => $message === 'App key carried another user\'s token');

        $this->appKeyWithAnotherUsersToken()->assertGraphQLErrorFree();
    }

    private function appKeyWithAnotherUsersToken(): TestResponse
    {
        $app = app(Apps::class);
        $keyOwner = $this->createUser();
        $key = new CreateAppKeyAction(new AppKeyInput('carry-' . uniqid(), $app, $keyOwner), createUserInApp: false)->execute();
        $otherUsersToken = $this->createUser()->createToken('test')->toArray()['token'];

        return $this->graphQL(
            /** @lang GraphQL */
            '{ appUsers { data { id } } }',
            [],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $key->client_secret_id,
                'Authorization' => 'Bearer ' . $otherUsersToken,
            ]
        );
    }

    public function testAnInvalidAppKeyStopsTheRequest(): void
    {
        $response = $this->graphQL(
            /** @lang GraphQL */
            '{ me { id } }',
            [],
            [],
            [AppEnums::KANVAS_APP_KEY_HEADER->getValue() => 'not-a-key']
        );

        $response->assertStatus(500);
        $this->assertSame(['message' => 'No App Key configured with this key: not-a-key'], $response->json());
    }

    private function actingAsNonOwner(): Users
    {
        $user = $this->createUser();
        $this->assertFalse($user->isOwnerOf(app(Apps::class)));
        $this->actingAs($user, 'api');

        return $user;
    }
}
