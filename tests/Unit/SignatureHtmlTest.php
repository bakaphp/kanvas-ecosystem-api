<?php

declare(strict_types=1);

namespace Tests\Unit;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Notifications\Support\SignatureHtml;
use Tests\TestCaseUnit;

final class SignatureHtmlTest extends TestCaseUnit
{
    public function testKeepsEmailLayoutAndDropsEverythingThatRunsOrLoadsOnItsOwn(): void
    {
        $clean = SignatureHtml::sanitize(
            '<table cellpadding="0" style="font-family:Arial"><tr>'
            . '<td style="color:#1A73E8;background:url(http://169.254.169.254/x)"><b>Jane</b><script>alert(1)</script></td>'
            . '<td style="color:#333" onclick="steal()"><a href="javascript:alert(1)">bad</a><a href="mailto:jane@example.com">mail</a></td>'
            . '</tr></table><style>body{display:none}</style>'
        );

        $this->assertStringContainsString('<table cellpadding="0" style="font-family:Arial">', $clean);
        $this->assertStringContainsString('<b>Jane</b>', $clean);
        $this->assertStringContainsString('style="color:#333"', $clean);
        $this->assertStringContainsString('mailto:', $clean);
        foreach (['url(', '<script', 'onclick', 'javascript:', '<style'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $clean);
        }
    }

    public function testImagesComeOnlyFromResolvedTenantFiles(): void
    {
        $html = '<img src="kanvas-file:7" width="80"> Jane';

        $this->assertSame([7], SignatureHtml::imageFileIds($html));
        $this->assertStringContainsString(
            'src="https://cdn.example.com/logo.png"',
            SignatureHtml::sanitize($html, [7 => 'https://cdn.example.com/logo.png'])
        );
    }

    public function testAnImageFromAnyOtherSourceFailsTheWholeSignature(): void
    {
        $this->expectException(ValidationException::class);

        SignatureHtml::sanitize('<img src="https://tracker.example.com/pixel.png"> Jane');
    }

    public function testAnUnresolvedFilePlaceholderFails(): void
    {
        $this->expectException(ValidationException::class);

        SignatureHtml::sanitize('<img src="kanvas-file:9"> Jane', [7 => 'https://cdn.example.com/logo.png']);
    }

    public function testNothingLeftAfterCleaningIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        SignatureHtml::sanitize('<script>alert(1)</script>');
    }

    public function testPlainTextKeepsOneItemPerLine(): void
    {
        $this->assertSame(
            "Jane Doe\nSales & Ops\n+1 809 555 0000",
            SignatureHtml::toText('<table><tr><td><b>Jane Doe</b></td></tr><tr><td>Sales &amp; Ops<br>+1 809 555 0000</td></tr></table>')
        );
    }
}
