<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\NetSuite;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Workflow\SyncCompanyChannelsActivity;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasNetSuiteConfiguration;
use Tests\TestCase;

final class SyncCompanyChannelsActivityTest extends TestCase
{
    use HasNetSuiteConfiguration;

    protected Companies $buyerCompany;
    protected Companies $mainCompany;
    protected Channels $channel;
    protected Regions $buyerRegion;
    protected Apps $apps;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        $this->mainCompany = Companies::first();

        $this->apps->setAppCompany($this->mainCompany);

        $this->buyerCompany = Companies::factory()->create([
            'users_id' => auth()->id(),
            'name' => 'Test Buyer Company',
            'is_active' => 1,
        ]);

        $mainRegion = Regions::getDefault($this->mainCompany, $this->apps);

        $this->buyerRegion = Regions::create([
            'companies_id' => $this->buyerCompany->getId(),
            'apps_id' => $this->apps->getId(),
            'name' => 'Test Buyer Region',
            'slug' => 'test-buyer-region',
            'currency_id' => $mainRegion->currency_id,
            'is_default' => 1,
            'is_deleted' => 0,
        ]);

        $this->setupNetSuiteIntegration($this->buyerCompany, $this->buyerRegion);

        $this->channel = Channels::create([
            'companies_id' => $this->mainCompany->getId(),
            'apps_id' => $this->apps->getId(),
            'users_id' => auth()->id(),
            'name' => 'Test Channel for ' . $this->buyerCompany->name,
            'slug' => (string) $this->buyerCompany->uuid,
            'description' => 'Test channel for NetSuite sync',
            'is_published' => 0,
            'is_deleted' => 0,
        ]);
    }

    public function testSyncChannelsWhenCompanyIsActive(): void
    {
        $this->buyerCompany->update(['is_active' => 1]);

        $activity = new SyncCompanyChannelsActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $result = $activity->execute(
            buyerCompany: $this->buyerCompany,
            app: $this->apps,
            params: []
        );

        $this->assertTrue($result['success']);
        $this->assertEquals($this->buyerCompany->getId(), $result['company_id']);
        $this->assertEquals($this->buyerCompany->name, $result['company_name']);
        $this->assertEquals('published', $result['action']);
        $this->assertInstanceOf(Channels::class, $result['channels_affected']);

        $this->channel->refresh();
        $this->assertEquals(1, $this->channel->is_published);
    }

    public function testSyncChannelsWhenCompanyIsInactive(): void
    {
        $this->channel->update(['is_published' => 1]);
        $this->buyerCompany->update(['is_active' => 0]);

        $activity = new SyncCompanyChannelsActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $result = $activity->execute(
            buyerCompany: $this->buyerCompany,
            app: $this->apps,
            params: []
        );

        $this->assertTrue($result['success']);
        $this->assertEquals($this->buyerCompany->getId(), $result['company_id']);
        $this->assertEquals($this->buyerCompany->name, $result['company_name']);
        $this->assertEquals('unpublished', $result['action']);
        $this->assertInstanceOf(Channels::class, $result['channels_affected']);

        $this->channel->refresh();
        $this->assertEquals(0, $this->channel->is_published);
    }

    protected function tearDown(): void
    {
        $this->channel->forceDelete();
        $this->buyerCompany->forceDelete();

        parent::tearDown();
    }
}
