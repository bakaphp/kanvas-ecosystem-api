<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Regions\Enums\CustomFieldEnum;
use Kanvas\Regions\Models\Regions;
use Tests\TestCase;

final class UserRegionPreferenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Movipass region preference tests are skipped in CI');
        }
    }

    public function testSetDefaultRegionMutation(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();
        $region = Regions::getDefault($company, $app);

        $response = $this->graphQL('
            mutation($regionId: ID!) {
                setDefaultRegion(region_id: $regionId)
            }
        ', ['regionId' => $region->getId()]);

        $response->assertSuccessful();
        $response->assertJson(['data' => ['setDefaultRegion' => true]]);

        // Verify custom field was set
        $user->refresh();
        $storedRegionId = $user->get(CustomFieldEnum::DEFAULT_REGION_ID->value);
        $this->assertEquals($region->getId(), (int) $storedRegionId);

        // It must be stored as public so it surfaces in me.custom_fields
        $meResponse = $this->graphQL('
            query {
                me {
                    custom_fields {
                        data {
                            name
                            value
                        }
                    }
                }
            }
        ');

        $customFields = collect($meResponse->json('data.me.custom_fields.data'));
        $defaultRegionField = $customFields->firstWhere('name', CustomFieldEnum::DEFAULT_REGION_ID->value);

        $this->assertNotNull(
            $defaultRegionField,
            'default_region_id must appear in me.custom_fields'
        );
        $this->assertEquals($region->getId(), (int) $defaultRegionField['value']);

        // Cleanup
        $user->del(CustomFieldEnum::DEFAULT_REGION_ID->value);
    }

    public function testSetDefaultRegionWithInvalidIdFails(): void
    {
        $response = $this->graphQL('
            mutation($regionId: ID!) {
                setDefaultRegion(region_id: $regionId)
            }
        ', ['regionId' => 999999]);

        $response->assertJsonStructure(['errors']);
        $errorMessage = $response->json('errors.0.message');
        $this->assertStringContainsString('record found with ID', $errorMessage);
    }

    public function testSetDefaultRegionUpdatesExistingPreference(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();
        $region = Regions::getDefault($company, $app);

        // Set initial preference
        $this->graphQL('
            mutation($regionId: ID!) {
                setDefaultRegion(region_id: $regionId)
            }
        ', ['regionId' => $region->getId()])->assertSuccessful();

        // Verify it was stored
        $user->refresh();
        $this->assertEquals($region->getId(), (int) $user->get(CustomFieldEnum::DEFAULT_REGION_ID->value));

        // Update to same region (should still work)
        $this->graphQL('
            mutation($regionId: ID!) {
                setDefaultRegion(region_id: $regionId)
            }
        ', ['regionId' => $region->getId()])->assertSuccessful();

        $user->refresh();
        $this->assertEquals($region->getId(), (int) $user->get(CustomFieldEnum::DEFAULT_REGION_ID->value));

        // Cleanup
        $user->del(CustomFieldEnum::DEFAULT_REGION_ID->value);
    }
}
