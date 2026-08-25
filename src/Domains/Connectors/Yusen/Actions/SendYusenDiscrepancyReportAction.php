<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Yusen\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException as EloquentModelNotFoundException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Yusen\Services\YusenSettings;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

/**
 * Mails the discrepancy report to the people who asked for it.
 *
 * Without this the report is computed and then only reachable by reading
 * `receiver_webhook_calls.results` out of the database, which is not a report anybody receives.
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
        $recipients = new YusenSettings($this->app, $this->company)->reportUsers();

        if ($recipients === []) {
            return [];
        }

        $data = [
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

        $notified = [];

        foreach ($recipients as $userId) {
            try {
                $user = Users::getById((int) $userId);
                UsersRepository::belongsToThisApp($user, $this->app, $this->company);

                $user->notify(
                    new Blank(
                        self::TEMPLATE,
                        $data,
                        ['mail'],
                        $user,
                    )
                );

                $notified[] = $user->getId();
            } catch (ModelNotFoundException | EloquentModelNotFoundException) {
                // A recipient removed from the company shouldn't stop the rest of the list.
                //
                // Both types are caught because `Users::getById()` means to convert Eloquent's
                // into Kanvas's but doesn't — its catch names the Kanvas class while
                // `firstOrFail()` throws Eloquent's, so the raw one escapes.
                continue;
            }
        }

        return $notified;
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
