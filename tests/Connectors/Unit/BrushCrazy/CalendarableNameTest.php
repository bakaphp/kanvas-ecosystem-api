<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\BrushCrazy;

use Kanvas\Connectors\BrushCrazy\Support\CalendarableName;
use Tests\TestCase;

/**
 * The stored-name variants here are the ones the source's own docblock calls out. Drop-in sessions
 * are the highest-volume row in BrushCrazy and the Event grouping key is built from the canonical
 * name, so a variant that stops matching splits one Event into hundreds.
 */
final class CalendarableNameTest extends TestCase
{
    public function testRecognisesEveryStoredDropInVariant(): void
    {
        foreach ([
            'Walk in & Paint 1-8pm',
            'Drop-In and Paint',
            'Drop-N & Paint! 12-6pm',
            'Drop In Open Paint',
            'drop-in paint',
            'Walk-In + Paint',
        ] as $name) {
            $this->assertTrue(CalendarableName::isDropInPaint($name), "expected drop-in: {$name}");
        }
    }

    public function testDoesNotMatchUnrelatedNames(): void
    {
        foreach ([
            'Sunset Canvas',
            'Paint Night',
            'Kids Drop Off Painting',
            '',
            null,
        ] as $name) {
            $this->assertFalse(CalendarableName::isDropInPaint($name), 'unexpected drop-in match');
        }
    }

    public function testCanonicalisesLabelAndKeepsTrailingDetail(): void
    {
        $this->assertSame('Drop-In & Paint 1-8pm', CalendarableName::displayName('Walk in & Paint 1-8pm'));
        $this->assertSame('Drop-In & Paint! 12-6pm', CalendarableName::displayName('Drop-N & Paint! 12-6pm'));
        $this->assertSame('Drop-In & Paint', CalendarableName::displayName('Drop In Open Paint'));
    }

    public function testLeavesNonDropInNamesUntouchedApartFromTrimming(): void
    {
        $this->assertSame('Sunset Canvas', CalendarableName::displayName('  Sunset Canvas  '));
        $this->assertSame('', CalendarableName::displayName(null));
    }

    /** Every variant has to collapse to one grouping key or the Event split happens anyway. */
    public function testAllVariantsShareOneCanonicalLabel(): void
    {
        $canonical = array_unique(array_map(
            fn (string $name) => CalendarableName::displayName($name),
            ['Walk in & Paint', 'Drop-In and Paint', 'Drop-N & Paint', 'Drop In Open Paint'],
        ));

        $this->assertSame(['Drop-In & Paint'], array_values($canonical));
    }
}
