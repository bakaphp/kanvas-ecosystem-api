<?php

declare(strict_types=1);

namespace App\GraphQL\Workflow\Queries\Integrations;

use App\GraphQL\Workflow\Concerns\ResolvesImportableIntegration;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Integrations\Imports\DataTransferObject\ImportChoice;
use Kanvas\Workflow\Integrations\Imports\Enums\ImportOptionTypeEnum;
use Kanvas\Workflow\Integrations\Imports\Models\IntegrationImport;

class IntegrationImportQuery
{
    use ResolvesImportableIntegration;

    public function status(mixed $rootValue, array $request): ?IntegrationImport
    {
        return IntegrationImport::latestFor(
            $this->importableIntegration((int) $request['integration_company_id']),
            $request['entity']
        );
    }

    /**
     * @return list<array{value: string, label: string, suggested: bool}>
     */
    public function choices(mixed $rootValue, array $request): array
    {
        $integrationCompany = $this->importableIntegration((int) $request['integration_company_id']);
        $integrationCompany->assertImportable();
        $entity = $request['entity'];
        $option = $request['option'];

        $importable = $integrationCompany->importableFor($entity)
            ?? throw new ValidationException(sprintf('The integration does not import %s.', $entity));
        $declared = array_find(
            $importable->options,
            fn ($candidate) => $candidate->name === $option && $candidate->type === ImportOptionTypeEnum::CHOICES
        ) ?? throw new ValidationException(sprintf('The option %s has no choices.', $option));

        return array_map(
            fn (ImportChoice $choice) => [
                'value' => $choice->value,
                'label' => $choice->label,
                'suggested' => $choice->suggested,
            ],
            $integrationCompany->importerFor($entity, auth()->user())->choices($declared->name)
        );
    }
}
