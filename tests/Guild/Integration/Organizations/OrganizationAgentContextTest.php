<?php

declare(strict_types=1);

namespace Tests\Guild\Integration\Organizations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Contracts\ProvidesAgentContext;
use Kanvas\Intelligence\Agents\Services\EntityContextBriefService;
use Kanvas\Social\Tags\Models\Tag;
use Tests\TestCase;

final class OrganizationAgentContextTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Deliberately WITHOUT 'social'. `HasTagsTrait::tags()` reads `tags_entities` through a
     * cross-database reference resolved on the model's own connection (`crm`), while `CreateTagAction`
     * writes the tag row on the `social` connection. Holding `social` open in its own transaction makes
     * that freshly written tag invisible to the cross-database read, and every tag assertion sees zero
     * rows. The few leaked tag rows are the accepted trade — see tests/CLAUDE.md on the same pattern.
     */
    protected array $connectionsToTransact = ['mysql', 'crm'];

    public function testOrganizationOptsIntoItsOwnAgentBrief(): void
    {
        $this->assertInstanceOf(ProvidesAgentContext::class, $this->seedOrganization('Contract Corp'));
    }

    public function testBriefCarriesTheCommercialContextAnAgentNeeds(): void
    {
        $organization = $this->seedOrganization('Brief Corp');
        $this->tagOrganization($organization, ['module-commerce', 'module-logistics']);
        $organization->set('customer_tier', 'growth');
        $organization->set('customer_locale', 'es');

        $brief = $organization->agentContextBrief();

        $this->assertSame('Organization', $brief['type']);
        $this->assertSame($organization->getId(), $brief['id']);
        $this->assertSame('growth', $brief['tier']);
        $this->assertSame('es', $brief['locale']);
        $this->assertEqualsCanonicalizing(['module-commerce', 'module-logistics'], $brief['modules']);
    }

    /**
     * read_entity_context routes through EntityContextBriefService, so an organization must get its own
     * brief rather than the generic name/email/phone fallback every unopted model receives.
     */
    public function testTheSharedBriefServiceUsesItRatherThanTheGenericFallback(): void
    {
        $organization = $this->seedOrganization('Routed Corp');
        $organization->set('customer_tier', 'enterprise');

        $brief = new EntityContextBriefService()->brief($organization);

        $this->assertSame('enterprise', $brief['tier'] ?? null, 'the generic fallback has no tier at all');
    }

    /**
     * Feature state for one workflow must not leak onto a surface every agent reads.
     */
    public function testTheNewsletterWatermarkIsNotInTheBrief(): void
    {
        $organization = $this->seedOrganization('Watermark Corp');
        $organization->set('newsletter_last_release_at', '2026-09-01T00:00:00+00:00');

        $this->assertArrayNotHasKey('newsletter_last_release_at', $organization->agentContextBrief());
    }

    public function testEmptyValuesAreOmittedRatherThanSentAsNulls(): void
    {
        $brief = $this->seedOrganization('Sparse Corp')->agentContextBrief();

        $this->assertArrayNotHasKey('tier', $brief);
        $this->assertArrayNotHasKey('locale', $brief);
        $this->assertArrayNotHasKey('modules', $brief);
    }

    /**
     * Writes the tag + pivot directly rather than through `HasTagsTrait::addTag()`. That helper is a
     * no-op inside the test harness — it returns without error and inserts nothing, while the identical
     * call writes a row outside it. Pre-existing and unrelated to the brief; this test is about the
     * brief reading tags, so it seeds them the short way and does not inherit that quirk.
     *
     * @param array<int, string> $slugs
     */
    private function tagOrganization(Organization $organization, array $slugs): void
    {
        foreach ($slugs as $slug) {
            $tag = Tag::firstOrCreate(
                [
                    'apps_id' => $organization->apps_id,
                    'companies_id' => $organization->companies_id,
                    'slug' => $slug,
                ],
                [
                    'users_id' => $organization->users_id,
                    'name' => $slug,
                ]
            );

            DB::connection('social')->table('tags_entities')->insert([
                'tags_id' => $tag->getId(),
                'entity_id' => $organization->getId(),
                'taggable_type' => Organization::class,
                'users_id' => $organization->users_id,
                'is_deleted' => 0,
                'created_at' => now(),
            ]);
        }
    }

    private function seedOrganization(string $name): Organization
    {
        $user = auth()->user();

        return Organization::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
            'name' => $name . ' ' . uniqid(),
            'address' => '',
            'total_employees' => 0,
        ]);
    }
}
