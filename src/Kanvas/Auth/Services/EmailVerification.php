<?php

declare(strict_types=1);

namespace Kanvas\Auth\Services;

use Carbon\Carbon;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Notifications\Templates\EmailVerification as EmailVerificationNotification;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Throwable;

class EmailVerification
{
    protected Apps $app;

    public function __construct(?Apps $app = null)
    {
        $this->app = $app ?? app(Apps::class);
    }

    public function send(Users $user): bool
    {
        $profile = UsersAssociatedApps::fromApp($this->app)
            ->notDeleted()
            ->where('users_id', $user->getId())
            ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
            ->first();

        if (! $profile) {
            throw new ModelNotFoundException('User profile not found for this app.');
        }

        if ((bool) $profile->is_verified) {
            return false;
        }

        try {
            $subject = $this->app->get((string) AppSettingsEnums::EMAIL_VERIFICATION_EMAIL_SUBJECT->getValue())
                ?? $this->app->name . ' - Verify your email';

            $user->notify(new EmailVerificationNotification(
                $user,
                [
                    'subject' => $subject,
                    'app' => $this->app,
                ]
            ));
        } catch (Throwable $e) {
            return false;
        }

        return true;
    }

    public function verify(string $token): bool
    {
        $payload = $this->decodeToken($token);

        if ((int) $payload['app_id'] !== $this->app->getId()) {
            throw new ValidationException('Verification link is invalid.');
        }

        if (Carbon::now()->getTimestamp() > (int) $payload['exp']) {
            throw new ValidationException('Verification link has expired.');
        }

        $profile = UsersAssociatedApps::fromApp($this->app)
            ->notDeleted()
            ->where('users_id', (int) $payload['user_id'])
            ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
            ->first();

        if (! $profile) {
            throw new ModelNotFoundException('Verification link is invalid.');
        }

        if ((bool) $profile->is_verified) {
            return true;
        }

        $profile->is_verified = 1;
        $profile->email_verified_at = Carbon::now()->toDateTimeString();
        $profile->saveOrFail();

        return true;
    }

    public function generateToken(Users $user): string
    {
        $ttlHours = (int) ($this->app->get((string) AppSettingsEnums::EMAIL_VERIFICATION_LINK_TTL_HOURS->getValue()) ?: 24);

        $payload = [
            'user_id' => $user->getId(),
            'app_id' => $this->app->getId(),
            'exp' => Carbon::now()->addHours($ttlHours)->getTimestamp(),
        ];

        return Crypt::encryptString(json_encode($payload));
    }

    private function decodeToken(string $token): array
    {
        try {
            $raw = Crypt::decryptString($token);
        } catch (DecryptException $e) {
            throw new ValidationException('Verification link is invalid.');
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload) || ! isset($payload['user_id'], $payload['app_id'], $payload['exp'])) {
            throw new ValidationException('Verification link is invalid.');
        }

        return $payload;
    }
}
