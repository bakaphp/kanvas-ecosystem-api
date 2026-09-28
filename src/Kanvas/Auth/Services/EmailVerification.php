<?php

declare(strict_types=1);

namespace Kanvas\Auth\Services;

use Baka\Contracts\AppInterface;
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

    public static function isRequiredFor(AppInterface $app): bool
    {
        return filter_var($app->get(AppSettingsEnums::REQUIRE_EMAIL_VERIFICATION->getValue()), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Returns false both when the profile is already verified and when the
     * notification throws — read `is_verified` first if the two need telling
     * apart.
     */
    public function send(Users $user): bool
    {
        $profile = $this->profile($user->getId());

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

        if (Carbon::now()->getTimestamp() >= (int) $payload['exp']) {
            throw new ValidationException('Verification link has expired.');
        }

        $profile = $this->profile((int) $payload['user_id']);

        if (! $profile) {
            throw new ModelNotFoundException('Verification link is invalid.');
        }

        return $this->markProfileVerified($profile);
    }

    /**
     * Social login is itself proof of ownership — Google, Apple and Facebook
     * only mint an identity token for a mailbox they already confirmed — so a
     * profile arriving through a provider is verified without being sent a link.
     * Without this, `require_email_verification` locks out every social user.
     */
    public function markVerified(Users $user): bool
    {
        $profile = $this->profile($user->getId());

        if (! $profile) {
            throw new ModelNotFoundException('User profile not found for this app.');
        }

        return $this->markProfileVerified($profile);
    }

    private function markProfileVerified(UsersAssociatedApps $profile): bool
    {
        if ((bool) $profile->is_verified) {
            return true;
        }

        $profile->is_verified = 1;
        $profile->email_verified_at = Carbon::now()->toDateTimeString();
        $profile->saveOrFail();

        return true;
    }

    private function profile(int $usersId): ?UsersAssociatedApps
    {
        return UsersAssociatedApps::fromApp($this->app)
            ->notDeleted()
            ->where('users_id', $usersId)
            ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
            ->first();
    }

    public function generateToken(Users $user): string
    {
        $ttlHours = (int) ($this->app->get((string) AppSettingsEnums::EMAIL_VERIFICATION_LINK_TTL_HOURS->getValue()) ?? 24);

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
