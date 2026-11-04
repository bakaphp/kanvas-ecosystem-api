<?php

declare(strict_types=1);

namespace Kanvas\AdminLinks\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Kanvas\AdminLinks\Enums\AdminLinkIdentifierEnum;
use Kanvas\AdminLinks\Enums\AdminLinkSectionEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentSwarm;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Rules\Models\Rule;

/**
 * Finds the record behind whatever identifier a caller happens to be holding.
 *
 * Callers rarely hold the identifier the admin route wants. Every lead tool hands the agent a numeric
 * id, while the leads route keys on the uuid — so a link built straight from what the caller has is a
 * dead one. Resolving the record first means the identifier is derived from the row, not from the
 * caller's luck.
 */
class AdminLinkRecordResolver
{
    /**
     * A model listed here also needs HasAdminLink: build_admin_link links through the record it gets
     * back, and reports one it cannot link as a record that does not exist.
     *
     * @return array<string, class-string<Model>>
     */
    private const MODELS = [
        'LEAD' => Lead::class,
        'DEAL' => Deal::class,
        'PEOPLE' => People::class,
        'ORGANIZATION' => Organization::class,
        'PRODUCT' => Products::class,
        'PRODUCT_VARIANT' => Variants::class,
        // Both screens key on the slug while every inventory tool hands back the numeric id.
        'CATEGORY' => Categories::class,
        'CHANNEL' => Channels::class,
        'ORDER' => Order::class,
        'AGENT_PROJECT' => Project::class,
        'AGENT' => Agent::class,
        'AGENT_SWARM' => AgentSwarm::class,
        'WORKFLOW_RECEIVER' => ReceiverWebhook::class,
        'RULE' => Rule::class,
        'MESSAGE' => Message::class,
    ];

    /**
     * Sections whose records can belong to the app rather than to one company (companies_id = 0). The
     * admin lists them with fromCompanyOrGlobal, so looking only at the company would report a channel
     * the person can see as missing.
     */
    private const array SHARED_WITH_THE_APP = ['CATEGORY', 'CHANNEL'];

    private const string UNKNOWN_COLUMN = '42S22';

    public function supports(AdminLinkSectionEnum $section): bool
    {
        return isset(self::MODELS[$section->name]);
    }

    /**
     * Scoped to the app, and to the company when one is given — an identifier reaching this is
     * caller-supplied and may be a hallucination or someone else's row, so an unscoped lookup here
     * would be a cross-tenant read. A deleted record is not found: there is nothing left to open.
     */
    public function resolve(
        AdminLinkSectionEnum $section,
        string $identifier,
        Apps $app,
        ?Companies $company = null
    ): ?Model {
        $modelClass = self::MODELS[$section->name] ?? null;
        $identifier = trim($identifier);

        if ($modelClass === null || $identifier === '') {
            return null;
        }

        $query = $modelClass::query()->fromApp($app);

        if ($company !== null) {
            $this->scopeToCompany($query, $section, $company);
        }

        $found = [];

        foreach ($this->columnsFor($section, $identifier) as $column) {
            $record = $this->firstBy(clone $query, $column, $identifier);

            // The CRM models carry no soft-delete scope, so a deleted lead comes back like any other row.
            if ($record !== null && ! $record->isDeleted()) {
                $found[$record->getKey()] = $record;
            }
        }

        // An identifier that names two records names neither: "2024" as one category's slug and
        // another's id. Either guess would put the wrong record under the caller's title.
        return count($found) === 1 ? array_values($found)[0] : null;
    }

    /**
     * The company filter, written out instead of taken from fromCompany().
     *
     * That scope widens to every company of the app when the request came in on an app key with no
     * branch header, which is how the admin's project mode calls. The identifier is the caller's own
     * text, so under it a card would confirm, and link, another company's record.
     */
    private function scopeToCompany(Builder $query, AdminLinkSectionEnum $section, Companies $company): void
    {
        $column = $query->qualifyColumn('companies_id');

        if (! in_array($section->name, self::SHARED_WITH_THE_APP, true)) {
            $query->where($column, $company->getId());

            return;
        }

        // The model's own scope says whether app-wide rows are visible at all (a channel always, a
        // category under the cross-company flag). On an app-key request that scope lets every company
        // through, so the bound is what keeps the answer to this company's rows and the app's.
        $query->fromCompanyOrGlobal($company)->whereIn($column, [0, $company->getId()]);
    }

    /**
     * Where to look.
     *
     * A screen that opens by slug is asked for the slug whatever the identifier looks like — "2024"
     * is a good slug — and also for what its shape says, because the tools hand back the numeric id.
     *
     * @return list<string>
     */
    private function columnsFor(AdminLinkSectionEnum $section, string $identifier): array
    {
        $byShape = AdminLinkIdentifierEnum::shapeOf($identifier)->column();

        if ($section->identifier() !== AdminLinkIdentifierEnum::SLUG) {
            return [$byShape];
        }

        return array_values(array_unique([AdminLinkIdentifierEnum::SLUG->column(), $byShape]));
    }

    private function firstBy(Builder $query, string $column, string $identifier): ?Model
    {
        try {
            return $query->where($column, $identifier)->first();
        } catch (QueryException $e) {
            // Not every model in the map carries every column the identifier's shape can imply —
            // Rule has neither uuid nor slug, so a name asks for a column that is not there. That is
            // "no such record". Anything else is a fault: it still reads as not found, because a link
            // is not worth failing the turn over, but it is reported so a lock timeout is not taken
            // for a missing lead.
            if ((string) $e->getCode() !== self::UNKNOWN_COLUMN) {
                report($e);
            }

            return null;
        }
    }
}
