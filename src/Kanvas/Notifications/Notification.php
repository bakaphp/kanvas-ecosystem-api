<?php
namespace Kanvas\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification as LaravelNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Kanvas\Users\Users\Models\Users;
use Kanvas\Templates\Models\Templates;
use Illuminate\Support\Facades\Blade;
use Kanvas\Notifications\Interfaces\Email;

class Notification extends LaravelNotification implements ShouldQueue, Email
{
    use Queueable;

    /**
    * Get the mail representation of the notification.
    *
    * @param  mixed  $notifiable
    * @return \Illuminate\Notifications\Messages\MailMessage
    */
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
                ->from('barrett@example.com', 'Barrett Blair')
                ->view('emails.layout', ['html' => $this->generateHtml()]);
    }

    /**
     * generateHtml
     *
     * @return string
     */
    public function generateHtml(): string
    {
        $template = new Templates();
        $html = Blade::render($template->getByName($this->templateName)->template, $this->getDataMail());
        return $html;
    }

    /**
     * getDataMail
     *
     * @return array
     */
    public function getDataMail(): array
    {
        return [
        ];
    }
}
