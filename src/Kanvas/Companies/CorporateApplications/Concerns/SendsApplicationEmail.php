<?php

declare(strict_types=1);

namespace Kanvas\Companies\CorporateApplications\Concerns;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification as LaravelNotification;
use Kanvas\Notifications\Templates\Blank;
use Throwable;

/**
 * Every email in the application flow is the same move: a Blank notification on a per-app
 * template, routed on demand to an address that is not a User yet. A failed send is reported,
 * never thrown — the application's custom fields are the source of truth, the email is not.
 */
trait SendsApplicationEmail
{
    /**
     * @param string|list<string> $to
     */
    protected function sendApplicationEmail(
        AppInterface $app,
        string $templateName,
        string $subject,
        array $data,
        string|array $to,
        ?Model $entity = null
    ): bool {
        $recipients = array_values(array_filter(array_map('trim', (array) $to)));

        if ($recipients === []) {
            return false;
        }

        $notification = new Blank($templateName, ['app' => $app] + $data, ['mail'], $entity);
        $notification->setSubject($subject);

        try {
            LaravelNotification::route('mail', $recipients)->notify($notification);
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return true;
    }
}
