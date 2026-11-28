<?php

declare(strict_types=1);

namespace App\Console\Commands\Ecosystem\Notifications;

use Baka\Traits\KanvasJobsTrait;
use Baka\Validations\Date;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Mailings\Actions\CreateAppUserMailingAction;
use Kanvas\Notifications\Mailings\DataTransferObject\AppUserMailing as AppUserMailingData;
use Kanvas\Notifications\Mailings\Enums\AppUserMailingAudienceEnum;
use Kanvas\Notifications\Mailings\Services\AppUserMailingNotificationFactory;
use Kanvas\Notifications\Mailings\Services\MailingAudienceService;
use Kanvas\Users\Models\Users;

class MailAllAppUsersCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:mail-notification-to-all-app-users
                            {apps_id : The ID of the application}
                            {email_template_name : The name of the email template to use}
                            {subject : The subject of the email}
                            {--test-email= : Email address to send a test email instead of sending to all users}
                            {--production : Flag to confirm sending to all users in production}
                            {--created-after= : Filter users created after this date (Y-m-d H:i:s format)}
                            {--created-before= : Filter users created before this date (Y-m-d H:i:s format)}';

    protected $description = 'Queue a template email to every eligible user of an app (skipping inactive, banned, marketing-off and muted users) or send a test to one address';

    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('apps_id'));
        $this->overwriteAppService($app);
        $emailTemplateName = (string) $this->argument('email_template_name');
        $emailSubject = (string) $this->argument('subject');
        $testEmail = $this->option('test-email');
        $createdAfter = (string) ($this->option('created-after') ?? '');
        $createdBefore = (string) ($this->option('created-before') ?? '');

        if ($createdAfter !== '' && ! Date::isValid($createdAfter, 'Y-m-d H:i:s')) {
            $this->error('Invalid created-after date format. Use Y-m-d H:i:s format (e.g., 2024-01-01 00:00:00)');

            return;
        }

        if ($createdBefore !== '' && ! Date::isValid($createdBefore, 'Y-m-d H:i:s')) {
            $this->error('Invalid created-before date format. Use Y-m-d H:i:s format (e.g., 2024-12-31 23:59:59)');

            return;
        }

        if (is_string($testEmail) && $testEmail !== '') {
            $this->sendTestEmail($app, $testEmail, $emailTemplateName, $emailSubject);

            return;
        }

        if (! app()->isProduction() || ! $this->option('production')) {
            $this->error('This command can only send emails to all users in production with the --production flag.');
            $this->info('Use --test-email=email@example.com to send a test email instead.');

            return;
        }

        $this->queueMailing(
            $app,
            $emailTemplateName,
            $emailSubject,
            array_filter(['created_after' => $createdAfter, 'created_before' => $createdBefore]),
        );
    }

    private function sendTestEmail(Apps $app, string $testEmail, string $templateName, string $subject): void
    {
        try {
            $user = Users::getByEmail($testEmail);
            $factory = new AppUserMailingNotificationFactory($app, null, null);

            Notification::sendNow(
                $user,
                $factory->forRecipient(
                    $factory->make($templateName, $subject, honorMarketingPreferences: false),
                    $user,
                ),
            );
            $this->info('Test email successfully sent to: ' . $user->getId() . ' on app: ' . $app->getId());
        } catch (Exception $e) {
            $this->error('Failed to send test email: ' . $e->getMessage());
        }
    }

    private function queueMailing(Apps $app, string $templateName, string $subject, array $criteria): void
    {
        $audience = new MailingAudienceService($app, null)->resolve(AppUserMailingAudienceEnum::APP_USERS, $criteria);

        if ($audience['eligible'] === []) {
            $this->warn('No eligible users found for this app with the given criteria.');

            return;
        }

        $mailing = new CreateAppUserMailingAction(
            new AppUserMailingData(
                app: $app,
                company: null,
                requester: null,
                agentId: null,
                templateName: $templateName,
                subject: $subject,
                audience: AppUserMailingAudienceEnum::APP_USERS,
                criteria: $criteria,
                recipientIds: $audience['eligible'],
                excludedBreakdown: $audience['excluded'],
            ),
        )->execute();

        $this->info("Mailing #{$mailing->getId()} ({$mailing->uuid}) queued on the app-mailing queue.");
        $this->info('  - Eligible: ' . count($audience['eligible']));
        $this->info('  - Excluded: ' . array_sum($audience['excluded']));

        foreach (array_filter($audience['excluded']) as $reason => $count) {
            $this->info("      {$reason}: {$count}");
        }
    }
}
