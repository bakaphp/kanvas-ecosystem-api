<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\CustomerSuccess;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Enums\KanvasReleaseFeedEnum;
use Kanvas\Intelligence\Agents\Services\CustomerSuccess\NewsletterAudienceService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Intelligence\Agents\CustomerSuccess\Concerns\BuildsNewsletterAudience;
use Tests\TestCase;

/**
 * Who the monthly update reaches. Both halves are opt-in and both are easy to get wrong in the
 * direction that mails somebody who never asked, so each is pinned here.
 *
 * Serial: this toggles the monthly-update flag on the shared test app. `HashTableTrait::set()` writes
 * to Redis and upserts on `ecosystem`, and DatabaseTransactions rolls back neither — so in the parallel
 * lane the flag is on for every other process while this runs, and an assertion failing between the
 * on and the off leaves the cron enabled on that app afterwards.
 */
#[Group('serial')]
final class NewsletterAudienceServiceTest extends TestCase
{
    use BuildsNewsletterAudience;
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
}
