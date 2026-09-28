<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\DataTransferObject\People;
use Kanvas\Guild\Customers\Models\People as ModelsPeople;

class SyncPeopleByThirdPartyCustomFieldAction
{
    public function __construct(
        protected People $people,
    ) {
    }

    public function execute(): ModelsPeople
    {
        $customFields = $this->people->custom_fields ?? [];
        $customFieldKeys = array_keys($customFields);
        $customFieldValues = array_values($customFields);

        if (empty($customFieldKeys[0]) || empty($customFieldValues[0])) {
            throw new ValidationException('People Missing Custom Fields Key and Value to find reference');
        }

        $company = $this->people->branch->company;
        $customFieldKey = (string) $customFieldKeys[0];
        $customFieldValue = (string) $customFieldValues[0];
        $lockKey = 'people_sync:' . $this->people->app->getId() . $company->getId() . ':' . $customFieldKey . ':' . $customFieldValue;

        try {
            // Wait longer than the lock TTL so a slow holder either finishes or has its
            // lock expire inside our window — otherwise every waiter times out on a
            // create/update that legitimately takes more than the wait.
            return Cache::lock($lockKey, 10)
                ->block(11, fn (): ModelsPeople => $this->sync($customFieldKey, $customFieldValue));
        } catch (LockTimeoutException $e) {
            // The concurrent sync owns the canonical people record for this reference.
            // Yield to it instead of surfacing a 500; only rethrow if it isn't there yet.
            /** @var ModelsPeople|null $people */
            $people = ModelsPeople::getByCustomField($customFieldKey, $customFieldValue, $company);

            if ($people !== null) {
                return $people;
            }

            throw $e;
        }
    }

    private function sync(string $customFieldKey, string $customFieldValue): ModelsPeople
    {
        /** @var ModelsPeople|null $people */
        $people = ModelsPeople::getByCustomField(
            $customFieldKey,
            $customFieldValue,
            $this->people->branch->company,
        );

        if ($people !== null) {
            $this->people->id = $people->getId();
            $createPeople = new UpdatePeopleAction($people, $this->people);
        } else {
            $createPeople = new CreatePeopleAction($this->people);
        }

        $createPeople->runWorkflow = $this->people->runWorkflow ?? true;

        return $createPeople->execute();
    }
}
