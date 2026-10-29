<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Notifications;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Notifications\Support\UserEmailSignature;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;
use Tests\TestCase;
use Tests\Traits\MakesTemplates;

final class UserSignatureInTemplateTest extends TestCase
{
    use DatabaseTransactions;
    use MakesTemplates;

    protected $connectionsToTransact = [null, 'ecosystem'];

    public function testATemplateThatOnlyRendersContentGetsTheSignatureRightUnderTheMessage(): void
    {
        $body = $this->render('<div>{{ content|raw }}</div><footer>Unsubscribe</footer>');

        $this->assertSame(1, substr_count($body, UserEmailSignature::MARKER));
        $this->assertLessThan(strpos($body, '<footer>'), strpos($body, UserEmailSignature::MARKER));
        $this->assertGreaterThan(strpos($body, 'Hello there'), strpos($body, UserEmailSignature::MARKER));
    }

    public function testATemplateThatPlacesTheSignatureItselfIsNotSignedTwice(): void
    {
        $body = $this->render('<div>{{ user_signature|raw }}</div><div>{{ content|raw }}</div>');

        $this->assertSame(1, substr_count($body, UserEmailSignature::MARKER));
        $this->assertLessThan(strpos($body, 'Hello there'), strpos($body, UserEmailSignature::MARKER));
    }

    public function testATemplateThatIgnoresContentStillDeliversTheSignature(): void
    {
        $body = $this->render('<p>Static announcement</p>');

        $this->assertSame(1, substr_count($body, UserEmailSignature::MARKER));
        $this->assertStringContainsString('Jane Signer', $body);
    }

    public function testNoSignatureLeavesTheTemplateUntouched(): void
    {
        $body = $this->render('<div>{{ content|raw }}</div>', withSignature: false);

        $this->assertStringNotContainsString(UserEmailSignature::MARKER, $body);
    }

    private function render(string $templateBody, bool $withSignature = true): string
    {
        /** @var Users $user */
        $user = auth()->user();
        $template = $this->makeTemplate($templateBody);

        $notification = new Blank(
            $template->name,
            ['content' => '<p>Hello there</p>'],
            ['mail'],
            $user,
        );
        $notification->setUserSignature($withSignature ? new UserEmailSignature("Jane Signer\nSales") : null);

        return $notification->getEmailContent();
    }
}
