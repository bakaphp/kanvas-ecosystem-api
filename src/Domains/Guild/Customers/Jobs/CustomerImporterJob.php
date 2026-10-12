<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Jobs;

use Illuminate\Support\Facades\Auth;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Events\Events\ImportResultEvents;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Participants\Actions\SyncPeopleWithParticipantAction;
use Kanvas\Guild\Customers\Actions\ImportPeopleRowsAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Imports\AbstractImporterJob;
use Override;

class CustomerImporterJob extends AbstractImporterJob
{
    #[Override]
    public function handle(): void
    {
        Auth::loginUsingId($this->user->getId());
        $this->overwriteAppService($this->app);
        $this->overwriteAppServiceLocation($this->branch);

        $this->startFilesystemMapperImport();

        $company = $this->branch->company()->firstOrFail();

        $result = new ImportPeopleRowsAction($this->app, $this->branch, $this->user)->execute(
            iterator_to_array($this->iterateImporterRows()),
            function (People $peopleModel, array $customerData) use ($company): void {
                if (key_exists('event_version_id', $customerData)) {
                    $eventVersion = EventVersion::getByIdFromCompanyApp(
                        $customerData['event_version_id']['id'],
                        $company,
                        $this->app
                    );
                    $sync = new SyncPeopleWithParticipantAction(
                        $peopleModel,
                        $this->user,
                    );
                    $participant = $sync->execute();
                    $eventVersion->addParticipant($participant);
                }
            },
        );

        $created = $result['created'];
        $updated = $result['updated'];
        $errors = $result['errors'];
        $totalProcessSuccessfully = $created + $updated;
        $totalProcessFailed = count($errors);
        $totalItems = $totalProcessSuccessfully + $totalProcessFailed;

        $this->finishFilesystemMapperImport(
            $totalItems,
            $totalProcessSuccessfully,
            $totalProcessFailed,
            $errors
        );

        $this->notificationStatus(
            $totalItems,
            $totalProcessSuccessfully,
            $totalProcessFailed,
            $created,
            $updated,
            $errors,
            $this->branch->company
        );
    }

    #[Override]
    protected function notificationStatus(
        int $totalItems,
        int $totalProcessSuccessfully,
        int $totalProcessFailed,
        int $created,
        int $updated,
        array $errors,
        Companies $company
    ): void {
        $subscriptionData = [
                   'jobUuid' => $this->jobUuid,
                   'status' => 'completed',
                   'results' => [
                       'total_items' => $totalItems,
                       'total_process_successfully' => $totalProcessSuccessfully,
                       'total_process_failed' => $totalProcessFailed,
                       'created' => $created,
                       'updated' => $updated,
                   ],
                   'exception' => $errors,
                  // 'user' => $this->user,
                  // 'company' => $company,
               ];
        ImportResultEvents::dispatch(
            $this->app,
            $this->branch->company,
            $this->user,
            $subscriptionData
        );
    }
}
