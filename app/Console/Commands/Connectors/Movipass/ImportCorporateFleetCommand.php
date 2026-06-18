<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Movipass;

use Baka\Traits\KanvasJobsTrait;
use Bouncer;
use Illuminate\Console\Command;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Actions\ImportCorporateFleetAction;
use Kanvas\Connectors\Movipass\DataTransferObject\CorporateFleet;
use Kanvas\Users\Models\Users;
use Throwable;

class ImportCorporateFleetCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:movipass-import-corporate-fleet
        {app_id : Destination app id}
        {file : Path to the fleet JSON (absolute, or relative to the project root)}
        {--user-id= : App-owner/admin user that owns the import (required)}
        {--company= : Import into this existing company id instead of creating one}
        {--dry-run : Parse and validate the file without writing anything}';

    protected $description = 'Import a corporate fleet (company + vehicles) from a MoviPass PDF converted to JSON';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        Bouncer::scope()->to(RolesEnums::getScope($app));

        $payload = $this->readJson((string) $this->argument('file'));

        if ($payload === null) {
            return self::FAILURE;
        }

        $user = $this->resolveUser();

        if ($user === null) {
            return self::FAILURE;
        }

        auth()->setUser($user);

        $company = $this->option('company')
            ? Companies::getById((int) $this->option('company'))
            : null;

        try {
            $result = new ImportCorporateFleetAction(
                app: $app,
                user: $user,
                fleet: CorporateFleet::fromImportArray($payload),
                company: $company,
                dryRun: (bool) $this->option('dry-run'),
            )->execute();
        } catch (Throwable $e) {
            $this->error('Import failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->renderSummary($result);

        return self::SUCCESS;
    }

    private function resolveUser(): ?Users
    {
        $userId = (int) $this->option('user-id');

        if ($userId === 0) {
            $this->error('--user-id is required (an app owner or admin user id).');

            return null;
        }

        $user = Users::getById($userId);

        if (! $user->isAppOwner() && ! $user->isAdmin()) {
            $this->error("User {$userId} must be an app owner or admin to create the company and its vehicles.");

            return null;
        }

        return $user;
    }

    private function readJson(string $file): ?array
    {
        $path = is_file($file) ? $file : base_path($file);

        if (! is_file($path)) {
            $this->error('File not found: ' . $file);

            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            $this->error('Invalid JSON in file: ' . $file);

            return null;
        }

        return $decoded;
    }

    private function renderSummary(array $result): void
    {
        if ($result['dry_run']) {
            $this->info('DRY RUN — no changes written.');

            if (! empty($result['validation_error'])) {
                $this->warn('Corporate validation would fail: ' . $result['validation_error']);
            }
        }

        $companyLabel = ($result['company_name'] ?? '-')
            . (isset($result['company_id']) ? ' (' . $result['company_id'] . ')' : '');
        $this->line('Company: ' . $companyLabel);
        $this->line('Vehicles in file: ' . $result['total']);

        if ($result['dry_run']) {
            $this->line('Importable: ' . $result['importable']);
        } else {
            $this->line('Created: ' . $result['created']);
            $this->line('Updated: ' . $result['updated']);
        }

        foreach ($result['skipped'] as $skip) {
            $this->warn('Skipped ' . $skip['vehicle'] . ': ' . $skip['reason']);
        }

        if (! empty($result['duplicate_plates'])) {
            $this->warn('Duplicate plates in file: ' . implode(', ', $result['duplicate_plates']));
        }

        if (! empty($result['vehicles'])) {
            $isDryRun = $result['dry_run'];

            $rows = array_map(static function (array $vehicle) use ($isDryRun): array {
                $note = $isDryRun
                    ? (($vehicle['missing_year'] ?? false) ? 'missing year' : '')
                    : ($vehicle['action'] ?? '');

                return [
                    $vehicle['tag_number'] ?? '',
                    $vehicle['name'] ?? '',
                    $vehicle['plate'] ?? '',
                    $note,
                ];
            }, $result['vehicles']);

            $this->table(['Tag', 'Name', 'Plate', $result['dry_run'] ? 'Note' : 'Action'], $rows);
        }
    }
}
