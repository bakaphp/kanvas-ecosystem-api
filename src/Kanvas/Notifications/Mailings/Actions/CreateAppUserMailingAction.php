<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Mailings\Actions;

use Kanvas\Enums\AppEnums;
use Kanvas\Notifications\Mailings\DataTransferObject\AppUserMailing as AppUserMailingData;
use Kanvas\Notifications\Mailings\Enums\AppUserMailingStatusEnum;
use Kanvas\Notifications\Mailings\Jobs\SendAppUsersEmailJob;
use Kanvas\Notifications\Mailings\Models\AppUserMailing;

class CreateAppUserMailingAction
{
    public function __construct(
        private readonly AppUserMailingData $data,
    ) {
    }

    public function execute(): AppUserMailing
    {
        $mailing = new AppUserMailing();
        $mailing->apps_id = $this->data->app->getId();
        $mailing->companies_id = $this->data->company?->getId() ?? AppEnums::GLOBAL_COMPANY_ID->getValue();
        $mailing->users_id = $this->data->requester?->getId();
        $mailing->agents_id = $this->data->agentId;
        $mailing->audience = $this->data->audience;
        $mailing->template_name = $this->data->templateName;
        $mailing->subject = $this->data->subject;
        $mailing->criteria = $this->data->criteria;
        $mailing->status = $this->data->scheduledAt === null
            ? AppUserMailingStatusEnum::QUEUED
            : AppUserMailingStatusEnum::SCHEDULED;
        $mailing->scheduled_at = $this->data->scheduledAt;
        $mailing->confirmed_total = $this->data->confirmedTotal;
        $mailing->total_eligible = count($this->data->recipientIds);
        $mailing->total_excluded = array_sum($this->data->excludedBreakdown);
        $mailing->excluded_breakdown = $this->data->excludedBreakdown;
        $mailing->recipient_ids = array_values($this->data->recipientIds);
        $mailing->saveOrFail();

        if ($this->data->scheduledAt === null) {
            SendAppUsersEmailJob::dispatch($this->data->app, $mailing);
        }

        return $mailing;
    }
}
