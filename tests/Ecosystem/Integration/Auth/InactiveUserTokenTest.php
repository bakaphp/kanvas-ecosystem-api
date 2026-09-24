<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Auth;

use App\GraphQL\Ecosystem\Mutations\Auth\AuthManagementMutation;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\Actions\SocialLoginAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Auth\Exceptions\AuthenticationException;
use Kanvas\Auth\Socialite\DataTransferObject\User as SocialiteUser;
use Kanvas\Auth\TokenGuard;
use Kanvas\Enums\SourceEnum;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Sessions\Models\Sessions;
use Kanvas\Users\Models\Sources;
use Kanvas\Users\Models\UserLinkedSources;
use Kanvas\Users\Models\Users;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha512;
use Lcobucci\JWT\Signer\Key\InMemory;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;
use Tests\TestCase;

final class InactiveUserTokenTest extends TestCase
{
    private Apps $currentApp;
    private Users $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);

        $this->user = new RegisterUsersAction(
            RegisterInput::from([
                'email' => fake()->unique()->safeEmail(),
                'password' => fake()->password(12),
                'firstname' => fake()->firstName(),
                'lastname' => fake()->lastName(),
            ])
        )->execute();
    }

    public function testRefreshIssuesANewTokenForAnActiveUser(): void
    {
        $tokens = $this->user->createToken('test')->toArray();

        $refreshed = $this->refresh($tokens['refresh_token']);

        $this->assertSame($this->user->getId(), $refreshed['id']);
        $this->assertNotEmpty($refreshed['token']);
    }

    public function testRefreshRejectsATokenNotSignedByUs(): void
    {
        $session = $this->user->createToken('test')->toArray();
        $victim = new RegisterUsersAction(
            RegisterInput::from([
                'email' => fake()->unique()->safeEmail(),
                'password' => fake()->password(12),
                'firstname' => fake()->firstName(),
                'lastname' => fake()->lastName(),
            ])
        )->execute();

        $forged = $this->forgeRefreshToken($session['sessionId'], $victim->email);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Invalid Token');

        $this->refresh($forged);
    }

    public function testRefreshRejectsATokenWhoseSessionWasEnded(): void
    {
        $tokens = $this->user->createToken('test')->toArray();
        new Sessions()->endAll($this->user, $this->currentApp);

        $this->expectException(ModelNotFoundException::class);

        $this->refresh($tokens['refresh_token']);
    }

    public function testRefreshRejectsADeactivatedUser(): void
    {
        $tokens = $this->user->createToken('test')->toArray();
        $this->user->getAppProfile($this->currentApp)->deActive();

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('User is not active, please contact support.');

        $this->refresh($tokens['refresh_token']);
    }

    public function testGuardAuthenticatesAnActiveUser(): void
    {
        $tokens = $this->user->createToken('test')->toArray();

        $this->assertSame($this->user->getId(), $this->guardFor($tokens['token'])->user()->getId());
    }

    public function testGuardRejectsABannedUserWithALiveSession(): void
    {
        $tokens = $this->user->createToken('test')->toArray();

        $profile = $this->user->getAppProfile($this->currentApp);
        $profile->banned = 1;
        $profile->saveOrFail();

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('User has been banned, please contact support.');

        $this->guardFor($tokens['token'])->user();
    }

    public function testSocialLoginRejectsABannedLinkedUser(): void
    {
        $source = Sources::where('title', SourceEnum::GOOGLE->value)->firstOrFail();
        $socialId = (string) Str::uuid();

        UserLinkedSources::create([
            'users_id' => $this->user->getId(),
            'source_id' => $source->getId(),
            'source_users_id' => $socialId,
            'apps_id' => $this->currentApp->getId(),
            'source_users_id_text' => 'token',
            'source_username' => 'banned-user',
        ]);

        $profile = $this->user->getAppProfile($this->currentApp);
        $profile->banned = 1;
        $profile->saveOrFail();

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('User has been banned, please contact support.');

        new SocialLoginAction(
            new SocialiteUser(
                id: $socialId,
                name: 'Banned User',
                email: $this->user->email,
                nickname: 'banned-user',
                token: 'token',
            ),
            SourceEnum::GOOGLE->value,
            $this->currentApp
        )->execute();
    }

    private function refresh(string $refreshToken): array
    {
        return new AuthManagementMutation()->refreshToken(null, ['refresh_token' => $refreshToken]);
    }

    private function guardFor(string $token): TokenGuard
    {
        $request = Request::create('/graphql', 'POST');
        $request->headers->set('Authorization', 'Bearer ' . $token);

        return new TokenGuard(Auth::createUserProvider('users'), $request);
    }

    private function forgeRefreshToken(string $sessionId, string $email): string
    {
        $config = Configuration::forSymmetricSigner(
            new Sha512(),
            InMemory::plainText(str_repeat('attacker-key-', 8))
        );
        $now = new DateTimeImmutable();

        return $config->builder()
            ->issuedBy(config('auth.token_audience'))
            ->permittedFor(config('auth.token_audience'))
            ->identifiedBy($sessionId)
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->modify('+1 day'))
            ->withClaim('sessionId', $sessionId)
            ->withClaim('email', $email)
            ->getToken($config->signer(), $config->signingKey())
            ->toString();
    }
}
