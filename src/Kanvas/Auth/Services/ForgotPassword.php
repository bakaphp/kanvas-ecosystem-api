<?php

declare(strict_types=1);

namespace Kanvas\Auth\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Notifications\Templates\ResetPassword;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;

class ForgotPassword
{
    protected Apps $app;

    public function __construct()
    {
        $this->app = app(Apps::class);
    }

    /**
     * Send email forgot password.
     */
    public function forgot(string $email): Users
    {
        $recoverUser = Users::getByEmail($email);
        $recoverUser->generateForgotHash($this->app);

        try {
            $resetPasswordTitle = $this->app->get((string) AppSettingsEnums::RESET_PASSWORD_EMAIL_SUBJECT->getValue()) ?? $this->app->name . ' - Reset your password';

            $recoverUser->notify(new ResetPassword(
                $recoverUser,
                [
                    'subject' => $resetPasswordTitle,
                    'app' => $this->app,
                ]
            ));
        } catch (ModelNotFoundException $e) {
            //throw $th;
        }

        return $recoverUser;
    }

    /**
     * Get user and update password to the new one.
     */
    public function reset(string $newPassword, string $hashKey): bool
    {
        try {
            $recoverUser = UsersAssociatedApps::fromApp($this->app)
                ->notDeleted()
                ->where([
                    'companies_id' => AppEnums::GLOBAL_COMPANY_ID->getValue(),
                    'user_activation_forgot' => $hashKey,
                ])->firstOrFail();
        } catch (ModelNotFoundException $e) {
            throw new ExceptionsModelNotFoundException('Password reset link has expired, request a new link.');
        }

        return $recoverUser->user()->firstOrFail()->resetPassword($newPassword, $this->app);
    }
}
