<?php

declare(strict_types=1);

namespace Kanvas\AdminLinks\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Kanvas\AdminLinks\Enums\AdminLinkSectionEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentSwarm;
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
     * @return array<string, class-string<Model>>
     */
    private const MODELS = [
        'LEAD' => Lead::class,
        'DEAL' => Deal::class,
        'PEOPLE' => People::class,
        'ORGANIZATION' => Organization::class,
        'PRODUCT' => Products::class,
        'PRODUCT_VARIANT' => Variants::class,
        'ORDER' => Order::class,
        'AGENT_PROJECT' => Project::class,
        'AGENT' => Agent::class,
        'AGENT_SWARM' => AgentSwarm::class,
        'WORKFLOW_RECEIVER' => ReceiverWebhook::class,
        'RULE' => Rule::class,
        'MESSAGE' => Message::class,
    ];

    public function supports(AdminLinkSectionEnum $section): bool
    {
        return isset(self::MODELS[$section->name]);
    }

    /**
     * Scoped to the app, and to the company when the model carries one — an identifier reaching this
     * is caller-supplied and may be a hallucination or someone else's row, so an unscoped lookup here
     * would be a cross-tenant read.
     */
    public function resolve(
        AdminLinkSectionEnum $section,
        string $identifier,
        Apps $app,
        ?Companies $company = null
    ): ?Model {
        $modelClass = self::MODELS[$section->name] ?? null;

        if ($modelClass === null || trim($identifier) === '') {
            return null;
        }

        $identifier = trim($identifier);

        $query = $modelClass::query()->fromApp($app);

        if ($company !== null) {
            $query->fromCompany($company);
        }

        $column = match (true) {
            ctype_digit($identifier) => 'id',
            str_contains($identifier, '-') => 'uuid',
            default => 'slug',
        };

        try {
            return $query->where($column, $identifier)->first();
        } catch (QueryException) {
            // Not every model in the map carries every column the identifier's shape can imply —
            // Rule has neither uuid nor slug, so a dashed identifier asks for a column that is not
            // there. Treated as "no such record" rather than a fault; the caller says so and moves on.
            return null;
        }
    }
}
