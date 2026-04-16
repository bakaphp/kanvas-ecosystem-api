<?php

declare(strict_types=1);

namespace Kanvas\Users\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Services\EmailVerification as EmailVerificationService;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Notifications\Templates\CreateUserTemplate;
use Kanvas\Notifications\Templates\Welcome;
use Kanvas\Users\Models\Users;
use Throwable;

class UserNotificationService
{
    public static function sendCreateUserEmail(
        AppInterface $app,
        CompaniesBranches $branch,
        UserInterface $user,
        array $request
    ): void {
        if ($app->get((string) AppSettingsEnums::SEND_CREATE_USER_EMAIL->getValue())) {
            $createUserNotification = new CreateUserTemplate(
                $user,
                [
                    'company' => $branch->company,
                    'subject' => 'Welcome to ' . $app->name,
                ]
            );

            $createUserNotification->setData([
                'request' => $request['data'],
            ]);

            $user->notify($createUserNotification);
        }
    }

    public static function sendWelcomeEmail(
        AppInterface $app,
        UserInterface $user,
        ?CompanyInterface $company = null
    ): void {
        try {
            if ($app->get((string) AppSettingsEnums::SEND_WELCOME_EMAIL->getValue())) {
                $welcomeEmailConfig = $app->get((string) AppSettingsEnums::WELCOME_EMAIL_CONFIG->getValue()) ?? [];

                $title = $welcomeEmailConfig['title'] ?? 'Welcome to ' . $app->name;
                $user->notify(new Welcome(
                    $user,
                    $company ? ['company' => $company, 'subject' => $title, 'app' => $app] : ['app' => $app]
                ));
            }
        } catch (Throwable $e) {
            //no email sent
        }
    }

    public static function sendEmailVerification(
        AppInterface $app,
        UserInterface $user
    ): void {
        try {
            if (! $app->get((string) AppSettingsEnums::SEND_EMAIL_VERIFICATION->getValue())) {
                return;
            }

            if (! $user instanceof Users || ! $app instanceof Apps) {
                return;
            }

            (new EmailVerificationService($app))->send($user);
        } catch (Throwable $e) {
            //no email sent
        }
    }
}
