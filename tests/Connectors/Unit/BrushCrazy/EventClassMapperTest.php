<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\BrushCrazy;

use Kanvas\Connectors\BrushCrazy\Enums\EventClassEnum;
use Kanvas\Connectors\BrushCrazy\Mappers\EventClassMapper;
use Tests\TestCase;

final class EventClassMapperTest extends TestCase
{
    private function row(array $overrides = []): object
    {
        return (object) array_merge([
            'name' => 'Sunset Canvas',
            'private' => false,
            'fundraiser' => 0,
        ], $overrides);
    }

    public function testClassifiesEachBookingModel(): void
    {
        $this->assertSame(EventClassEnum::DROP_IN, EventClassMapper::resolve($this->row(['name' => 'Drop-In & Paint 1-8pm'])));
        $this->assertSame(EventClassEnum::PRIVATE, EventClassMapper::resolve($this->row(['private' => true])));
        $this->assertSame(EventClassEnum::FUNDRAISER, EventClassMapper::resolve($this->row(['fundraiser' => 3])));
        $this->assertSame(EventClassEnum::PUBLIC, EventClassMapper::resolve($this->row()));
    }

    /**
     * Precedence is load-bearing: drop-in rows are sometimes also flagged private, and private
     * parties are often fundraisers. Reordering the checks silently reclassifies real rows.
     */
    public function testPrecedenceHoldsWhenFlagsOverlap(): void
    {
        $this->assertSame(
            EventClassEnum::DROP_IN,
            EventClassMapper::resolve($this->row(['name' => 'Walk in & Paint', 'private' => true, 'fundraiser' => 2])),
        );

        $this->assertSame(
            EventClassEnum::PRIVATE,
            EventClassMapper::resolve($this->row(['private' => true, 'fundraiser' => 2])),
        );
    }

    public function testHandlesLegacyTruthyRepresentations(): void
    {
        $this->assertSame(EventClassEnum::PRIVATE, EventClassMapper::resolve($this->row(['private' => 1])));
        $this->assertSame(EventClassEnum::PUBLIC, EventClassMapper::resolve($this->row(['private' => 0, 'fundraiser' => 0])));
    }

    public function testMissingColumnsFallBackToPublic(): void
    {
        $this->assertSame(EventClassEnum::PUBLIC, EventClassMapper::resolve((object) ['name' => 'Sunset Canvas']));
    }
}
