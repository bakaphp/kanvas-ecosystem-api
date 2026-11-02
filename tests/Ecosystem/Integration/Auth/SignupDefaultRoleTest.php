<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Auth;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppSettingsEnums;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Roles are app-wide, so a signup becomes an admin only when the app's default_signup_role says so.
 * Serial: it rewrites an app setting every parallel process reads.
 */
#[Group('serial')]
final class SignupDefaultRoleTest extends TestCase
{
    /**
     * @return array<string, array{string|null, bool}>
     */
    public static function signupRoleProvider(): array
    {
        return [
            'no setting' => [null, false],
            'unknown role' => ['customer', false],
            'users' => [RolesEnums::USER->value, false],
            'admins' => ['Admins', true],
            'admin' => [RolesEnums::ADMIN->value, true],
        ];
    }

    #[DataProvider('signupRoleProvider')]
    public function testASignupGetsTheRoleTheAppConfigures(?string $setting, bool $becomesAdmin): void
    {
        $app = app(Apps::class);
        $key = AppSettingsEnums::DEFAULT_SIGNUP_ROLE->getValue();

        try {
            $setting === null ? $app->del($key) : $app->set($key, $setting);

            $user = $this->createUser();

            $this->assertSame($becomesAdmin, $user->isAn(RolesEnums::ADMIN->value));
            $this->assertFalse($user->isOwnerOf($app));
        } finally {
            $app->set($key, RolesEnums::ADMIN->value);
        }
    }
}
