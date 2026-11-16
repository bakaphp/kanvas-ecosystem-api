<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\BrushCrazy;

use Kanvas\Connectors\BrushCrazy\Actions\ResolveStudioContextsAction;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class StudioNameTest extends TestCase
{
    public function testARealStudioIsNamedByItsLocality(): void
    {
        $this->assertSame('Great Falls', $this->nameOf([
            'id' => 1,
            'community' => null,
            'locality' => 'Great Falls',
            'administrative_area_level_1' => 'MT',
        ]));
    }

    /**
     * Regression: studio 0 has no community or locality and keeps "Corporate" in the state column,
     * so it was imported as "Studio 0".
     */
    public function testTheCorporateStudioIsNamedFromTheStateColumn(): void
    {
        $this->assertSame('Corporate', $this->nameOf([
            'id' => 0,
            'community' => null,
            'locality' => null,
            'administrative_area_level_1' => 'Corporate',
        ]));
    }

    public function testAStudioWithNoNamingColumnsFallsBackToItsId(): void
    {
        $this->assertSame('Studio 7', $this->nameOf([
            'id' => 7,
            'community' => null,
            'locality' => null,
            'administrative_area_level_1' => null,
        ]));
    }

    private function nameOf(array $studio): string
    {
        $reflection = new ReflectionClass(ResolveStudioContextsAction::class);

        return $reflection->getMethod('studioName')->invoke(
            $reflection->newInstanceWithoutConstructor(),
            (object) $studio
        );
    }
}
