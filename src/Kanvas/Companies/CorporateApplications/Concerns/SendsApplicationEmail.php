<?php

declare(strict_types=1);

namespace Kanvas\Companies\CorporateApplications\Concerns;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Illuminate\Support\Facades\Notification as LaravelNotification;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationEmailEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Templates\Blank;
use Throwable;

trait SendsApplicationEmail
{
    protected function sendApplicationEmail(
        AppInterface $app,
        CorporateApplicationEmailEnum $email,
        array $data,
        string|array $to,
        Lead $application
    ): bool {
        $recipients = array_values(array_filter(array_map(Str::trimToNull(...), (array) $to)));

        if ($recipients === []) {
            return false;
        }

        $notification = new Blank(
            $email->templateFor($application->receiver, $app),
            ['app' => $app] + $data,
            ['mail'],
            $application
        );

        try {
            LaravelNotification::route('mail', $recipients)->notify($notification);
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return true;
    }
}
