<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Notifications;

use Kanvas\Intelligence\AgentRuntime\Notifications\AbstractTemplatedNotification;
use Kanvas\NervousSystem\Plan\Models\Plan;

/**
 * Mail-only alert to whoever owns the roadmap that an agent was asked for something it has no tool
 * for. The Plan is the durable record; this is what gets a human to open it.
 *
 * The Plan is the entity anchor — the Kanvas Notification base resolves `$this->app` from it.
 */
class CapabilityGapReportedNotification extends AbstractTemplatedNotification
{
    public const string TEMPLATE_NAME = 'capability_gap_reported';

    /**
     * @param array<string, mixed> $details Flattened, already-rendered values for the template — built
     *        by `CapabilityGapAlertService::details()` before the notification is queued, so the
     *        transcript lookup runs once and nothing heavier than strings is serialized.
     */
    public function __construct(Plan $plan, array $details)
    {
        parent::__construct($plan, [
            'company' => $plan->company,
        ]);

        $count = (int) ($details['request_count'] ?? 1);

        $this->setSubject(sprintf(
            'Capability gap: %s — %s (%s)%s',
            (string) ($details['topic'] ?? $plan->title),
            (string) ($details['company_name'] ?? ''),
            (string) ($details['app_name'] ?? ''),
            $count > 1 ? sprintf(' · asked %d times', $count) : '',
        ));

        $this->setData($details);
    }
}
