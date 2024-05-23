<?php

declare(strict_types=1);

namespace Kanvas\Auth\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
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
            $recoverUser->notify(new ResetPassword(
                $recoverUser,
                [
                    'subject' => $this->app->name . ' - Reset your password',
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
        $recoverUser = UsersAssociatedApps::fromApp($this->app)
            ->notDeleted()
            ->where([
                'companies_id' => AppEnums::GLOBAL_COMPANY_ID->getValue(),
                'user_activation_forgot' => $hashKey,
            ])->firstOrFail();

        return $recoverUser->user()->firstOrFail()->resetPassword($newPassword, $this->app);
    }
}
