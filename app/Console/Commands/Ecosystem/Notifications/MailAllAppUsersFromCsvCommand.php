<?php

declare(strict_types=1);

namespace App\Console\Commands\Ecosystem\Notifications;

use Baka\Traits\KanvasJobsTrait;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;
use League\Csv\Reader;
use stdClass;
use Symfony\Component\Console\Helper\ProgressBar;

class MailAllAppUsersFromCsvCommand extends Command
{
    use KanvasJobsTrait;

    protected ?Apps $app = null;
    protected string $redisHashKey = 'csv:emails:processed';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:mail-notification-to-app-users-csv 
                            {apps_id : The ID of the application} 
                            {email_template_name : The name of the email template to use} 
                            {subject : The subject of the email}
                            {csv_file : The path to the CSV file containing user data}
                            {--test-email= : Email address to send a test email instead of sending to all users}
                            {--production : Flag to confirm sending to all users in production}
                            {--delay=500 : Delay in milliseconds between each email}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send specific email to unregistered users from a CSV file or to a test email address';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('apps_id'));
        $this->app = $app;
        $this->overwriteAppService($app);
        $emailTemplateName = $this->argument('email_template_name');
        $emailSubject = $this->argument('subject');
        $csvFilePath = $this->argument('csv_file');
        $testEmail = $this->option('test-email');
        $isProduction = $this->option('production');
        $delayMs = (int) $this->option('delay');
        $this->redisHashKey .= ':app:' . $app->getId();

        // Validate CSV file exists
        if (! file_exists($csvFilePath)) {
            $this->error("CSV file not found: {$csvFilePath}");

            return 1;
        }

        // Check if we're just testing to a single email
        if ($testEmail) {
            try {
                // Get real user from database for test
                $userModelEntity = Users::getByEmail($testEmail);
                $this->sendEmailToUser($userModelEntity, $emailTemplateName, $emailSubject);
                $this->info('Test email successfully sent to: ' . $testEmail . ' on app: ' . $app->getId());
            } catch (Exception $e) {
                $this->error('Failed to send test email: ' . $e->getMessage());

                return 1;
            }

            return 0;
        }

        // Check if we're in production mode and have the production flag
        if (! app()->isProduction() || ! $isProduction) {
            $this->error('This command can only send emails to all users in production with the --production flag.');
            $this->info('Use --test-email=email@example.com to send a test email instead.');

            return 1;
        }

        try {
            $csvData = $this->readCsvFile($csvFilePath);

            if (empty($csvData)) {
                $this->warn('No users found in the CSV file.');

                return 0;
            }

            $this->info('Found ' . count($csvData) . ' users to process.');

            // Initialize counters
            $successCount = 0;
            $failCount = 0;
            $skippedCount = 0;
            $processedCount = 0;

            // Initialize progress bar
            $progressBar = $this->output->createProgressBar(count($csvData));
            $progressBar->start();

            // Process records in chunks
            $chunk = [];
            $chunkSize = 100;

            foreach ($csvData as $record) {
                $chunk[] = $record;

                if (count($chunk) === $chunkSize) {
                    $this->processChunk(
                        $chunk,
                        $app,
                        $emailTemplateName,
                        $emailSubject,
                        $progressBar,
                        $delayMs,
                        $successCount,
                        $failCount,
                        $skippedCount,
                        $processedCount
                    );
                    $chunk = [];
                }
            }

            // Process remaining records
            if (! empty($chunk)) {
                $this->processChunk(
                    $chunk,
                    $app,
                    $emailTemplateName,
                    $emailSubject,
                    $progressBar,
                    $delayMs,
                    $successCount,
                    $failCount,
                    $skippedCount,
                    $processedCount
                );
            }

            $progressBar->finish();
            $this->newLine(2);

            // Output summary
            $this->info('Email sending complete:');
            $this->info("  - Total processed: {$processedCount}");
            $this->info("  - Successfully sent: {$successCount}");
            $this->info("  - Skipped (already registered): {$skippedCount}");

            if ($failCount > 0) {
                $this->error("  - Failed: {$failCount}");

                return 1;
            }

            $this->info('All emails have been sent successfully.');

            return 0;
        } catch (Exception $e) {
            $this->error('Error: ' . $e->getMessage());

            return 1;
        }
    }

    /**
     * Read and validate CSV file
     *
     * @throws Exception
     */
    private function readCsvFile(string $csvFilePath): array
    {
        try {
            $csv = Reader::from($csvFilePath, 'r');
            $csv->setHeaderOffset(0);

            // Get all records as an array
            $records = iterator_to_array($csv->getRecords());

            if (empty($records)) {
                return [];
            }

            // Validate that required columns exist in first record
            $firstRecord = reset($records);
            $requiredFields = ['firstname', 'lastname', 'email'];

            foreach ($requiredFields as $field) {
                if (! isset($firstRecord[$field]) || $firstRecord[$field] === '') {
                    throw new Exception(
                        "CSV is missing required field: '{$field}'. Required fields are: " .
                        implode(', ', $requiredFields)
                    );
                }
            }

            return $records;
        } catch (Exception $e) {
            throw new Exception('Failed to read CSV file: ' . $e->getMessage());
        }
    }

    private function createUserObject(array $record): Users
    {
        $user = new Users();
        $user->firstname = trim($record['firstname'] ?? '');
        $user->lastname = trim($record['lastname'] ?? '');
        $user->email = trim($record['email'] ?? '');

        return $user;
    }

    /**
     * Check if user already exists in the system via email
     */
    private function userExists(string $email): bool
    {
        try {
            Users::getByEmail($email);

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Process a chunk of user records
     */
    private function processChunk(
        array $chunk,
        Apps $app,
        string $emailTemplateName,
        string $emailSubject,
        ProgressBar $progressBar,
        int $delayMs,
        int &$successCount,
        int &$failCount,
        int &$skippedCount,
        int &$processedCount
    ): void {
        foreach ($chunk as $record) {
            try {
                $email = trim($record['email'] ?? '');

                // Skip if already processed (check Redis, prevents duplicates even after crashes)
                if ($this->isEmailProcessed($email)) {
                    $this->warn("Email already processed, skipping: {$email}");
                    $processedCount++;
                    $progressBar->advance();

                    continue;
                }

                // Mark as processed in Redis before attempting to send (1 week TTL)
                $this->markEmailAsProcessed($email);

                // Skip if user already exists in the system
                if ($this->userExists($email)) {
                    $skippedCount++;
                    if ($this->getOutput()->isVerbose()) {
                        $this->info('User already registered, skipping: ' . $email);
                    }
                } else {
                    // Create user object and send email
                    $user = $this->createUserObject($record);
                    $this->sendEmailToUser($user, $emailTemplateName, $emailSubject);
                    $successCount++;

                    // Only log detailed success if verbose
                    if ($this->getOutput()->isVerbose()) {
                        $this->info('Email successfully sent to: ' . $email . ' on app: ' . $app->getId());
                    }
                }
            } catch (Exception $e) {
                $failCount++;
                $this->error('Failed to send email: ' . $e->getMessage());
            }

            $processedCount++;
            $progressBar->advance();

            // Add delay between emails to avoid overwhelming the mail server
            if ($delayMs > 0) {
                usleep($delayMs * 1000); // Convert ms to microseconds
            }
        }
    }

    /**
     * Check if email has already been processed (stored in Redis hash)
     */
    private function isEmailProcessed(string $email): bool
    {
        return Redis::hexists($this->redisHashKey, $email);
    }

    /**
     * Mark email as processed in Redis hash with 1 week TTL
     */
    private function markEmailAsProcessed(string $email): void
    {
        $ttl = 7 * 24 * 60 * 60; // 1 week in seconds
        Redis::hset($this->redisHashKey, $email, time());
        Redis::expire($this->redisHashKey, $ttl);
    }

    /**
     * Send email to user using a custom template
     *
     * @param stdClass|Users $user User object (stdClass from CSV or Users model from DB)
     * @throws Exception If email sending fails
     */
    private function sendEmailToUser(stdClass|Users $user, string $emailTemplateName, string $emailSubject): void
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
}
