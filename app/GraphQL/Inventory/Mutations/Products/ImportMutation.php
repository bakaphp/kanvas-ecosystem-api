<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Products;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Imports\Actions\WriteImporterArrayToJsonlFileAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Importer\Jobs\ProductImporterJob as ImporterJob;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Regions\Repositories\RegionRepository;
use Kanvas\Regions\Models\Regions as BaseRegions;

class ImportMutation
{
    /**
     * importer.
     *
     * @param  mixed $req
     */
    public function product(mixed $root, array $req): string
    {
        $company = Companies::getById($req['companyId']);

        CompaniesRepository::userAssociatedToCompany(
            $company,
            auth()->user()
        );

        /** @var BaseRegions $region */
        $region = ! isset($req['regionId']) ? Regions::getDefault($company) : RegionRepository::getById($req['regionId'], $company);

        if (empty($req['input']) || ! is_array($req['input'])) {
            // Use ValidationException (client-safe / ClientAware) instead of a plain
            // InvalidArgumentException so the GraphQL error handler treats this as
            // client input misuse: the caller still gets the error message, but it
            // is not forwarded to Sentry as noise.
            throw new ValidationException('Input array cannot be empty.');
        }

        //verify it has the correct format
        ProductImporter::from($req['input'][0]);

        $user = auth()->user();
        $app = app(Apps::class);
        $branch = $company->branch;

        // Spool the inline payload to a JSONL file on the filesystem so the
        // job runs from a streamed file instead of carrying the whole array
        // through the queue. This is the fix for KANVAS-ECOSYSTEM-4XV — large
        // payloads no longer OOM the worker, peak memory stays flat.
        $filesystemImport = new WriteImporterArrayToJsonlFileAction(
            $req['input'],
            $app,
            $company,
            $user,
            $branch,
            $region,
        )->execute();

        //so we can tie the job to pusher
        $jobUuid = Str::uuid()->toString();
        ImporterJob::dispatch(
            $jobUuid,
            [],
            $branch,
            $user,
            $region,
            $app,
            $filesystemImport,
        );

        return $jobUuid;
    }
}
