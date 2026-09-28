<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\SystemModules;

use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\SystemModules\Models\SystemModules;
use Tests\TestCase;

/**
 * Regression for KANVAS-ECOSYSTEM-65X: a message on a Deal's social channel broadcasts via
 * ChannelMessageCreatedEvent, which resolves the channel slug by the entity namespace. Deal was
 * missing from the mapping, throwing "Namespace Kanvas\Guild\Deals\Models\Deal not found".
 */
final class SystemModulesDealMappingTest extends TestCase
{
    public function testDealNamespaceResolvesToSlug(): void
    {
        $this->assertSame('deal', SystemModules::getSlugBySystemModuleNameSpace(Deal::class));
    }

    public function testDealSlugResolvesToNamespace(): void
    {
        $this->assertSame(Deal::class, SystemModules::getSystemModuleNameSpaceBySlug('deal'));
    }
}
