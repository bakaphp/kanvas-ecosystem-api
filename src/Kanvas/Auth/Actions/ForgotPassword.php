<?php

declare(strict_types=1);

namespace Kanvas\Auth\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Templates\ResetPassword;
use Kanvas\Users\Models\Users;

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
     * 
     * @return Users
     */
    public function forgot(array $data) : Users
    {
        $recoverUser = Users::getByEmail($data['email']);
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
     * @return bool
     */
    public function reset(array $data) : bool
    {
        $key = $data['hash_key'];

        $recoverUser = Users::where(
            [
                'user_activation_forgot' => $key,
                'is_deleted' => 0
            ]
        )->first();

        return $recoverUser->resetPassword($data['new_password']);
    }
}
