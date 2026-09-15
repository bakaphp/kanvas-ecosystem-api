<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services\CustomerSuccess;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Settings;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Enums\KanvasReleaseFeedEnum;

/**
 * Who gets the monthly update, on both sides, decided by one tag.
 *
 * Opting in is tagging: an organization tagged `newsletter` is on the list, and the people on that
 * account tagged `newsletter` are who it reaches. No custom field to seed, no config table — the same
 * tag UI a CSM already uses to organise accounts is the subscription list, and it is visible where
 * they work rather than in an admin screen nobody opens.
 *
 * A tagged account with no tagged, deliverable contact is not an error. It is an account somebody
 * meant to include and forgot to name a recipient on, so it comes back as a skip the batch can report
 * by name instead of a silent omission.
 */
class NewsletterAudienceService
{
    public const string TAG = 'newsletter';

    /**
     * @return Collection<int, Organization>
     */
    public function organizations(AppInterface $app, ?CompanyInterface $company = null): Collection
    {
        $companyId = $company?->getId();

        $taggedIds = $this->taggedEntityIds($app->getId(), Organization::class);

        if ($taggedIds === []) {
            return collect();
        }

        return Organization::query()
            ->fromApp($app)
            ->when(
                $companyId !== null,
                fn (Builder $query): Builder => $query->where('companies_id', $companyId)
            )
            ->whereIn('id', $taggedIds)
            ->notDeleted()
            ->orderBy('id')
            ->get();
    }

    /**
     * The apps the monthly cron may run for: those whose operator switched it on.
     *
     * Gated on the setting and NOT on "has tagged accounts". A tag is how a CSM organises records, the
     * word `newsletter` could already mean something on an app that has never heard of this feature,
     * and either way tagging an account is not consent to mail it on a schedule. Enabling is a
     * deliberate act; the tags then decide who, within an app that already said yes.
     *
     * @return list<int>
     */
    public function enabledAppIds(): array
    {
        return Settings::query()
            ->where('name', KanvasReleaseFeedEnum::MONTHLY_UPDATE_ENABLED->value)
            ->where('is_deleted', 0)
            ->pluck('value', 'apps_id')
            ->filter(fn (mixed $value): bool => filter_var($value, FILTER_VALIDATE_BOOLEAN))
            ->keys()
            ->map(fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Tagged entity ids, read straight off the pivot.
     *
     * NOT `whereHas('tags', ...)`. Tags live in the `social` database while organizations and people
     * live in `crm`, and the correlated subquery Eloquent builds for whereHas does not bridge that —
     * it matches nothing at all, silently, even when the relation itself resolves fine. A monthly cron
     * that quietly finds zero accounts is the worst possible failure here, so the lookup is explicit.
     *
     * @param list<int>|null $limitTo candidate ids, when the caller already has a bounded set
     *
     * @return list<int>
     */
    private function taggedEntityIds(int $appId, string $morphClass, ?array $limitTo = null): array
    {
        $social = config('database.connections.social.database');

        return DB::connection('social')
            ->table($social . '.tags_entities')
            ->join($social . '.tags', $social . '.tags.id', '=', $social . '.tags_entities.tags_id')
            ->where($social . '.tags.slug', self::TAG)
            ->where($social . '.tags.apps_id', $appId)
            ->where($social . '.tags_entities.taggable_type', $morphClass)
            ->where($social . '.tags_entities.is_deleted', 0)
            ->when(
                $limitTo !== null,
                fn ($query) => $query->whereIn($social . '.tags_entities.entity_id', $limitTo)
            )
            ->distinct()
            ->pluck($social . '.tags_entities.entity_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Deliverable email addresses for the tagged people on this account.
     *
     * `Contact::scopeDeliverable()` is the platform's own rule for "may we mail this" — opted in, not a
     * permanent failure — so an address that hard-bounced last month drops out here rather than
     * bouncing again on every send.
     *
     * @return list<string>
     */
    public function recipients(Organization $organization): array
    {
        $onAccount = $organization->peoples()->pluck('peoples.id')->all();

        if ($onAccount === []) {
            return [];
        }

        $peopleIds = $this->taggedEntityIds((int) $organization->apps_id, People::class, $onAccount);

        if ($peopleIds === []) {
            return [];
        }

        return Contact::query()
            ->whereIn('peoples_id', $peopleIds)
            ->whereIn('contacts_types_id', Contact::EMAIL_TYPES)
            ->notDeleted()
            ->deliverable()
            ->orderByDesc('weight')
            ->pluck('value')
            ->map(fn (string $email): string => trim($email))
            ->filter(fn (string $email): bool => $email !== '')
            ->unique()
            ->values()
            ->all();
    }
}
