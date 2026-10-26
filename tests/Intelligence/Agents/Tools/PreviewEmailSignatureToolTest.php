<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\PreviewEmailSignatureTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\SetMyEmailSignatureTool;
use Kanvas\Notifications\Support\UserEmailSignature;
use Kanvas\Users\Models\Users;
use Tests\TestCase;
use Tests\Traits\MakesFilesystemRows;

final class PreviewEmailSignatureToolTest extends TestCase
{
    use DatabaseTransactions;
    use MakesFilesystemRows;

    private Users $user;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_executable(PdfService::IMAGE_BINARY_PATH)) {
            $this->markTestSkipped('wkhtmltoimage is not installed at ' . PdfService::IMAGE_BINARY_PATH);
        }

        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();
    }

    protected function tearDown(): void
    {
        $this->user->del(UserEmailSignature::companySettingKey($this->company));

        parent::tearDown();
    }

    public function testPreviewsEachDesignThenSavesExactlyTheOneApproved(): void
    {
        $logo = $this->file('logo.png', $this->company);

        $preview = $this->preview()->__invoke(designs: [
            ['name' => 'Logo left', 'html' => '<table><tr><td><img src="kanvas-file:' . $logo->getId() . '" width="80"></td><td style="color:#1A73E8"><b>Jane Doe</b><br>Sales</td></tr></table>'],
            ['name' => 'Text only', 'html' => '<p style="font-family:Arial"><b>Jane Doe</b><br>Sales<script>alert(1)</script></p>'],
        ]);
        $files = Filesystem::query()->whereIn('url', array_column($preview['previews'] ?? [], 'preview_url'))->get();

        try {
            $this->assertTrue($preview['success']);
            $this->assertSame(['Logo left', 'Text only'], array_column($preview['previews'], 'name'));
            $this->assertSame(['png'], $files->pluck('file_type')->unique()->values()->all());
            $this->assertNull($this->user->get(UserEmailSignature::companySettingKey($this->company)));

            $saved = new SetMyEmailSignatureTool()
                ->withContext(app(Apps::class), $this->company, $this->user)
                ->__invoke(draft_id: $preview['previews'][1]['draft_id']);

            $signature = UserEmailSignature::fromUser($this->user, $this->company);
            $this->assertTrue($saved['saved']);
            $this->assertSame("Jane Doe\nSales", $signature->text);
            $this->assertStringContainsString('<b>Jane Doe</b>', $signature->html);
            $this->assertStringNotContainsString('<script', $signature->html);
        } finally {
            foreach ($files as $file) {
                new FilesystemServices(app(Apps::class))->delete($file);
            }
        }
    }

    public function testRejectsAnImageThatIsNotATenantFileAndSavesNothing(): void
    {
        $foreign = $this->file('logo.png', Companies::factory()->create());

        $external = $this->preview()->__invoke(designs: [['name' => 'A', 'html' => '<img src="https://tracker.example.com/p.png"> Jane']]);
        $otherCompany = $this->preview()->__invoke(designs: [['name' => 'B', 'html' => '<img src="kanvas-file:' . $foreign->getId() . '"> Jane']]);

        $this->assertSame('invalid_args', $external['outcome']);
        $this->assertSame('A', $external['design']);
        $this->assertSame('not_found', $otherCompany['outcome']);
        $this->assertArrayNotHasKey('previews', $external);
    }

    public function testRejectsAFileThatIsNotAnImage(): void
    {
        $pdf = $this->file('contract.pdf', $this->company);

        $result = $this->preview()->__invoke(designs: [['name' => 'A', 'html' => '<img src="kanvas-file:' . $pdf->getId() . '"> Jane']]);

        $this->assertSame('invalid_args', $result['outcome']);
    }

    private function preview(): PreviewEmailSignatureTool
    {
        return new PreviewEmailSignatureTool()->withContext(app(Apps::class), $this->company, $this->user);
    }

    /**
     * The shared row has a `.test` host, which the SSRF guard cannot resolve; a signature image must pass it.
     */
    private function file(string $name, Companies $company): Filesystem
    {
        $file = $this->makeFilesystemRow($company->getId(), $name);
        $file->url = 'https://example.com/' . uniqid() . '/' . $name;
        $file->saveOrFail();

        return $file;
    }
}
