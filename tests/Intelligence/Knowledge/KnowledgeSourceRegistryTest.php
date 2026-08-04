<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Knowledge\Contracts\KnowledgeSource;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeSourceRegistry;
use Tests\TestCase;

class KnowledgeSourceRegistryTest extends TestCase
{
    public function testOnlyExplicitlyRegisteredEntityTypesAreSupported(): void
    {
        $source = new class () implements KnowledgeSource {
            public function entityType(): string
            {
                return Lead::class;
            }

            public function build(Model $entity): array
            {
                return [];
            }
        };

        $registry = new KnowledgeSourceRegistry([$source]);

        $this->assertSame($source, $registry->for(Lead::class));
        $this->assertSame($source, $registry->for('lead'));
        $this->assertSame(['lead'], $registry->aliases());
        $this->assertNull($registry->for(Model::class));
    }
}
