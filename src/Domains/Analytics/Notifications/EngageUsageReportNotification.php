<?php

declare(strict_types=1);

namespace Kanvas\Analytics\Notifications;

use Illuminate\Support\Facades\View;
use Kanvas\Companies\Models\Companies;
use Kanvas\Notifications\Notification;
use Override;

/**
 * Mail-only weekly Engage usage report: per-rep leaderboard plus team totals, sent to each
 * Managers role member.
 *
 * The Companies model is the entity anchor — the Kanvas Notification base resolves $this->app
 * from $entity->app via KanvasModelTrait.
 */
class EngageUsageReportNotification extends Notification
{
    private const string VIEW = 'emails.analytics.engage-usage-report';

    public array $channels = ['mail'];

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $team
     * @param  array<string, string>  $range  {from, to, label, channel_label}
     */
    public function __construct(
        Companies $company,
        array $rows,
        array $team,
        array $range,
    ) {
        parent::__construct($company, [
            'company' => $company,
        ]);

        $this->setSubject(sprintf(
            '%s — Engage usage for %s',
            $company->name,
            $range['label'],
        ));

        $this->setData([
            'company_name' => $company->name,
            'range_label' => $range['label'],
            'from' => $range['from'],
            'to' => $range['to'],
            'channel_label' => $range['channel_label'],
            'rows' => $rows,
            'team' => $team,
        ]);
    }

    #[Override]
    public function getEmailContent(): string
    {
        return View::make(self::VIEW, $this->getData())->render();
    }
}
