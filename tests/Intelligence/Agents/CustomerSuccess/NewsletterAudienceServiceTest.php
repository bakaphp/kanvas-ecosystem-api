<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\CustomerSuccess;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Enums\ContactValidationStatusEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\ContactType;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationPeople;
use Kanvas\Intelligence\Agents\Enums\KanvasReleaseFeedEnum;
use Kanvas\Intelligence\Agents\Services\CustomerSuccess\NewsletterAudienceService;
use Kanvas\Social\Tags\Models\Tag;
use Tests\TestCase;

/**
 * Who the monthly update reaches. Both halves are opt-in and both are easy to get wrong in the
 * direction that mails somebody who never asked, so each is pinned here.
 */
final class NewsletterAudienceServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'social', 'ecosystem'];

    /**
     * The one that matters most: `newsletter` is an ordinary tag string, so an app that has never
     * heard of this feature could already be using the word. Tagging is not consent — the app setting
     * is.
     */
    public function testAnAppIsOnlyEligibleOnceItsOperatorSwitchesTheFeatureOn(): void
    {
        $app = app(Apps::class);
        $service = new NewsletterAudienceService();

        $this->taggedOrganization();

        $app->set(KanvasReleaseFeedEnum::MONTHLY_UPDATE_ENABLED->value, false);
        $this->assertNotContains(
            $app->getId(),
            $service->enabledAppIds(),
            'a tagged account must not opt its whole app in'
        );

        $app->set(KanvasReleaseFeedEnum::MONTHLY_UPDATE_ENABLED->value, true);
        $this->assertContains($app->getId(), $service->enabledAppIds());

        $app->set(KanvasReleaseFeedEnum::MONTHLY_UPDATE_ENABLED->value, false);
        $this->assertNotContains($app->getId(), $service->enabledAppIds(), 'opting out must take effect');
    }

    public function testOnlyTaggedOrganizationsAreSelected(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $tagged = $this->taggedOrganization();
        $untagged = $this->organization();

        $ids = new NewsletterAudienceService()
            ->organizations($app, $user->getCurrentCompany())
            ->pluck('id')
            ->all();

        $this->assertContains($tagged->getId(), $ids);
        $this->assertNotContains($untagged->getId(), $ids);
    }

    public function testOnlyTaggedPeopleOnTheAccountBecomeRecipients(): void
    {
        $organization = $this->taggedOrganization();

        $this->linkPerson($organization, 'subscriber@example.test', tagged: true);
        $this->linkPerson($organization, 'colleague@example.test', tagged: false);

        $this->assertSame(
            ['subscriber@example.test'],
            new NewsletterAudienceService()->recipients($organization),
            'an untagged contact on a subscribed account is not a subscriber'
        );
    }

    /**
     * Contact::scopeDeliverable() is the platform rule for "may we mail this". An address that hard
     * bounced last month must drop out here rather than bouncing again every month.
     */
    public function testAHardBouncedOrOptedOutAddressIsNotARecipient(): void
    {
        $organization = $this->taggedOrganization();

        $this->linkPerson($organization, 'good@example.test', tagged: true);
        $this->linkPerson($organization, 'bounced@example.test', tagged: true, bounced: true);
        $this->linkPerson($organization, 'optedout@example.test', tagged: true, optedOut: true);

        $this->assertSame(['good@example.test'], new NewsletterAudienceService()->recipients($organization));
    }

    public function testATaggedAccountWithNoTaggedContactYieldsNobody(): void
    {
        $organization = $this->taggedOrganization();
        $this->linkPerson($organization, 'colleague@example.test', tagged: false);

        $this->assertSame([], new NewsletterAudienceService()->recipients($organization));
    }

    private function taggedOrganization(): Organization
    {
        $organization = $this->organization();
        $this->tag($organization);

        return $organization->refresh();
    }

    /**
     * The pivot row is written directly rather than through HasTagsTrait::addTag(). This exercises the
     * audience query, not the tag trait — and addTag() proved not to persist for an Organization in a
     * test context, which is a separate problem that should not decide whether these pass.
     */
    private function tag(Organization|People $entity): void
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

    private function organization(): Organization
    {
        $user = auth()->user();

        return Organization::create([
            'name' => 'Account ' . fake()->unique()->uuid(),
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
        ]);
    }

    private function linkPerson(
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
