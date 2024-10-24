<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Jobs;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Events\Events\ImportResultEvents;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Participants\Actions\SyncPeopleWithParticipantAction;
use Kanvas\Filesystem\Contracts\ImporterJobContract;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;

use function Sentry\captureException;

use Spatie\LaravelData\DataCollection;
use Throwable;

class CustomerImporterJob extends ImporterJobContract
{
    /**
     * handle.
     *
     * @return void
     */
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

                if ($people->contacts->count()) {
                    foreach ($people->contacts as $contact) {
                        $customer = PeoplesRepository::getByValue($contact->value, $company, $this->app);
                        if ($customer) {
                            $people->id = $customer->id;

                            break;
                        }
                    }
                }

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
