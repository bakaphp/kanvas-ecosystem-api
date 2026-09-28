<?php

declare(strict_types=1);

namespace Kanvas\Companies\CorporateApplications\Concerns;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification as LaravelNotification;
use Kanvas\Notifications\Templates\Blank;
use Throwable;

trait SendsApplicationEmail
{
    protected function sendApplicationEmail(
        AppInterface $app,
        string $templateName,
        string $subject,
        array $data,
        string|array $to,
        Model $entity
    ): bool {
        $recipients = array_values(array_filter(array_map(Str::trimToNull(...), (array) $to)));

        if ($recipients === []) {
            return false;
        }

        $notification = new Blank(
            $templateName,
            ['app' => $app] + $data,
            ['mail'],
            $entity
        );
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
