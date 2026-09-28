<?php

declare(strict_types=1);

namespace Tests\Connectors\UniversalSeguros;

use Kanvas\Connectors\UniversalSeguros\Enums\ConfigurationEnum;
use Kanvas\Connectors\UniversalSeguros\Enums\ProductEnum;
use Tests\TestCase;

/**
 * Regression: the hardcoded scope string only covered 3 of the 5 products, so A-PC
 * and A-PT would have failed at emit time — after the customer had paid.
 */
class ConfigurationScopesTest extends TestCase
{
    public function testDefaultScopesCoverEveryProductWeCanSell(): void
    {
        $scopes = ConfigurationEnum::defaultScopes();

        foreach (ProductEnum::cases() as $product) {
            $this->assertStringContainsString(
                $product->emitScope(),
                $scopes,
                $product->name . ' cannot be emitted with the default scopes'
            );
        }
    }

    public function testDefaultScopesKeepTheNonEmissionScopes(): void
    {
        $scopes = ConfigurationEnum::defaultScopes();

        $this->assertStringContainsString('unit.serviceplattform.externos', $scopes);
        $this->assertStringContainsString('unit.serviceplattform.cotizaciones', $scopes);
        $this->assertStringContainsString('unit.serviceplattform.polizas', $scopes);
    }

    public function testScopesAreSpaceSeparatedWithNoDuplicates(): void
    {
        $scopes = explode(' ', ConfigurationEnum::defaultScopes());

        $this->assertSame($scopes, array_values(array_unique($scopes)));
        $this->assertNotContains('', $scopes);
    }
}
