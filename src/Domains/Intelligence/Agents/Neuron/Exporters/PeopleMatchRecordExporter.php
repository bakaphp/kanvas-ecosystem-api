<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Exporters;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Neuron\Exporters\Traits\ReadsExportFilters;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\MatchesPeopleByName;
use Override;

/**
 * Cross-references a caller-supplied list of names against the contacts directory and exports the
 * verdict. Unlike every other exporter here the row SET comes from the caller, but the cells still
 * come from the DB — the model contributes names to look up, never a found/not-found answer, so the
 * file can't launder a guess as a lookup.
 *
 * One row per input name, in the order given, so the result joins straight back onto the caller's
 * own spreadsheet — which is why the ceiling here is a whole list rather than one query's worth:
 * three files of 100 rows don't join back onto anything.
 */
class PeopleMatchRecordExporter implements RecordExporterInterface
{
    use MatchesPeopleByName;
    use ReadsExportFilters;

    #[Override]
    public function type(): string
    {
        return 'people_match';
    }

    #[Override]
    public function filtersHint(): string
    {
        return sprintf(
            'names (required) — EVERY name to cross-reference in ONE call, separated by commas or new '
            . 'lines, up to %d per export; never split the list across several calls, that hands the '
            . 'user one file per batch. One row per name in the order given with Found yes/no',
            self::BULK_MAX_BATCHED_TERMS,
        );
    }

    #[Override]
    public function headers(): array
    {
        return ['Name', 'Found', 'Person ID', 'Email', 'Phone', 'Organization', 'Candidates'];
    }

    #[Override]
    public function rows(Apps $app, Companies $company, array $filters): array
    {
        $names = $this->filterString($filters, 'names');

        if ($names === null) {
            throw new ValidationException(
                'names is required to export people_match — pass every name to check in one call, '
                . 'separated by commas or new lines.',
            );
        }

        if ($this->bulkTermsExceedLimit($names, self::BULK_MAX_BATCHED_TERMS)) {
            throw new ValidationException(sprintf(
                'people_match takes at most %d names per export. Split the list into batches and export each one.',
                self::BULK_MAX_BATCHED_TERMS,
            ));
        }

        $matches = $this->matchPeopleByName(
            $app,
            $company,
            $names,
            maxTerms: self::BULK_MAX_BATCHED_TERMS,
        );

        if ($matches['searched'] === 0) {
            throw new ValidationException(
                'None of the values in names were usable — pass real names separated by commas or new lines.',
            );
        }

        return array_map(
            function (array $result): array {
                $best = $result['matches'][0] ?? null;

                return [
                    $result['query'],
                    $result['found'] ? 'Yes' : 'No',
                    $best['person_id'] ?? '',
                    $best['email'] ?? '',
                    $best['phone'] ?? '',
                    $best['organization'] ?? '',
                    count($result['matches']),
                ];
            },
            $matches['results'],
        );
    }
}
