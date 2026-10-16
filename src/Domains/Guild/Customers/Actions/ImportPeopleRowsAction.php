<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\Log;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Kanvas\Guild\Customers\Models\People;
use Spatie\LaravelData\DataCollection;
use Throwable;

use function Sentry\captureException;

/**
 * The per-row "turn a flat array into a People record" loop, shared by CustomerImporterJob (queued,
 * file/mapper-driven) and any synchronous caller (an agent tool that needs the resulting people_ids
 * back in the same response, not after a worker runs). Pulling this out of the job is what lets both
 * reuse CreatePeopleAction's dedup-by-contact logic without a second copy of the loop.
 */
class ImportPeopleRowsAction
{
    public function __construct(
        private readonly AppInterface $app,
        private readonly CompaniesBranches $branch,
        private readonly UserInterface $user,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param (callable(People, array<string, mixed>): void)|null $afterRowProcessed Extra per-row work
     *        only the caller knows about (e.g. CustomerImporterJob's event-participant sync) — keeps
     *        that concern out of this shared loop instead of forking it.
     *
     * @return array{created: int, updated: int, people_ids: list<int>, errors: array}
     */
    public function execute(array $rows, ?callable $afterRowProcessed = null): array
    {
        // Dedup-by-contact reads the row a prior iteration of THIS SAME loop just wrote; a cached
        // "not found" from before that write would silently create a duplicate instead of updating it.
        config(['laravel-model-caching.disabled' => true]);

        $created = 0;
        $updated = 0;
        $peopleIds = [];
        $errors = [];

        foreach ($rows as $row) {
            try {
                $row = $this->splitFirstNameIfNeeded($row);

                $peopleData = PeopleData::from([
                    'app' => $this->app,
                    'branch' => $this->branch,
                    'user' => $this->user,
                    'firstname' => $row['firstname'],
                    'middlename' => $row['middlename'] ?? null,
                    'lastname' => $row['lastname'] ?? null,
                    'contacts' => Contact::collect($row['contacts'] ?? [], DataCollection::class),
                    'address' => Address::collect($row['address'] ?? [], DataCollection::class),
                    'dob' => $row['dob'] ?? null,
                    'facebook_contact_id' => $row['facebook_contact_id'] ?? null,
                    'google_contact_id' => $row['google_contact_id'] ?? null,
                    'apple_contact_id' => $row['apple_contact_id'] ?? null,
                    'linkedin_contact_id' => $row['linkedin_contact_id'] ?? null,
                    'custom_fields' => $row['custom_fields'] ?? [],
                    'tags' => $row['tags'] ?? [],
                    'organization' => $row['organization'] ?? null,
                    'created_at' => $row['created_at'] ?? null,
                ]);

                /** @var People $peopleModel */
                $peopleModel = new CreatePeopleAction($peopleData)->execute();

                $peopleModel->wasRecentlyCreated ? $created++ : $updated++;
                $peopleIds[] = $peopleModel->getId();

                if ($afterRowProcessed !== null) {
                    $afterRowProcessed($peopleModel, $row);
                }
            } catch (Throwable $e) {
                Log::error($e->getMessage());
                captureException($e);

                $errors[] = [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'request' => $row,
                ];
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'people_ids' => $peopleIds,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function splitFirstNameIfNeeded(array $row): array
    {
        if (! empty($row['lastname']) || ! empty($row['middlename']) || ! isset($row['firstname'])) {
            return $row;
        }

        $nameParts = explode(' ', trim($row['firstname']));

        if (count($nameParts) > 1) {
            $row['firstname'] = $nameParts[0];
            $row['lastname'] = $nameParts[count($nameParts) - 1];
        }

        return $row;
    }
}
