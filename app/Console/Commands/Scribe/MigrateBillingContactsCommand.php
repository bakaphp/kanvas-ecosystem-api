<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\CustomFields\Models\AppsCustomFields;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationPeople;
use Kanvas\Scribe\Delivery\Services\BillingContactResolverService;
use Kanvas\SystemModules\Models\SystemModules;

/**
 * One-time move of the organization's `billing_contact_people_id` custom field to the `billing-contact`
 * people tag (plus the organization link). Idempotent; the custom field rows are left in place.
 */
class MigrateBillingContactsCommand extends Command
{
    use KanvasJobsTrait;

    private const string LEGACY_FIELD = 'billing_contact_people_id';

    protected $signature = 'scribe:migrate-billing-contacts
                            {--app= : Only this app id}
                            {--company= : Only this company id}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Tag every person named in an organization billing_contact_people_id custom field as billing-contact';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $appId = $this->option('app') !== null ? (int) $this->option('app') : null;
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
        $resolver = new BillingContactResolverService();
        $counts = ['rows' => 0, 'migrated' => 0, 'already' => 0, 'missing' => 0];

        $rows = AppsCustomFields::query()
            ->whereIn('model_name', [Organization::class, SystemModules::getLegacyNamespace(Organization::class)])
            ->where('name', self::LEGACY_FIELD)
            ->where('is_deleted', 0)
            ->when($companyId !== null, fn ($query) => $query->where('companies_id', $companyId))
            ->get(['entity_id', 'value'])
            ->mapWithKeys(fn (AppsCustomFields $row): array => [(int) $row->entity_id => $row->value]);

        $organizations = Organization::query()
            ->whereIn('id', $rows->keys()->all())
            ->when($appId !== null, fn ($query) => $query->where('apps_id', $appId))
            ->when($companyId !== null, fn ($query) => $query->where('companies_id', $companyId))
            ->where('is_deleted', 0)
            ->get();

        foreach ($organizations->groupBy('apps_id') as $orgAppId => $appOrganizations) {
            $app = Apps::query()->find((int) $orgAppId);
            if ($app === null) {
                continue;
            }
            $this->overwriteAppService($app);

            foreach ($appOrganizations as $organization) {
                /** @var Organization $organization */
                $counts['rows']++;
                $value = $rows->get($organization->getId());

                $person = is_numeric($value) ? People::query()
                    ->where('id', (int) $value)
                    ->where('companies_id', $organization->companies_id)
                    ->where('is_deleted', 0)
                    ->first() : null;

                if ($person === null) {
                    $counts['missing']++;

                    continue;
                }

                $linked = OrganizationPeople::query()
                    ->where('organizations_id', $organization->getId())
                    ->where('peoples_id', $person->getId())
                    ->exists();

                if ($linked && $resolver->isBillingContact($person)) {
                    $counts['already']++;

                    continue;
                }

                $counts['migrated']++;
                if (! $dryRun) {
                    $resolver->markBillingContact($person, $organization);
                }
            }
        }

        $this->info(sprintf(
            '%sorganizations with the field: %d, tagged + linked: %d, already done: %d, person missing: %d',
            $dryRun ? '[dry-run] ' : '',
            $counts['rows'],
            $counts['migrated'],
            $counts['already'],
            $counts['missing'],
        ));

        return self::SUCCESS;
    }
}
