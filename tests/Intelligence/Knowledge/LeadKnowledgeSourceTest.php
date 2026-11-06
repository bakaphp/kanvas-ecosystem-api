<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use App\Console\Commands\Intelligence\Knowledge\PruneLeadMessageKnowledgeCommand;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeDocument;
use Kanvas\Intelligence\Knowledge\Sources\LeadKnowledgeSource;
use Mockery;
use Tests\TestCase;

class LeadKnowledgeSourceTest extends TestCase
{
    public function testBuildsStableTenantScopedProfileDocuments(): void
    {
        $app = Mockery::mock(Apps::class)->makePartial();
        $app->id = 11;
        $app->shouldReceive('get')->andReturnNull();

        $company = new Companies([
            'id' => 22,
            'name' => 'Acme Dominicana',
            'website' => 'https://acme.test',
        ]);
        $company->id = 22;
        $people = new People([
            'id' => 33,
            'name' => 'Ada Lovelace',
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'email' => 'ada@example.test',
            'phone' => '8095550101',
        ]);
        $people->id = 33;
        $organization = new Organization([
            'id' => 44,
            'name' => 'Analytical Engines',
        ]);
        $organization->id = 44;
        $lead = new Lead([
            'id' => 55,
            'apps_id' => 11,
            'companies_id' => 22,
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'title' => 'Expansion opportunity',
            'email' => 'ada@example.test',
            'phone' => '8095550101',
            'description' => 'Interested in enterprise automation.',
        ]);
        $lead->id = 55;
        $lead->setRelation('app', $app);
        $lead->setRelation('company', $company);
        $lead->setRelation('people', $people);
        $lead->setRelation('organization', $organization);

        $documents = new LeadKnowledgeSource()->build($lead);

        $this->assertCount(3, $documents, 'Cards only: no person card, no messages');
        $this->assertSame(
            ['lead-55-lead-55', 'lead-55-company-22', 'lead-55-organization-44'],
            array_map(static fn (KnowledgeDocument $document): string => $document->id, $documents),
        );
        foreach ($documents as $document) {
            $this->assertNotContains($document->metadata['source_type'], PruneLeadMessageKnowledgeCommand::PRUNED_SOURCE_TYPES, 'The cleanup must never match a card');
            $this->assertSame(11, $document->metadata['apps_id']);
            $this->assertSame(22, $document->metadata['companies_id']);
            $this->assertSame(Lead::class, $document->metadata['entity_type']);
            $this->assertSame(55, $document->metadata['entity_id']);
            $this->assertNotSame('', $document->content);
        }
    }

    public function testTheCleanupFilterIsPinnedToOneAppLeadsAndThePrunedKinds(): void
    {
        $app = new Apps();
        $app->id = 11;

        $this->assertSame(
            'apps_id:=11 && entity_type:=`Kanvas\\\\Guild\\\\Leads\\\\Models\\\\Lead` && source_type:=[`message`, `note`, `people`]',
            PruneLeadMessageKnowledgeCommand::filterFor($app),
        );
    }
}
