<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Templates;

use Kanvas\Auth\Services\EmailVerification as EmailVerificationService;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Notifications\Notification;
use Kanvas\Templates\Enums\EmailTemplateEnum;
use Kanvas\Users\Models\Users;
use Override;

class EmailVerification extends Notification
{
    public ?string $templateName = EmailTemplateEnum::EMAIL_VERIFICATION->value;

    #[Override]
    public function getData(): array
    {
        $baseUrl = $this->app->get(AppSettingsEnums::EMAIL_VERIFICATION_LINK_URL->getValue())
            ?? $this->app->url . '/verify-email';

        /** @var Users $user */
        $user = $this->toUser;
        $token = new EmailVerificationService($this->app)->generateToken($user);

        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return [
            ...parent::getData(),
            'verifyUrl' => $baseUrl . $separator . 'token=' . urlencode($token),
        ];
    }
}
