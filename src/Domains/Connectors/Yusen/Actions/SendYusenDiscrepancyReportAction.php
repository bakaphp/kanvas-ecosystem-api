<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Yusen\Actions;

use Illuminate\Support\Facades\Log;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

/**
 * Mails the discrepancy report to the company's managers.
 *
 * Without this the report is computed and then only reachable by reading
 * `receiver_webhook_calls.results` out of the database, which is not a report anybody receives.
 *
 * Recipients come from the Managers role rather than a per-company list of user ids: a custom
 * field goes stale the moment somebody joins or leaves, and nobody remembers to update it.
 */
class SendYusenDiscrepancyReportAction
{
    /**
     * Rows carried in the email. The full set can be the whole catalog on a bad night; the mail is
     * the alert, and `total_discrepancies` tells the reader how much they are not seeing.
     */
    private const int EMAILED_ROWS = 25;

    private const string TEMPLATE = 'yusen-discrepancy-report';

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly array $report,
    ) {
    }

    /**
     * @return array<int, int> ids of the users actually notified
     */
    public function execute(): array
    {
        $recipients = $this->managers();

        if ($recipients === []) {
            return [];
        }

        $notification = new Blank(
            self::TEMPLATE,
            $this->mailData(),
            ['mail'],
            $this->company,
        );

        $notification->setSubject($this->subject());

        $notified = [];

        foreach ($recipients as $manager) {
            $manager->notify($notification);
            $notified[] = $manager->getId();
        }

        return $notified;
    }

    /**
     * @return array<int, Users>
     */
    private function managers(): array
    {
        try {
            return UsersRepository::getCompanyAppUserByRole(
                $this->company,
                $this->app,
                RolesEnums::MANAGER->value,
            )
                ->notDeleted()
                ->whereNotNull('users.email')
                ->where('users.email', '!=', '')
                ->get()
                ->all();
        } catch (ModelNotFoundException) {
            // The role isn't bootstrapped for this app. Not a fault worth reporting — the company
            // simply has nobody to tell yet, and the report still lands on the webhook call.
            Log::info('Yusen.DiscrepancyReport — Managers role not set up for this app, nobody notified', [
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
            ]);

            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function mailData(): array
    {
        return [
            'subject' => $this->subject(),
            'company' => $this->company->name,
            'file_name' => $this->report['file_name'] ?? null,
            'generated_at' => $this->report['generated_at'] ?? null,
            'total_items' => $this->report['total_items'] ?? 0,
            'total_quantity' => $this->report['total_quantity'] ?? 0,
            'total_discrepancies' => $this->report['total_discrepancies'] ?? 0,
            'multi_record_items' => $this->report['multi_record_items'] ?? 0,
            'by_source' => $this->report['by_source'] ?? [],
            'by_type' => $this->report['by_type'] ?? [],
            'source_errors' => $this->report['source_errors'] ?? [],
            'rows' => $this->worstRows(),
        ];
    }

    private function subject(): string
    {
        $count = (int) ($this->report['total_discrepancies'] ?? 0);

        return $count === 0
            ? 'Yusen inventory reconciled — no discrepancies'
            : 'Yusen inventory: ' . $count . ' discrepancies';
    }

    /**
     * Biggest absolute gaps first, so the mail leads with the units that matter rather than
     * whatever the catalog happened to list first.
     *
     * @return array<array-key, mixed>
     */
    private function worstRows(): array
    {
        /** @var array<array-key, array<string, mixed>> $rows */
        $rows = $this->report['rows'] ?? [];

        usort(
            $rows,
            fn (array $a, array $b): int => abs((float) ($b['difference'] ?? $b['compared_quantity'] ?? $b['yusen_quantity'] ?? 0))
                <=> abs((float) ($a['difference'] ?? $a['compared_quantity'] ?? $a['yusen_quantity'] ?? 0))
        );

        return array_slice($rows, 0, self::EMAILED_ROWS);
    }
}
