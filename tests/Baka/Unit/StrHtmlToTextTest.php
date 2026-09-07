<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Support\Str;
use Tests\TestCaseUnit;

final class StrHtmlToTextTest extends TestCaseUnit
{
    public function testBlocksBecomeBreaksRatherThanWeldingWordsTogether(): void
    {
        $this->assertSame(
            "Santo Domingo.\nOverview",
            Str::htmlToText('<p>Santo Domingo.</p><p>Overview</p>')
        );
    }

    public function testAnchorMarkupDoesNotEatTheTruncationBudget(): void
    {
        $html = '<p>Cabinet is a cigar shop. [<a target="_blank" rel="noopener noreferrer nofollow" '
            . 'href="https://example.com/a-very-long-path">1</a>]</p>';

        $this->assertSame('Cabinet is a cigar shop. [1]', Str::htmlToText($html));
    }

    public function testEntitiesAndNonBreakingSpacesAreDecoded(): void
    {
        $this->assertSame('Overview & Locations', Str::htmlToText('<strong>Overview &amp;&nbsp;Locations</strong>'));
    }

    public function testNullAndPlainTextAreSafe(): void
    {
        $this->assertSame('', Str::htmlToText(null));
        $this->assertSame('already plain', Str::htmlToText('  already plain  '));
    }
}
