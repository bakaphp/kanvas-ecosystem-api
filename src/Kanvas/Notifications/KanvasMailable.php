<?php

declare(strict_types=1);

namespace Kanvas\Notifications;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Support\Facades\Mail;

/**
 * we create a custom Mailable class to set the mailer configuration
 * at runtime, this is useful when we need to send emails using different
 * mailer configurations.
 */
class KanvasMailable extends Mailable
{
    public function __construct(
        protected array $mailerConfig,
        protected string $emailContent
    ) {
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.layout',
            with: [
                'html' => $this->emailContent,
            ],
        );
    }

    public function build(): self
    {
        if (app()->environment('testing')) {
            // Skip setting the custom mailer configuration in testing environment
            //return $this;
        }

        //thanks to https://github.com/laravel/framework/issues/42602#issuecomment-1143637921
        $customConfig = Mail::createSymfonyTransport($this->mailerConfig);
        Mail::setSymfonyTransport($customConfig);

        return $this;
    }
}
