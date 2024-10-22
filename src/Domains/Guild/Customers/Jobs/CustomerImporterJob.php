<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Jobs;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Inventory\Importer\Jobs\ProductImporterJob;
use Kanvas\Event\Events\Events\ImportResultEvents;
use function Sentry\captureException;

use Spatie\LaravelData\DataCollection;
use Throwable;

class CustomerImporterJob extends ProductImporterJob
{
    use KanvasJobsTrait;

    /**
    * The number of seconds after which the job's unique lock will be released.
    *
    * @var int
    */
    public $uniqueFor = 1800;

    // public function __construct(
    //     public string $jobUuid,
    //     public array $importer,
    //     public CompaniesBranches $branch,
    //     public UserInterface $user,
    //     public AppInterface $app
    // ) {
    // }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return $this->jobUuid . $this->app->getId() . $this->branch->getId();
    }

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
