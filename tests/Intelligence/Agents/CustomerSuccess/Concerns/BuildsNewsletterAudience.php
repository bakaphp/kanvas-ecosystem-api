<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\CustomerSuccess\Concerns;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Enums\ContactValidationStatusEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\ContactType;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationPeople;
use Kanvas\Intelligence\Agents\Services\CustomerSuccess\NewsletterAudienceService;
use Kanvas\Social\Tags\Models\Tag;

trait BuildsNewsletterAudience
{
    protected function taggedOrganization(?int $companyId = null): Organization
    {
        $organization = $this->organization($companyId);
        $this->tag($organization);

        return $organization->refresh();
    }

    /**
     * The pivot row is written directly rather than through HasTagsTrait::addTag(). This exercises the
     * audience query, not the tag trait — and addTag() proved not to persist for an Organization in a
     * test context, which is a separate problem that should not decide whether these pass.
     */
    protected function tag(Organization|People $entity): void
    {
        $tag = Tag::firstOrCreate(
            [
                'apps_id' => app(Apps::class)->getId(),
                'slug' => NewsletterAudienceService::TAG,
            ],
            [
                'name' => NewsletterAudienceService::TAG,
                'users_id' => auth()->user()->getId(),
                'companies_id' => auth()->user()->getCurrentCompany()->getId(),
                'is_deleted' => 0,
            ]
        );

        DB::connection('social')->table('tags_entities')->insert([
            'tags_id' => $tag->getId(),
            'entity_id' => $entity->getId(),
            'taggable_type' => $entity->getMorphClass(),
            'users_id' => auth()->user()->getId(),
            'is_deleted' => 0,
            'created_at' => now(),
        ]);
    }

    protected function organization(?int $companyId = null): Organization
    {
        $user = auth()->user();

        return Organization::create([
            'name' => 'Account ' . fake()->unique()->uuid(),
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $companyId ?? $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
        ]);
    }

    protected function linkPerson(
        Organization $organization,
        string $email,
        bool $tagged,
        bool $bounced = false,
        bool $optedOut = false
    ): People {
        $user = auth()->user();

        $person = People::create([
            'name' => 'Person ' . fake()->unique()->uuid(),
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
        ]);

        OrganizationPeople::create([
            'organizations_id' => $organization->getId(),
            'peoples_id' => $person->getId(),
            'created_at' => now(),
        ]);

        Contact::create([
            'peoples_id' => $person->getId(),
            'contacts_types_id' => ContactType::getByName(ContactTypeEnum::EMAIL->getName())->getId(),
            'value' => $email,
            'weight' => 0,
            'is_opt_out' => $optedOut ? 1 : 0,
            'validation_status' => $bounced
                ? ContactValidationStatusEnum::HARD_BOUNCE->value
                : ContactValidationStatusEnum::VALID->value,
        ]);

        if ($tagged) {
            $this->tag($person);
        }

        return $person;
    }
}
