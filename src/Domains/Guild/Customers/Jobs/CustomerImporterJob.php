<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Jobs;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Events\Events\ImportResultEvents;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Participants\Actions\SyncPeopleWithParticipantAction;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People;
use Kanvas\Imports\AbstractImporterJob;
use Override;
use Spatie\LaravelData\DataCollection;
use Throwable;

use function Sentry\captureException;

class CustomerImporterJob extends AbstractImporterJob
{
    #[Override]
    public function handle()
    {
        config(['laravel-model-caching.disabled' => true]);
        Auth::loginUsingId($this->user->getId());
        $this->overwriteAppService($this->app);
        $this->overwriteAppServiceLocation($this->branch);

        $totalItems = count($this->importer);
        $totalProcessSuccessfully = 0;
        $totalProcessFailed = 0;
        $created = 0;
        $updated = 0;
        $errors = [];

        $this->startFilesystemMapperImport();

        /**
         * @var Companies
         */
        $company = $this->branch->company()->firstOrFail();

        foreach ($this->importer as $customerData) {
            try {
                // Check if lastname and middlename are empty, and firstname contains a space
                if (empty($customerData['lastname']) && empty($customerData['middlename']) && isset($customerData['firstname'])) {
                    // Split the firstname by space
                    $nameParts = explode(' ', trim($customerData['firstname']));

                    // If there are multiple parts, use the first part as firstname and the last part as lastname
                    if (count($nameParts) > 1) {
                        $customerData['firstname'] = $nameParts[0];
                        $customerData['lastname'] = $nameParts[count($nameParts) - 1];
                    }
                }
                $people = People::from([
                    'app' => $this->app,
                    'branch' => $this->branch,
                    'user' => $this->user,
                    'firstname' => $customerData['firstname'],
                    'middlename' => $customerData['middlename'] ?? null,
                    'lastname' => $customerData['lastname'] ?? null,
                    'contacts' => Contact::collect($customerData['contacts'] ?? [], DataCollection::class),
                    'address' => Address::collect($customerData['address'] ?? [], DataCollection::class),
                    'dob' => $customerData['dob'] ?? null,
                    'facebook_contact_id' => $customerData['facebook_contact_id'] ?? null,
                    'google_contact_id' => $customerData['google_contact_id'] ?? null,
                    'apple_contact_id' => $customerData['apple_contact_id'] ?? null,
                    'linkedin_contact_id' => $customerData['linkedin_contact_id'] ?? null,
                    'custom_fields' => $customerData['custom_fields'] ?? [],
                    'tags' => $customerData['tags'] ?? [],
                    'organization' => $customerData['organization'] ?? null,
                    'created_at' => $customerData['created_at'] ?? null,
                ]);

                $peopleSync = new CreatePeopleAction($people);
                $peopleModel = $peopleSync->execute();

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

                if ($peopleModel->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }

                $totalProcessSuccessfully++;
            } catch (Throwable $e) {
                Log::error($e->getMessage());
                captureException($e);

                $errors[] = [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'request' => $customerData,
                ];

                $totalProcessFailed++;
            }
        }

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
