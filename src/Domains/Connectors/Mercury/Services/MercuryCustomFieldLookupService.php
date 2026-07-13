<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Connectors\Mercury\Enums\CustomFieldEnum;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * Resolves the Kanvas record behind a Mercury id.
 *
 * The mapping lives in a custom field, and the tempting way to read it back — load every organization for the
 * company and `->first(fn ($o) => $o->get(MERCURY_CUSTOMER_ID) === $id)` — is a full table scan with a cache
 * round-trip per row. `getByCustomFieldBuilder` joins `apps_custom_fields` and lets the index answer it.
 *
 * That join brings its own `companies_id` and `is_deleted` columns, so the model's own have to be named with
 * their table prefix or MySQL calls them ambiguous. `fromApp` is safe — `apps_custom_fields` has no `apps_id`.
 */
class MercuryCustomFieldLookupService
{
    public static function organization(
        string $mercuryCustomerId,
        AppInterface $app,
        CompanyInterface $company,
    ): ?Organization {
        /** @var Organization|null $organization */
        $organization = self::scoped(
            Organization::getByCustomFieldBuilder(
                CustomFieldEnum::CUSTOMER_ID->value,
                $mercuryCustomerId,
                useCompanyFilter: false,
            ),
            'organizations',
            $app,
            $company,
        )->first();

        return $organization;
    }

    public static function invoice(
        string $mercuryInvoiceId,
        AppInterface $app,
        CompanyInterface $company,
    ): ?Invoice {
        /** @var Invoice|null $invoice */
        $invoice = self::scoped(
            Invoice::getByCustomFieldBuilder(
                CustomFieldEnum::INVOICE_ID->value,
                $mercuryInvoiceId,
                useCompanyFilter: false,
            ),
            'invoices',
            $app,
            $company,
        )->first();

        return $invoice;
    }

    private static function scoped(
        Builder $query,
        string $table,
        AppInterface $app,
        CompanyInterface $company,
    ): Builder {
        return $query
            ->fromApp($app)
            ->where('apps_custom_fields.companies_id', $company->getId())
            ->where($table . '.companies_id', $company->getId())
            ->where($table . '.is_deleted', 0);
    }
}
