<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Apps;

use App\Http\Middleware\KanvasAppKeyMiddleware;
use Illuminate\Http\Request;
use Kanvas\Apps\Actions\CreateAppKeyAction;
use Kanvas\Apps\DataTransferObject\AppKeyInput;
use Kanvas\Apps\Models\AppKey;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Enums\AppEnums;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class AppKeyRevocationTest extends TestCase
{
    private Apps $currentApp;
    private AppKey $appKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);

        // apps_keys is keyed on (apps_id, users_id), so every key in this file
        // needs its own user or the second insert collides on the primary key.
        $keyOwner = new RegisterUsersAction(
            RegisterInput::from([
                'email' => fake()->unique()->safeEmail(),
                'password' => fake()->password(12),
                'firstname' => fake()->firstName(),
                'lastname' => fake()->lastName(),
            ])
        )->execute();

        $this->appKey = new CreateAppKeyAction(
            new AppKeyInput(
                'revocation-test-' . fake()->unique()->uuid(),
                $this->currentApp,
                $keyOwner
            ),
            createUserInApp: false
        )->execute();
    }

    /**
     * The middleware writes its error response with ->send(), so the buffer has
     * to be swallowed for the failure path not to leak into the test output.
     */
    private function runMiddleware(string $secret): void
    {
        $request = Request::create('/graphql', 'POST');
        $request->headers->set(AppEnums::KANVAS_APP_KEY_HEADER->getValue(), $secret);

        ob_start();
        new KanvasAppKeyMiddleware()->handle($request, fn () => new Response());
        ob_end_clean();
    }

    public function testActiveKeyAuthenticates(): void
    {
        $this->runMiddleware($this->appKey->client_secret_id);

        $this->assertTrue(app()->bound(AppKey::class));
        $this->assertSame($this->appKey->client_id, app(AppKey::class)->client_id);
    }

    public function testRevokedKeyDoesNotAuthenticate(): void
    {
        $this->appKey->softDelete();

        $this->runMiddleware($this->appKey->client_secret_id);

        $this->assertFalse(app()->bound(AppKey::class));
    }

    public function testRevokeCommandSoftDeletesTheKey(): void
    {
        $this->artisan('kanvas:app-key-revoke', [
            'app_id' => $this->currentApp->getId(),
            '--key' => $this->appKey->client_id,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertTrue((bool) $this->appKey->refresh()->is_deleted);
        $this->assertNull(
            AppKey::notDeleted()->where('client_secret_id', $this->appKey->client_secret_id)->first()
        );
    }

    public function testRevokeCommandCanScheduleAnExpirationInstead(): void
    {
        $this->artisan('kanvas:app-key-revoke', [
            'app_id' => $this->currentApp->getId(),
            '--key' => $this->appKey->client_secret_id,
            '--expires-at' => '2020-01-01 00:00:00',
        ])->assertExitCode(0);

        $this->appKey->refresh();

        $this->assertFalse((bool) $this->appKey->is_deleted);
        $this->assertTrue($this->appKey->hasExpired());
    }

    public function testRotateCommandReplacesTheSecret(): void
    {
        $oldSecret = $this->appKey->client_secret_id;

        $this->artisan('kanvas:app-key-revoke', [
            'app_id' => $this->currentApp->getId(),
            '--key' => $this->appKey->client_id,
            '--rotate' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->appKey->refresh();

        $this->assertNotSame($oldSecret, $this->appKey->client_secret_id);
        $this->assertFalse((bool) $this->appKey->is_deleted);
        $this->assertNull(AppKey::notDeleted()->where('client_secret_id', $oldSecret)->first());
    }

    public function testRevokedKeyDisappearsFromTheAppRelations(): void
    {
        $this->assertTrue($this->currentApp->keys()->where('client_id', $this->appKey->client_id)->exists());

        $this->appKey->softDelete();

        $this->assertFalse($this->currentApp->keys()->where('client_id', $this->appKey->client_id)->exists());
        $this->assertFalse(
            $this->currentApp->getUserKeys($this->appKey->user)->contains('client_id', $this->appKey->client_id)
        );
    }

    public function testRevokeCommandFailsOnAnUnknownKey(): void
    {
        $this->artisan('kanvas:app-key-revoke', [
            'app_id' => $this->currentApp->getId(),
            '--key' => 'does-not-exist',
            '--force' => true,
        ])->assertExitCode(1);
    }
}
