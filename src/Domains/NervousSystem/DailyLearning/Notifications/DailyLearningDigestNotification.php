<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\DailyLearning\Notifications;

use Illuminate\Support\Facades\View;
use Kanvas\Companies\Models\Companies;
use Kanvas\Notifications\Notification;
use Override;

/**
 * Mail-only digest: per-company recap of yesterday's daily-learning cycles
 * across all agents, sent to each `AgentReport` role member.
 *
 * The notification is queued (Notification implements ShouldQueue), so the
 * fan-out action dispatches one of these per recipient — see
 * SendDailyLearningDigestAction. Per the multi-channel base class, channels
 * are slugs; we lock to `mail` only.
 *
 * The Companies model is the entity anchor — Kanvas Notification base
 * resolves $this->app from $entity->app via KanvasModelTrait.
 */
class DailyLearningDigestNotification extends Notification
{
    private const string VIEW = 'emails.nervous-system.daily-learning-digest';

    public array $channels = ['mail'];

    /**
     * @param array<int, array<string, mixed>> $agents per-agent summary rows for the digest
     */
    public function __construct(
        Companies $company,
        string $cycleDate,
        array $agents,
    ) {
        parent::__construct($company, [
            'company' => $company,
        ]);

        $this->setSubject(sprintf(
            '%s — daily agent learning for %s',
            $company->name,
            $cycleDate,
        ));

        $this->setData([
            'company_name' => $company->name,
            'cycle_date' => $cycleDate,
            'agents' => $agents,
        ]);
    }

    #[Override]
    public function getEmailContent(): string
    {
        return View::make(self::VIEW, $this->getData())->render();
    }
}
