<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Collection;
use Kanvas\Notifications\Notification;
use Kanvas\Users\Repositories\UsersRepository;

class CompanyNameNotificationService
{
    public function __construct(
        protected CompanyInterface $company,
        protected AppInterface $app,
    ) {
    }

    /**
     * Recipients in more than one company of the app cannot tell which one a notification is about,
     * so only they get `company_name` in the template data and the subject; everyone else gets it empty.
     *
     * @param callable(string): string $subject receives " from <company>" or an empty string
     */
    public function send(
        Collection $recipients,
        Notification $notification,
        callable $subject
    ): void {
        $multiCompanyIds = UsersRepository::getMultiCompanyUserIds(
            $recipients->pluck('id')->all(),
            $this->app
        );

        foreach ($recipients as $recipient) {
            $inSeveralCompanies = in_array((int) $recipient->getId(), $multiCompanyIds, true);
            $companyName = $inSeveralCompanies ? (string) $this->company->name : '';

            $copy = clone $notification;
            $copy->setData(['company_name' => $companyName]);
            $copy->setSubject($subject($inSeveralCompanies ? ' from ' . $companyName : ''));

            $recipient->notify($copy);
        }
    }
}
