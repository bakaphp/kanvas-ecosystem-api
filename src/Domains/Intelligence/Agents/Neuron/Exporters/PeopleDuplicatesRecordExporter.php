<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Exporters;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Support\CompanyScope;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Services\PeopleDuplicateQueueService;
use Kanvas\Intelligence\Agents\Neuron\Exporters\Traits\ReadsExportFilters;
use Kanvas\Intelligence\Agents\Neuron\Tools\Concerns\PresentsPeopleDuplicateGroups;
use Override;

/**
 * One row per record, with the group's columns repeated, so the file sorts and filters in a
 * spreadsheet and every row carries the id a merge needs.
 */
class PeopleDuplicatesRecordExporter implements RecordExporterInterface
{
    use PresentsPeopleDuplicateGroups;
    use ReadsExportFilters;

    private const int DEFAULT_MAX_GROUPS = 500;
    private const int MAX_GROUPS = 2000;

    #[Override]
    public function type(): string
    {
        return 'people_duplicates';
    }

    #[Override]
    public function filtersHint(): string
    {
        return 'optional reason (' . implode(', ', self::DUPLICATE_REASONS) . '), max_groups (default '
            . self::DEFAULT_MAX_GROUPS . '), refresh=true to rescan the company first';
    }

    #[Override]
    public function headers(): array
    {
        return [
            'Group ID',
            'Reason',
            'Shared Value',
            'Recommendation',
            'Role',
            'Person ID',
            'Name',
            'Emails',
            'Phones',
            'Organizations',
            'Created At',
            'Differs From Keep',
        ];
    }

    #[Override]
    public function rows(Apps $app, Companies|CompanyScope $company, array $filters): array
    {
        $reason = $this->filterString($filters, 'reason');

        if ($reason !== null && ! in_array($reason, self::DUPLICATE_REASONS, true)) {
            throw new ValidationException('Unknown reason "' . $reason . '". Use one of: ' . implode(', ', self::DUPLICATE_REASONS) . '.');
        }

        $maxGroups = max(1, min($this->filterInt($filters, 'max_groups') ?? self::DEFAULT_MAX_GROUPS, self::MAX_GROUPS));
        $refresh = $this->filterBool($filters, 'refresh', false);

        $groups = [];
        foreach (CompanyScope::companyIdsOf($company) as $companyId) {
            $tenant = Companies::getById($companyId);
            $queue = new PeopleDuplicateQueueService($app, $tenant);

            if ($refresh) {
                $queue->detect();
            }

            $groups = [...$groups, ...$this->presentDuplicateGroups($app, $tenant, $queue->pending($maxGroups, $reason))];
        }

        usort(
            $groups,
            fn (array $a, array $b): int => [$a['recommendation'] !== self::SAFE_TO_MERGE, $a['group_id']]
                <=> [$b['recommendation'] !== self::SAFE_TO_MERGE, $b['group_id']]
        );

        $rows = [];
        foreach ($groups as $group) {
            foreach ($group['members'] as $member) {
                $rows[] = [
                    $group['group_id'],
                    $group['reason'],
                    $group['shared_value'],
                    $group['recommendation'],
                    $member['role'],
                    $member['person_id'],
                    $member['name'],
                    implode('; ', $member['emails']),
                    implode('; ', $member['phones']),
                    implode('; ', $member['organizations']),
                    $member['created_at'] ?? '',
                    implode('; ', $member['differs_from_keep']),
                ];
            }
        }

        return $rows;
    }
}
