<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Traits;

use Baka\Http\SafeUrlFetcher;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\AnonymousNotifiable;
use Kanvas\Apps\Support\SmtpRuntimeConfiguration;
use Kanvas\Notifications\KanvasMailable;

trait NotificationMailTrait
{
    public function toMail(object $notifiable): Mailable
    {
        $smtpConfig = new SmtpRuntimeConfiguration($this->app, $this->company);
        $mailConfig = $smtpConfig->loadSmtpSettings();
        $fromMail = $smtpConfig->getFromEmail();

        $toEmail = $this->resolveRecipientEmail($notifiable);

        $mailMessage = new KanvasMailable($mailConfig, $this->getEmailContent())
            ->from($fromMail['address'], $fromMail['name'])
            ->to($toEmail)
            ->subject($this->subject ?? $this->getNotificationTitle() ?? $this->app->name . ' Notification');

        $this->applyAttachments($mailMessage);

        return $mailMessage;
    }

    /**
     * Attach every entry of $pathAttachment to the outgoing mail.
     *
     * An http(s) entry is a remote file (e.g. an uploaded Kanvas Filesystem URL passed
     * through the anonymous-notification mutation): its bytes are fetched here — at
     * render time on the queue worker, so nothing binary is serialized into the job —
     * through the SSRF guard and attached inline. Any other entry is treated as a path
     * already readable on this machine and attached directly. Both the plain-list form
     * (`['a.pdf', 'b.pdf']`) and the keyed form (`['a.pdf' => ['as' => ..., 'mime' => ...]]`)
     * are supported.
     */
    protected function applyAttachments(KanvasMailable $mailMessage): void
    {
        if (empty($this->pathAttachment)) {
            return;
        }

        foreach ($this->pathAttachment as $key => $value) {
            [$source, $options] = is_int($key) ? [$value, []] : [$key, (array) $value];

            if (! is_string($source)) {
                continue;
            }

            if ($this->isRemoteAttachment($source)) {
                $bytes = SafeUrlFetcher::fetch($source);

                $mailMessage->attachData(
                    $bytes,
                    $options['as'] ?? $this->attachmentNameFromUrl($source),
                    array_filter(['mime' => $options['mime'] ?? $this->detectAttachmentMime($bytes)])
                );

                continue;
            }

            $mailMessage->attach($source, $options);
        }
    }

    private function isRemoteAttachment(string $source): bool
    {
        $source = strtolower($source);

        return str_starts_with($source, 'http://') || str_starts_with($source, 'https://');
    }

    private function attachmentNameFromUrl(string $url): string
    {
        $name = basename(parse_url($url, PHP_URL_PATH) ?: '');

        return $name !== '' ? $name : 'attachment';
    }

    private function detectAttachmentMime(string $bytes): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_buffer($finfo, $bytes) : false;

        if ($finfo) {
            finfo_close($finfo);
        }

        return $mime ?: 'application/octet-stream';
    }

    private function resolveRecipientEmail(object $notifiable): array|string
    {
        $primaryEmail = $notifiable instanceof AnonymousNotifiable
            ? $notifiable->routes['mail']
            : $notifiable->email;

        if (method_exists($notifiable, 'getAlternativeEmail') && $notifiable->getAlternativeEmail()) {
            return [$primaryEmail, $notifiable->getAlternativeEmail()];
        }

        return $primaryEmail;
    }
}
