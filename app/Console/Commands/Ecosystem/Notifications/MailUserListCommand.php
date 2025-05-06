<?php

declare(strict_types=1);

namespace App\Console\Commands\Ecosystem\Notifications;

use Baka\Traits\KanvasJobsTrait;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;
use League\Csv\Reader;

class MailUserListCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:mail-user-list-template 
                            {apps_id : The ID of the application} 
                            {email_template_name : The name of the email template to use} 
                            {subject : The subject of the email} 
                            {csv : Path to the CSV file containing recipient emails}
                            {--field=email : The CSV field containing email addresses}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send specific email to recipients from a CSV file';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('apps_id'));
        $this->overwriteAppService($app);
        $emailTemplateName = $this->argument('email_template_name');
        $emailSubject = $this->argument('subject');

        $csvPath = $this->argument('csv');

        if (File::exists($csvPath)) {
            $this->info('Reading recipients from CSV file: ' . $csvPath);
            $this->sendEmailsFromCsv($csvPath, $emailTemplateName, $emailSubject);
        } else {
            $this->error('CSV file not found at path: ' . $csvPath);

            return 1;
        }

        $this->info('Email sending process completed.');
    }

    /**
     * Send emails to recipients listed in a CSV file
     */
    private function sendEmailsFromCsv(string $csvPath, string $emailTemplateName, string $emailSubject): void
    {
        $csv = Reader::createFromPath($csvPath, 'r');
        $csv->setHeaderOffset(0);

        $emailField = $this->option('field');
        $nameField = 'name';
        $totalProcessed = 0;
        $successCount = 0;
        $failCount = 0;

        $this->output->progressStart(count($csv));

        foreach ($csv as $record) {
            if (! isset($record[$emailField])) {
                $this->error("CSV is missing the '{$emailField}' field. Aborting.");

                return;
            }

            $email = trim($record[$emailField]);
            $name = $record[$nameField] ?? null;

            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                try {
                    $user = new Users();
                    $user->email = $email;
                    $user->firstname = $name ?? '';

                    $this->sendEmailToUser($user, $emailTemplateName, $emailSubject);
                    $successCount++;
                } catch (Exception $e) {
                    $this->error("Failed to send email to {$email}: " . $e->getMessage());
                    $failCount++;
                }
            } else {
                $this->warn("Invalid email: {$email}");
                $failCount++;
            }

            $totalProcessed++;
            $this->output->progressAdvance();
        }

        $this->output->progressFinish();

        $this->info("Email sending complete. Processed: {$totalProcessed}, Success: {$successCount}, Failed: {$failCount}");
    }

    /**
     * Send email to user using a custom template
     */
    private function sendEmailToUser(Users $user, string $emailTemplateName, string $emailSubject): void
    {
        $notification = new Blank(
            $emailTemplateName,
            ['user' => $user],
            ['mail'],
            $user
        );

        $notification->setSubject($emailSubject);
        Notification::route('mail', $user->email)->notify($notification);
    }
}
