<?php

declare(strict_types=1);

namespace App\Console\Commands\Ecosystem\Notifications;

use Baka\Enums\StateEnums;
use Baka\Traits\KanvasJobsTrait;
use DateTime;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;

class MailAllAppUsersCommand extends Command
{
    use KanvasJobsTrait;

    protected ?Apps $app = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:mail-notification-to-all-app-users 
                            {apps_id : The ID of the application} 
                            {email_template_name : The name of the email template to use} 
                            {subject : The subject of the email} 
                            {--test-email= : Email address to send a test email instead of sending to all users}
                            {--production : Flag to confirm sending to all users in production}
                            {--delay=500 : Delay in milliseconds between each email}
                            {--created-after= : Filter users created after this date (Y-m-d H:i:s format)}
                            {--created-before= : Filter users created before this date (Y-m-d H:i:s format)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send specific email to all users of an app or to a test email address with optional date filtering';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('apps_id'));
        $this->app = $app;
        $this->overwriteAppService($app);
        $emailTemplateName = (string) $this->argument('email_template_name');
        $emailSubject = (string) $this->argument('subject');
        $testEmail = $this->option('test-email');
        $isProduction = $this->option('production');
        $delayMs = (int) $this->option('delay');
        $createdAfter = $this->option('created-after') ?? '';
        $createdBefore = $this->option('created-before') ?? '';

        // Validate date format if provided
        if ($createdAfter !== '' && ! $this->isValidDate($createdAfter)) {
            $this->error('Invalid created-after date format. Use Y-m-d H:i:s format (e.g., 2024-01-01 00:00:00)');

            return;
        }

        if ($createdBefore !== '' && ! $this->isValidDate($createdBefore)) {
            $this->error('Invalid created-before date format. Use Y-m-d H:i:s format (e.g., 2024-12-31 23:59:59)');

            return;
        }

        // Check if we're just testing to a single email
        if (is_string($testEmail) && ! empty($testEmail)) {
            try {
                $userModelEntity = Users::getByEmail($testEmail);
                $this->sendEmailToUser($userModelEntity, $emailTemplateName, $emailSubject);
                $this->info('Test email successfully sent to: ' . $userModelEntity->getId() . ' on app: ' . $app->getId());
            } catch (Exception $e) {
                $this->error('Failed to send test email: ' . $e->getMessage());

                return;
            }

            return;
        }

        // Check if we're in production mode and have the production flag
        if (! app()->isProduction() || ! $isProduction) {
            $this->error('This command can only send emails to all users in production with the --production flag.');
            $this->info('Use --test-email=email@example.com to send a test email instead.');

            return;
        }

        // Build the base query for counting and processing users
        $baseQuery = $this->buildUserQuery($app->getId(), $createdAfter, $createdBefore);

        // Count total users to process for progress bar
        $totalUsers = $baseQuery->count();

        if ($totalUsers === 0) {
            $this->warn('No users found for this app with the given criteria.');
            if ($createdAfter !== '' || $createdBefore !== '') {
                $this->info('Date filters applied:');
                if ($createdAfter !== '') {
                    $this->info('  - Created after: ' . $createdAfter);
                }
                if ($createdBefore !== '') {
                    $this->info('  - Created before: ' . $createdBefore);
                }
            }

            return;
        }

        $this->info("Found {$totalUsers} users to process.");
        if ($createdAfter !== '' || $createdBefore !== '') {
            $this->info('Date filters applied:');
            if ($createdAfter !== '') {
                $this->info('  - Created after: ' . $createdAfter);
            }
            if ($createdBefore !== '') {
                $this->info('  - Created before: ' . $createdBefore);
            }
        }

        // Initialize counters
        $successCount = 0;
        $failCount = 0;
        $processedCount = 0;

        // Initialize progress bar
        $progressBar = $this->output->createProgressBar($totalUsers);
        $progressBar->start();

        // Only runs if we're in production and have the production flag
        $this->buildUserQuery($app->getId(), $createdAfter, $createdBefore)
            ->orderBy('users_id')
            ->chunk(100, function ($users) use (
                $app,
                $emailTemplateName,
                $emailSubject,
                $progressBar,
                $delayMs,
                &$successCount,
                &$failCount,
                &$processedCount
            ) {
                foreach ($users as $user) {
                    try {
                        $userModelEntity = Users::getByEmail($user->email);
                        $this->sendEmailToUser($userModelEntity, $emailTemplateName, $emailSubject);
                        $successCount++;

                        // Only log detailed success if verbose
                        if ($this->getOutput()->isVerbose()) {
                            $this->info('Email successfully sent to: ' . $user->users_id . ' on app: ' . $app->getId());
                        }
                    } catch (Exception $e) {
                        $failCount++;
                        $this->error('Failed to send email to user ' . $user->users_id . ': ' . $e->getMessage());
                    }

                    $processedCount++;
                    $progressBar->advance();

                    // Add delay between emails to avoid overwhelming the mail server
                    if ($delayMs > 0) {
                        usleep($delayMs * 1000); // Convert ms to microseconds
                    }
                }
            });

        $progressBar->finish();
        $this->newLine(2);

        // Output summary
        $this->info('Email sending complete:');
        $this->info("  - Total processed: {$processedCount}");
        $this->info("  - Successfully sent: {$successCount}");

        if ($failCount > 0) {
            $this->error("  - Failed: {$failCount}");

            return;
        }

        $this->info('All emails have been sent successfully.');
    }

    /**
     * Send email to user using a custom template
     *
     * @throws Exception If email sending fails
     */
    private function sendEmailToUser(Users $user, string $emailTemplateName, string $emailSubject): void
    {
        $notification = new Blank(
            $emailTemplateName,
            [
                'user' => $user,
                'name' => $user->firstname . ' ' . $user->lastname,
            ],
            ['mail'],
            $user
        );

        $notification->setSubject($emailSubject);
        Notification::route('mail', $user->email)->notify($notification);
    }

    /**
     * Build the user query with optional date filters
     */
    private function buildUserQuery(int $appId, mixed $createdAfter = null, mixed $createdBefore = null): \Illuminate\Database\Query\Builder
    {
        $query = DB::table('users_associated_apps')
            ->join('users', 'users_associated_apps.users_id', '=', 'users.id')
            ->selectRaw('users_associated_apps.*, users.email')
            ->where('users_associated_apps.apps_id', $appId)
            ->where('users_associated_apps.is_deleted', StateEnums::NO->getValue())
            ->where('users_associated_apps.companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
            ->where('users.is_deleted', StateEnums::NO->getValue());

        // Apply created_at filters if provided
        if (is_string($createdAfter) && ! empty($createdAfter)) {
            $query->where('users.created_at', '>=', $createdAfter);
        }

        if (is_string($createdBefore) && ! empty($createdBefore)) {
            $query->where('users.created_at', '<=', $createdBefore);
        }

        return $query;
    }

    /**
     * Validate if the given string is a valid date
     */
    private function isValidDate(mixed $date): bool
    {
        if (! is_string($date)) {
            return false;
        }

        try {
            $dateTime = DateTime::createFromFormat('Y-m-d H:i:s', $date);

            return $dateTime && $dateTime->format('Y-m-d H:i:s') === $date;
        } catch (Exception $e) {
            return false;
        }
    }
}
