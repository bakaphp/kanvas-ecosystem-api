<?php

declare(strict_types=1);

namespace Tests\Unit;

use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Notifications\Support\UserEmailSignature;
use Kanvas\Users\Models\Users;
use Mockery;
use Tests\TestCaseUnit;

final class UserEmailSignatureTest extends TestCaseUnit
{
    public function testRendersEnabledSignatureWithOwnersPhoto(): void
    {
        $user = $this->user(['append' => true, 'text' => "Snowy Dong\nBest Sales in the Milky Way\n123-456-7890"], 'https://example.com/owner.png');
        $signature = UserEmailSignature::fromUser($user);
        $html = $signature->toHtml();

        $this->assertStringContainsString('Snowy Dong', $html);
        $this->assertStringContainsString('Best Sales in the Milky Way', $html);
        $this->assertStringContainsString('123-456-7890', $html);
        $this->assertStringContainsString('src="https://example.com/owner.png"', $html);
        $this->assertStringContainsString('border-radius: 50%', $html);
        $this->assertStringContainsString('width="96"', $html);
        $this->assertStringNotContainsString('Sally', $html);
        $this->assertStringNotContainsString("\n", $html);
    }

    public function testNoPhotoOmitsImageAndDivider(): void
    {
        $html = UserEmailSignature::fromUser($this->user(['append' => true, 'text' => "Owner\nSales"]))->toHtml();
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('padding-left: 18px', $html);
        $this->assertStringNotContainsString('background-color: #dddddd', $html);
    }

    public function testMissingDisabledAndMalformedSignaturesAreOmittedWithoutLookingUpPhoto(): void
    {
        $this->assertNull(UserEmailSignature::fromUser(null));
        foreach ([null, '', 'invalid json', ['append' => false, 'text' => 'Owner'], ['append' => 'false', 'text' => 'Owner'], ['append' => true, 'text' => '  '], ['text' => 'Owner'], ['append' => true, 'text' => []]] as $setting) {
            $user = Mockery::mock(Users::class);
            $user->shouldReceive('get')->with('email_signature')->once()->andReturn($setting);
            $user->shouldNotReceive('getFileByName');
            $this->assertNull(UserEmailSignature::fromUser($user));
        }
    }

    public function testJsonSettingAndHtmlEscaping(): void
    {
        $user = $this->user(json_encode(['append' => true, 'text' => "Owner <script>alert(1)</script>\nA & B"]));
        $html = UserEmailSignature::fromUser($user)->toHtml();
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('A &amp; B', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testUnsafePhotoUrlIsOmitted(): void
    {
        foreach (['javascript:alert(1)', 'data:image/png;base64,abc', '/private/photo.png'] as $url) {
            $html = UserEmailSignature::fromUser($this->user(['append' => true, 'text' => 'Owner'], $url))->toHtml();
            $this->assertStringNotContainsString('<img', $html);
        }
    }

    private function user(mixed $setting, ?string $url = null): Users
    {
        $user = Mockery::mock(Users::class);
        $user->shouldReceive('get')->with('email_signature')->once()->andReturn($setting);
        $photo = null;
        if ($url !== null) {
            $file = new Filesystem();
            $file->url = $url;
            $photo = new FilesystemEntities();
            $photo->setRelation('filesystem', $file);
        }
        $user->shouldReceive('getFileByName')->with('photo')->once()->andReturn($photo);
        $user->shouldNotReceive('getPhoto');

        return $user;
    }
}
