<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Imports\Actions;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Integrations\Imports\DataTransferObject\Importable;
use Kanvas\Workflow\Integrations\Imports\DataTransferObject\ImportOption;
use Kanvas\Workflow\Integrations\Imports\Enums\ImportOptionTypeEnum;
use Kanvas\Workflow\Integrations\Imports\Enums\IntegrationImportStatusEnum;
use Kanvas\Workflow\Integrations\Imports\Jobs\RunIntegrationImportJob;
use Kanvas\Workflow\Integrations\Imports\Models\IntegrationImport;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;

class StartIntegrationImportAction
{
    public function __construct(
        protected IntegrationsCompany $integrationCompany,
        protected Users $user,
        protected string $entity,
        protected array $options = [],
    ) {
    }

    public function execute(): IntegrationImport
    {
        $this->integrationCompany->assertImportable();

        $importable = $this->integrationCompany->importableOrFail($this->entity);

        $this->options = array_filter(
            $this->options,
            fn (mixed $value) => $value !== null && $value !== '' && $value !== []
        );

        $this->validateOptions($importable);

        $options = $this->integrationCompany
            ->importerFor($this->entity, $this->user)
            ->prepare($this->options);

        $lock = Cache::lock(
            sprintf(
                'integration-import-start:%d:%d:%s',
                $this->integrationCompany->companies_id,
                $this->integrationCompany->integrations_id,
                $this->entity
            ),
            10
        );

        try {
            $run = $lock->block(5, function () use ($options) {
                if (IntegrationImport::inProgressFor($this->integrationCompany, $this->entity) !== null) {
                    throw $this->alreadyInProgress();
                }

                return IntegrationImport::create([
                    'apps_id' => $this->integrationCompany->apps_id ?? app(Apps::class)->getId(),
                    'companies_id' => $this->integrationCompany->companies_id,
                    'integrations_id' => $this->integrationCompany->integrations_id,
                    'integrations_companies_id' => $this->integrationCompany->getId(),
                    'users_id' => $this->user->getId(),
                    'entity' => $this->entity,
                    'status' => IntegrationImportStatusEnum::QUEUED,
                    'options' => $options,
                    'queued_at' => Carbon::now(),
                ]);
            });
        } catch (LockTimeoutException) {
            throw $this->alreadyInProgress();
        }

        RunIntegrationImportJob::dispatch($run);

        return $run;
    }

    protected function alreadyInProgress(): ValidationException
    {
        return new ValidationException(sprintf('A %s import is already in progress for this integration.', $this->entity));
    }

    protected function validateOptions(Importable $importable): void
    {
        $declared = [];
        foreach ($importable->options as $option) {
            $declared[$option->name] = $option;
        }

        foreach (array_keys($this->options) as $name) {
            if (! isset($declared[$name])) {
                throw new ValidationException(sprintf('%s does not take the option %s.', $this->entity, $name));
            }
        }

        foreach ($declared as $name => $option) {
            if (! isset($this->options[$name])) {
                if ($option->required) {
                    throw new ValidationException(sprintf('%s needs the option %s.', $this->entity, $name));
                }

                continue;
            }

            $this->assertType($option, $this->options[$name]);
        }
    }

    protected function assertType(ImportOption $option, mixed $value): void
    {
        $valid = match (true) {
            $option->multiple => is_array($value) && array_is_list($value) && array_all($value, fn (mixed $entry) => is_scalar($entry)),
            $option->type === ImportOptionTypeEnum::BOOLEAN => is_bool($value),
            $option->type === ImportOptionTypeEnum::STRING => is_string($value),
            default => is_scalar($value),
        };

        if ($valid) {
            return;
        }

        $expected = match (true) {
            $option->multiple => 'a list of choices',
            $option->type === ImportOptionTypeEnum::BOOLEAN => 'boolean',
            $option->type === ImportOptionTypeEnum::STRING => 'a string',
            default => 'a choice',
        };

        throw new ValidationException(sprintf('The option %s must be %s.', $option->name, $expected));
    }
}
