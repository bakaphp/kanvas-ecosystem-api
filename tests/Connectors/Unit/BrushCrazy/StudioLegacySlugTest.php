<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\BrushCrazy;

use Kanvas\Connectors\BrushCrazy\Actions\SyncStudioProfileFromBrushCrazyAction;
use PHPUnit\Framework\TestCase;

final class StudioLegacySlugTest extends TestCase
{
    public function testMatchesTheBrushCrazyLocationUrlSegment(): void
    {
        $this->assertSame('great-falls-mt', $this->slug('Great Falls', 'MT'));
        $this->assertSame('colorado-springs-co', $this->slug('Colorado Springs', 'CO'));
    }

    public function testMultiWordStateAndCommunityAreJoinedWithoutDashes(): void
    {
        $this->assertSame('santa-fe-newmexico-oldtown', $this->slug('Santa Fe', 'New Mexico', 'Old Town'));
    }

    public function testCorporateHasNoLocalityAndNoSlug(): void
    {
        $this->assertNull($this->slug(null, 'Corporate'));
    }

    private function slug(?string $locality, ?string $state, ?string $community = null): ?string
    {
        return SyncStudioProfileFromBrushCrazyAction::legacySlug((object) [
            'locality' => $locality,
            'administrative_area_level_1' => $state,
            'community' => $community,
        ]);
    }
}
