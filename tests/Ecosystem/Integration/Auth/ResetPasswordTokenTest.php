<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Auth;

use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Auth\Services\ForgotPassword;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class ResetPasswordTokenTest extends TestCase
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

    public function testConsumedTokenIsNulledAndCannotBeReused(): void
    {
        $hashKey = $this->user->generateForgotHash($this->currentApp);
        $service = new ForgotPassword($this->currentApp);

        $this->assertTrue($service->reset(fake()->password(12), $hashKey));
        $this->assertNull($this->user->getAppProfile($this->currentApp)->user_activation_forgot);

        $this->expectException(ExceptionsModelNotFoundException::class);
        $service->reset(fake()->password(12), $hashKey);
    }

    /**
     * A consumed token used to be blanked to '' rather than nulled, so an empty
     * hash matched every user that had already reset their password.
     */
    public function testBlankHashKeyDoesNotMatchAConsumedToken(): void
    {
        $consumed = $this->user->getAppProfile($this->currentApp);
        $consumed->user_activation_forgot = '';
        $consumed->saveOrFail();

        $this->expectException(ExceptionsModelNotFoundException::class);

        new ForgotPassword($this->currentApp)->reset(fake()->password(12), '');
    }
}
