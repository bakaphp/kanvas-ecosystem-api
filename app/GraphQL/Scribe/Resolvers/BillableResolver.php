<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Resolvers;

use Baka\Contracts\AppInterface;
use Baka\Contracts\BillableInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Contracts\PayeeInterface;
use RuntimeException;

/**
 * Shared lookup for cross-domain customer (Billable) and vendor (Payee) references.
 *
 * GraphQL input carries `billable_type` (FQCN string) + `billable_id` (int). This service:
 *   1. Verifies the FQCN exists and implements the right contract
 *   2. Looks up the model in the current (app, company) scope via KanvasModelTrait
 *   3. Returns the typed model
 *
 * Throws on unknown class, non-implementing class, or out-of-scope id.
 */
class BillableResolver
{
    public function resolveBillable(string $type, int $id, AppInterface $app, CompanyInterface $company): BillableInterface
    {
        if (! class_exists($type)) {
            throw new RuntimeException("billable_type '{$type}' is not a valid class.");
        }

        if (! is_subclass_of($type, BillableInterface::class)) {
            throw new RuntimeException(
                "billable_type '{$type}' does not implement " . BillableInterface::class . '.'
            );
        }

        /** @var BillableInterface $model */
        $model = $type::getByIdFromCompanyApp($id, $company, $app);

        return $model;
    }

    public function resolveBillableOrNull(?string $type, ?int $id, AppInterface $app, CompanyInterface $company): ?BillableInterface
    {
        if ($type === null || $id === null) {
            return null;
        }

        return $this->resolveBillable($type, $id, $app, $company);
    }

    public function resolvePayee(string $type, int $id, AppInterface $app, CompanyInterface $company): PayeeInterface
    {
        if (! class_exists($type)) {
            throw new RuntimeException("vendor_billable_type '{$type}' is not a valid class.");
        }

        if (! is_subclass_of($type, PayeeInterface::class)) {
            throw new RuntimeException(
                "vendor_billable_type '{$type}' does not implement " . PayeeInterface::class . '.'
            );
        }

        /** @var PayeeInterface $model */
        $model = $type::getByIdFromCompanyApp($id, $company, $app);

        return $model;
    }

    public function resolvePayeeOrNull(?string $type, ?int $id, AppInterface $app, CompanyInterface $company): ?PayeeInterface
    {
        if ($type === null || $id === null) {
            return null;
        }

        return $this->resolvePayee($type, $id, $app, $company);
    }
}
