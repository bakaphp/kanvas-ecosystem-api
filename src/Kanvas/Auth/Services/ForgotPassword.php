<?php

declare(strict_types=1);

namespace Kanvas\Auth\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Templates\ResetPassword;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;

class ForgotPassword
{
    protected Apps $app;

    /**
     * Construct function.
     */
    public function __construct()
    {
        $this->app = app(Apps::class);
    }

    /**
     * Send email forgot password.
     *
     * @param array $data
     */
    public function forgot(string $email): Users
    {
        $recoverUser = Users::getByEmail($email);
        $recoverUser->generateForgotHash();

        try {
            $recoverUser->notify(new ResetPassword($recoverUser));
        } catch (ModelNotFoundException $e) {
            //throw $th;
        }

        return $recoverUser;
    }

    /**
     * Get user and update password to the new one.
     *
     * @param array $data
     */
    public function reset(string $newPassword, string $hashKey): bool
    {
        $recoverUser = UsersAssociatedApps::fromApp()
            ->where([
                'user_activation_forgot' => $hashKey,
                'is_deleted' => 0,
            ])->firstOrFail();

        return $recoverUser->resetPassword($newPassword);
    }
}
