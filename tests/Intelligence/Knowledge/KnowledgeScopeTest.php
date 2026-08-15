<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeEntity;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeScope;
use Tests\TestCase;

class KnowledgeScopeTest extends TestCase
{
    public function testTenantScopeAlwaysPinsCompanyAndExcludesEntityRows(): void
    {
        $scope = new KnowledgeScope(appId: 11, companyId: 22);

        $this->assertFalse($scope->isEntityScoped());
        // companies_id present closes the cross-company leak; entity_id:=0 keeps a
        // company-wide search from returning any entity's (e.g. a Lead's) rows.
        $this->assertSame('apps_id:=11 && companies_id:=22 && entity_id:=0', $scope->filter());
    }

    public function testEntityScopePinsTypeAndId(): void
    {
        $scope = new KnowledgeScope(
            appId: 11,
            companyId: 22,
            entityType: Lead::class,
            entityId: 55,
        );

        $this->assertTrue($scope->isEntityScoped());
        $this->assertSame(
            'apps_id:=11 && companies_id:=22 && entity_type:=`Kanvas\\\\Guild\\\\Leads\\\\Models\\\\Lead` && entity_id:=55',
            $scope->filter(),
        );
    }

    public function testFromEntityBuildsEntityScopedFilter(): void
    {
        $entity = new KnowledgeEntity(type: Lead::class, id: 55, appId: 11, companyId: 22);
        $scope = KnowledgeScope::fromEntity($entity);

        $this->assertTrue($scope->isEntityScoped());
        $this->assertSame(11, $scope->appId);
        $this->assertSame(22, $scope->companyId);
        $this->assertSame(Lead::class, $scope->entityType);
        $this->assertSame(55, $scope->entityId);
    }

    public function testTenantAndEntityFiltersNeverCollide(): void
    {
        $tenant = new KnowledgeScope(appId: 11, companyId: 22)->filter();
        $entity = new KnowledgeScope(appId: 11, companyId: 22, entityType: Lead::class, entityId: 55)->filter();

        // A tenant search requires entity_id:=0; an entity's rows carry entity_id>=1,
        // so the two scopes are mutually exclusive within one shared collection.
        $this->assertStringContainsString('entity_id:=0', $tenant);
        $this->assertStringNotContainsString('entity_id:=0', $entity);
    }
}
