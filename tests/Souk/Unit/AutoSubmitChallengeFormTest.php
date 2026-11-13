<?php

declare(strict_types=1);

namespace Tests\Souk\Unit;

use Kanvas\Souk\Payments\Support\AutoSubmitChallengeForm;
use PHPUnit\Framework\TestCase;

final class AutoSubmitChallengeFormTest extends TestCase
{
    public function testRendersAnAutoSubmittingPostFormWithHiddenFields(): void
    {
        $html = AutoSubmitChallengeForm::render('https://acs.test/challenge', ['creq' => 'abc', 'TermUrl' => 'https://t.test?a=1&b=2']);

        $this->assertStringContainsString('onload="document.forms[0].submit()"', $html);
        $this->assertStringContainsString('method="POST" action="https://acs.test/challenge"', $html);
        $this->assertStringContainsString('<input type="hidden" name="creq" value="abc">', $html);
        $this->assertStringContainsString('value="https://t.test?a=1&amp;b=2"', $html);
    }

    public function testEscapesValuesThatTryToBreakOutOfTheAttribute(): void
    {
        $html = AutoSubmitChallengeForm::render('https://acs.test/"><script>x</script>', ['token' => '"><script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>', $html);
    }
}
