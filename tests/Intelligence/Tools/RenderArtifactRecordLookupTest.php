<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\AdminLinks\Enums\AdminLinkSectionEnum;
use Kanvas\AdminLinks\Services\AdminLinkRecordResolver;
use Kanvas\Apps\Models\AppKey;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Enums\ToolOutcomeEnum;
use Kanvas\Intelligence\Agents\Neuron\Tools\Common\RenderArtifactTool;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Channels\Models\Channels;
use Tests\TestCase;

/**
 * An `entity` card reads its record live, so the id in the block is a lookup key the model chose. With
 * a tenant in scope the tool checks it before the block exists: an invented id becomes an error the
 * model can act on, and a real one is rewritten to the identifier the record's page reads.
 */
final class RenderArtifactRecordLookupTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'inventory', 'intelligence', 'social'];

    /**
     * Every lead tool hands the model the numeric id, while the lead page keys on the uuid.
     */
    public function testTheIdAToolReturnedBecomesTheOneThePageReads(): void
    {
        $lead = $this->lead($this->company());

        $result = $this->tool()(
            component: 'entity',
            props: ['type' => 'lead', 'id' => $lead->getId(), 'title' => 'Acme renewal'],
        );

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('"id":"' . $lead->uuid . '"', $result['block']);
    }

    public function testAUuidIsKeptAsItIs(): void
    {
        $lead = $this->lead($this->company());

        $result = $this->tool()(
            component: 'entity',
            props: json_encode(['type' => 'lead', 'id' => $lead->uuid, 'title' => 'Acme renewal']),
        );

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('"id":"' . $lead->uuid . '"', $result['block']);
    }

    public function testAnInventedIdIsRefusedBeforeItBecomesACard(): void
    {
        $result = $this->tool()(
            component: 'entity',
            props: ['type' => 'lead', 'id' => 2147483600, 'title' => 'A lead that does not exist'],
        );

        $this->assertFalse($result['success']);
        $this->assertSame(ToolOutcomeEnum::NOT_FOUND->value, $result['outcome']);
        $this->assertStringContainsString('No lead with id "2147483600" exists for this company', $result['error']);
        $this->assertArrayNotHasKey('block', $result);
    }

    /**
     * The id is the model's text and therefore prompt-injectable: another company's record has to be
     * indistinguishable from one that does not exist, or the card would confirm and link it.
     */
    public function testAnotherCompanysRecordIsNotFound(): void
    {
        $foreign = $this->lead(Companies::factory()->create());

        foreach ([$foreign->getId(), $foreign->uuid] as $id) {
            $result = $this->tool()(
                component: 'entity',
                props: ['type' => 'lead', 'id' => $id, 'title' => 'Somebody else\'s lead'],
            );

            $this->assertFalse($result['success']);
            $this->assertSame(ToolOutcomeEnum::NOT_FOUND->value, $result['outcome']);
        }
    }

    /**
     * The chat is sent on the app key with no branch header, and under that the model's own company
     * scope widens to every company of the app. The lookup must not: the id is the model's text.
     */
    public function testAnotherCompanysRecordIsNotFoundOnAnAppKeyRequestEither(): void
    {
        $foreign = $this->lead(Companies::factory()->create());

        app()->instance(AppKey::class, app(Apps::class)->keys()->firstOrFail());
        $this->assertFalse(app()->bound(CompaniesBranches::class));

        $result = $this->tool()(
            component: 'entity',
            props: ['type' => 'lead', 'id' => $foreign->getId(), 'title' => 'Somebody else\'s lead'],
        );

        $this->assertFalse($result['success']);
        $this->assertSame(ToolOutcomeEnum::NOT_FOUND->value, $result['outcome']);
    }

    /**
     * A category can be shared by the whole app, so its lookup goes through the model's own
     * company-or-app scope — which lets every company through on an app-key request. The bound next to
     * it is then the whole company filter.
     */
    public function testAnotherCompanysCategoryIsNotFoundOnAnAppKeyRequest(): void
    {
        $foreign = $this->category('foreign-' . uniqid(), Companies::factory()->create());

        app()->instance(AppKey::class, app(Apps::class)->keys()->firstOrFail());

        foreach ([$foreign->getId(), $foreign->slug] as $id) {
            $result = $this->tool()(
                component: 'entity',
                props: ['type' => 'category', 'id' => $id, 'title' => 'Somebody else\'s category'],
            );

            $this->assertFalse($result['success']);
            $this->assertSame(ToolOutcomeEnum::NOT_FOUND->value, $result['outcome']);
        }
    }

    /**
     * The CRM models have no soft-delete scope, so a deleted lead is a row like any other to the query.
     */
    public function testADeletedRecordIsNotFound(): void
    {
        $lead = Lead::factory()
            ->withAppAndCompany(app(Apps::class)->getId(), $this->company()->getId())
            ->create(['is_deleted' => 1]);

        $result = $this->tool()(
            component: 'entity',
            props: ['type' => 'lead', 'id' => $lead->getId(), 'title' => 'A lead that was deleted'],
        );

        $this->assertFalse($result['success']);
        $this->assertSame(ToolOutcomeEnum::NOT_FOUND->value, $result['outcome']);
    }

    /**
     * The category screen opens by slug, and every inventory tool hands back the numeric id. A slug
     * with a dash used to be read as a uuid, which reported the category as missing.
     */
    public function testACategoryIsWrittenAsItsSlugWhicheverIdentifierFoundIt(): void
    {
        $category = $this->category('summer-sale-' . uniqid());

        foreach ([$category->getId(), $category->slug] as $id) {
            $result = $this->tool()(
                component: 'entity',
                props: ['type' => 'category', 'id' => $id, 'title' => 'Summer sale'],
            );

            $this->assertTrue($result['success'], json_encode($result));
            $this->assertStringContainsString('"id":"' . $category->slug . '"', $result['block']);
        }
    }

    /**
     * "2024" is a good slug, and it is also what a tool hands back for the category whose id is 2024.
     * When both exist the identifier names two records, and either guess draws the wrong one under the
     * model's title — so it names neither. Alone, a slug made of digits is found like any other.
     */
    public function testAnIdentifierThatNamesTwoCategoriesNamesNeither(): void
    {
        $other = $this->category('other-' . uniqid());
        $this->category((string) $other->getId());
        // Digits that are nobody's id.
        $alone = $this->category('99' . random_int(10_000_000_000, 99_999_999_999));

        $find = fn (string $identifier): mixed => new AdminLinkRecordResolver()->resolve(
            AdminLinkSectionEnum::CATEGORY,
            $identifier,
            app(Apps::class),
            $this->company()
        )?->getKey();

        $this->assertNull($find((string) $other->getId()));
        $this->assertSame($alone->getId(), $find($alone->slug));
    }

    /**
     * A slug is the client's own text. One a card cannot put in a URL is not the model's mistake, and
     * no id it retried with would change the answer, so the refusal says what is true.
     */
    public function testACategoryWhoseSlugCannotBeOpenedSaysSoInsteadOfBlamingTheId(): void
    {
        $category = $this->category('Summer Sale ' . uniqid());

        $result = $this->tool()(
            component: 'entity',
            props: ['type' => 'category', 'id' => $category->getId(), 'title' => 'Summer sale'],
        );

        $this->assertFalse($result['success']);
        $this->assertSame(ToolOutcomeEnum::INVALID_ARGS->value, $result['outcome']);
        $this->assertStringContainsString('its slug is not one a card can open', $result['error']);
    }

    /**
     * Where the card also reads the numeric id, a record whose uuid it cannot use still gets a card.
     */
    public function testARecordWhoseUuidACardCannotReadIsWrittenAsItsNumericId(): void
    {
        $lead = Lead::factory()
            ->withAppAndCompany(app(Apps::class)->getId(), $this->company()->getId())
            ->create(['uuid' => 'legacy-' . uniqid()]);

        $result = $this->tool()(
            component: 'entity',
            props: ['type' => 'lead', 'id' => $lead->getId(), 'title' => 'A lead from before uuids'],
        );

        $this->assertTrue($result['success'], json_encode($result));
        $this->assertStringContainsString('"id":' . $lead->getId() . ',', $result['block']);
    }

    /**
     * A channel can belong to the app rather than to one company, and the admin lists it either way.
     */
    public function testAnAppWideChannelIsFound(): void
    {
        // Saved without events: ChannelObserver checks the channel's company, and this row has none.
        $channel = new Channels([
            'users_id' => static::$cachedUser->getId(),
            'companies_id' => 0,
            'apps_id' => app(Apps::class)->getId(),
            'name' => 'Popular ' . uniqid(),
            'slug' => 'popular-' . uniqid(),
            'is_published' => 1,
        ]);
        $channel->generateUuidIfMissing()->saveQuietly();

        $result = $this->tool()(
            component: 'entity',
            props: ['type' => 'channel', 'id' => $channel->getId(), 'title' => 'Popular'],
        );

        $this->assertTrue($result['success'], json_encode($result));
        $this->assertStringContainsString('"id":"' . $channel->slug . '"', $result['block']);
    }

    /**
     * No model stands behind a discount in the record resolver, so there is nothing to look up: the id
     * is held to what its card can find it by and written as given.
     */
    public function testATypeWithNoLookupIsCheckedForShapeOnly(): void
    {
        $result = $this->tool()(
            component: 'entity',
            props: ['type' => 'discount', 'id' => 12, 'title' => 'SUMMER20'],
        );

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('"id":12', $result['block']);
    }

    private function lead(Companies $company): Lead
    {
        return Lead::factory()
            ->withAppAndCompany(app(Apps::class)->getId(), $company->getId())
            ->create();
    }

    private function category(string $slug, ?Companies $company = null): Categories
    {
        return Categories::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => ($company ?? $this->company())->getId(),
            'users_id' => static::$cachedUser->getId(),
            'name' => 'Lookup test ' . $slug,
            'slug' => $slug,
        ]);
    }

    private function company(): Companies
    {
        return static::$cachedUser->getCurrentCompany();
    }

    private function tool(): RenderArtifactTool
    {
        return new RenderArtifactTool()->withContext(app(Apps::class), $this->company(), static::$cachedUser);
    }
}
