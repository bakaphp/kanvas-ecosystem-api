<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Yusen\Actions;

use Illuminate\Support\Facades\Log;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Yusen\Notifications\YusenDiscrepancyReportNotification;
use Kanvas\Exceptions\ModelNotFoundException;
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
     * Items carried in the email. The full set can be the whole catalog on a bad night; the mail
     * is the alert, and `total_items_in_report` tells the reader how much they are not seeing.
     */
    private const int EMAILED_ITEMS = 25;

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

        $notification = new YusenDiscrepancyReportNotification($this->company, $this->mailData());

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
        $items = $this->itemsWorstFirst();

        return [
            'subject' => $this->subject(),
            'company_name' => $this->company->name,
            'file_name' => $this->report['file_name'] ?? null,
            'generated_at' => $this->report['generated_at'] ?? null,
            'total_items' => $this->report['total_items'] ?? 0,
            'total_quantity' => $this->report['total_quantity'] ?? 0,
            'total_discrepancies' => $this->report['total_discrepancies'] ?? 0,
            'multi_record_items' => $this->report['multi_record_items'] ?? 0,
            'by_type' => $this->report['by_type'] ?? [],
            'source_errors' => $this->report['source_errors'] ?? [],
            'sources' => $this->sourceKeys(),
            'total_items_in_report' => count($items),
            'items' => array_slice($items, 0, self::EMAILED_ITEMS),
        ];
    }

    /**
     * Source keys in a stable order, so the table's columns line up with the per-item cells.
     *
     * @return array<int, string>
     */
    private function sourceKeys(): array
    {
        $keys = array_keys((array) ($this->report['by_source'] ?? []));
        sort($keys);

        return $keys;
    }

    /**
     * One row per item instead of one per (item, source).
     *
     * The raw report is per-source because that is what the comparison produces, but it makes a
     * bad table: when Kanvas and NetSuite agree with each other and both disagree with Yusen, the
     * same item appears twice with identical numbers and the reader has to notice they are the
     * same SKU. Pivoted, each item is one line and the sources are columns you can scan across.
     *
     * @return array<int, array<string, mixed>>
     */
    private function itemsWorstFirst(): array
    {
        $items = [];

        foreach ((array) ($this->report['rows'] ?? []) as $row) {
            $key = (string) $row['item'];

            $items[$key] ??= [
                'item' => $key,
                'description' => null,
                'warehouse_code' => null,
                'yusen_quantity' => null,
                'by_source' => [],
                'worst' => 0.0,
            ];

            $items[$key]['description'] ??= $row['description'] ?? null;
            $items[$key]['warehouse_code'] ??= $row['warehouse_code'] ?? null;
            $items[$key]['yusen_quantity'] ??= $row['yusen_quantity'] ?? null;

            $items[$key]['by_source'][(string) $row['source']] = [
                'type' => $row['type'],
                'quantity' => $row['compared_quantity'] ?? null,
                'difference' => $row['difference'] ?? null,
            ];

            $items[$key]['worst'] = max(
                $items[$key]['worst'],
                abs((float) ($row['difference'] ?? $row['compared_quantity'] ?? $row['yusen_quantity'] ?? 0))
            );
        }

        $items = array_values($items);

        usort($items, fn (array $a, array $b): int => $b['worst'] <=> $a['worst']);

        return $items;
    }

    private function subject(): string
    {
        $count = (int) ($this->report['total_discrepancies'] ?? 0);

        return $count === 0
            ? 'Yusen inventory reconciled — no discrepancies'
            : 'Yusen inventory: ' . $count . ' discrepancies';
    }
}
