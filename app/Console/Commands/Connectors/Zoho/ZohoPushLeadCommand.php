<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Zoho;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Connectors\Zoho\DataTransferObject\ZohoLead;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\Workflows\ZohoLeadActivity;
use Kanvas\Guild\Leads\Models\Lead;
use Throwable;
use Workflow\Models\StoredWorkflow;

/**
 * Re-push leads Zoho rejected (INVALID_DATA on a field type, a bad Owner, a transient 5xx). Runs the
 * same activity the workflow runs, so the attempt lands in the integration history like any other.
 */
class ZohoPushLeadCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:zoho-push-lead
                            {leads : Lead id(s) or uuid(s), comma separated}
                            {--dry-run : Show the payload that would be sent, without calling Zoho}
                            {--force : Drop the stored ZOHO_LEAD_ID first so the lead is created again}';

    protected $description = 'Push specific leads to Zoho CRM again';

    public function handle(): int
    {
        $failed = 0;

        foreach (explode(',', (string) $this->argument('leads')) as $identifier) {
            $identifier = trim($identifier);

            if ($identifier === '') {
                continue;
            }

            if (! $this->pushLead($identifier)) {
                $failed++;
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function pushLead(string $identifier): bool
    {
        try {
            /** @var Lead $lead */
            $lead = is_numeric($identifier)
                ? Lead::getById((int) $identifier)
                : Lead::getByUuid($identifier);
        } catch (Throwable $e) {
            $this->error('Lead ' . $identifier . ': ' . $e->getMessage());

            return false;
        }

        $app = $lead->app;
        $this->overwriteAppService($app);

        $this->line('Lead ' . $lead->getId() . ' — ' . $lead->company->name . ' (app ' . $app->getId() . ')');

        if ($this->option('dry-run')) {
            $this->line(json_encode(ZohoLead::fromLead($lead)->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return true;
        }

        if ($this->option('force')) {
            $lead->del(CustomFieldEnum::ZOHO_LEAD_ID->value);
        }

        $activity = new ZohoLeadActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $result = $activity->execute($lead, $app, []);
        $zohoLeadId = $result['zohoLeadId'] ?? null;

        if (empty($zohoLeadId)) {
            $this->error('  not pushed: ' . ($result['message'] ?? $result['error'] ?? 'no Zoho lead id returned'));

            return false;
        }

        $this->info('  pushed as Zoho lead ' . $zohoLeadId);

        return true;
    }
}
