<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Neuron\RAG\KnowledgeDocumentBuilder;
use Mockery;
use Tests\TestCase;

class LeadKnowledgeDocumentBuilderTest extends TestCase
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
        // Relations may be supplied as a base Support collection by callers/tests, not only
        // EloquentCollection (which is the only collection exposing modelKeys()).
        $lead->setRelation('socialChannels', new Collection());

        $documents = new KnowledgeDocumentBuilder()->build($lead);

        $this->assertCount(4, $documents);
        $this->assertSame(
            ['lead-55-lead-55-0', 'lead-55-people-33-0', 'lead-55-company-22-0', 'lead-55-organization-44-0'],
            array_map(fn ($document): string => (string) $document->getId(), $documents)
        );
        foreach ($documents as $document) {
            $this->assertSame(Lead::class, $document->getSourceType());
            $this->assertSame(Lead::class . ':11:22:55', $document->getSourceName());
            $this->assertSame(11, $document->metadata['apps_id']);
            $this->assertSame(22, $document->metadata['companies_id']);
            $this->assertSame(Lead::class, $document->metadata['entity_type']);
            $this->assertSame(55, $document->metadata['entity_id']);
            $this->assertNotSame('', $document->getContent());
        }
    }
}
