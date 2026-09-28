<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Intras;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Intras\Actions\InspectParticipantFieldsFromIntrasAction;
use Kanvas\Connectors\Intras\Mappers\ParticipantMapper;
use Throwable;

class InspectParticipantFieldsFromIntrasCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:intras-inspect-participant-fields
                            {app_id : The application ID}';

    protected $description = 'Dump the columns, custom-field names and lookup catalogs behind Intras participants, so profile fields (nivel, area) are mapped against what an install really has';

    public function handle(): void
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        try {
            $result = new InspectParticipantFieldsFromIntrasAction($app)->execute();
        } catch (Throwable $e) {
            $this->error("Inspection failed: {$e->getMessage()}");

            return;
        }

        $this->info('participants columns');
        $this->table(['column', 'type'], $result['columns']);

        $this->info('participants_custom_fields names (non-empty values only)');
        $this->table(['name', 'filled'], $result['custom_fields']);

        foreach ($result['lookups'] as $table => $catalog) {
            $this->info("{$table} (first 50)");

            if ($catalog['error'] !== null) {
                $this->error("  unreadable — {$catalog['error']}");

                continue;
            }

            $this->table(['id', 'name'], $catalog['rows']);
        }

        $columnNames = array_column($result['columns'], 'name');

        $this->info('Profile FKs recognised by ParticipantMapper');
        foreach (ParticipantMapper::LOOKUP_FIELD_MAP as $column => $spec) {
            $catalog = $result['lookups'][$spec['table']] ?? ['rows' => [], 'error' => 'not inspected'];
            $named = array_filter($catalog['rows'], fn (array $row) => $row['name'] !== '');

            $status = match (true) {
                ! in_array($column, $columnNames, true) => "MISSING — no {$column} column on participants",
                $catalog['error'] !== null => "MISSING — catalog {$spec['table']} unreadable",
                $catalog['rows'] === [] => "MISSING — catalog {$spec['table']} is empty",
                $named === [] => "UNUSABLE — {$spec['table']} rows have no name column, nothing to store",
                default => "ok — {$spec['table']} resolves to custom field '{$spec['custom_field']}'",
            };

            $this->line("  {$column}: {$status}");
        }
    }
}
