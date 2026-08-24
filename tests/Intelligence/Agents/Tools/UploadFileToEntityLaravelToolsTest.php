<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Laravel\Tools\Guild\UploadFileToLeadTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\UploadFileToProductTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\UploadFileToVariantTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Social\UploadFileToMessageTool;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

final class UploadFileToEntityLaravelToolsTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'social', 'inventory'];

    private Apps $currentApp;
    private Companies $currentCompany;
    private Users $actingUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $this->actingUser = $user;
        $this->currentCompany = $user->getCurrentCompany();
    }

    public function test_upload_file_to_lead_stores_inline_content_as_a_real_file(): void
    {
        $lead = $this->makeLead();

        $result = $this->invoke(new UploadFileToLeadTool(), [
            'lead_id' => (int) $lead->getId(),
            'file_name' => 'integration-prd.md',
            'content' => "# PRD\n\nWritten by the Laravel agent.",
        ]);

        $this->assertSame('success', $result['status']);
        $this->assertSame((int) $lead->getId(), $result['lead_id']);
        $this->assertContains('integration-prd.md', $lead->getFiles()->pluck('name')->all());
    }

    public function test_upload_file_to_lead_rejects_a_missing_payload(): void
    {
        $lead = $this->makeLead();

        $result = $this->invoke(new UploadFileToLeadTool(), ['lead_id' => (int) $lead->getId()]);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Nothing to upload', $result['message']);
    }

    public function test_upload_file_to_lead_rejects_a_disallowed_extension(): void
    {
        $lead = $this->makeLead();

        $result = $this->invoke(new UploadFileToLeadTool(), [
            'lead_id' => (int) $lead->getId(),
            'file_name' => 'payload.exe',
            'content' => 'MZ',
        ]);

        $this->assertSame('error', $result['status']);
        $this->assertCount(0, $lead->getFiles());
    }

    public function test_upload_file_to_lead_does_not_write_onto_another_companys_lead(): void
    {
        $foreignLead = Lead::factory()
            ->withAppId($this->currentApp->getId())
            ->withCompanyId(Companies::factory()->create()->getId())
            ->create();

        $raw = $this->handle(new UploadFileToLeadTool(), [
            'lead_id' => (int) $foreignLead->getId(),
            'file_name' => 'leak.md',
            'content' => 'should never land',
        ]);

        $this->assertStringContainsString('does not exist in this company', $raw);
        $this->assertCount(0, $foreignLead->getFiles());
    }

    public function test_upload_file_to_message_attaches_to_the_message(): void
    {
        $messageType = MessageType::firstOrCreate(
            ['apps_id' => $this->currentApp->getId(), 'languages_id' => 1, 'verb' => 'upload-tool-laravel-test'],
            ['name' => 'Upload Tool Laravel Test']
        );

        $message = Message::factory()
            ->withAppId($this->currentApp->getId())
            ->withCompanyId($this->currentCompany->getId())
            ->withMessageType($messageType)
            ->create();

        $result = $this->invoke(new UploadFileToMessageTool(), [
            'message_id' => (int) $message->getId(),
            'file_name' => 'handover-notes.md',
            'content' => '## Notes',
        ]);

        $this->assertSame('success', $result['status']);
        $this->assertContains('handover-notes.md', $message->getFiles()->pluck('name')->all());
    }

    public function test_upload_file_to_product_and_variant_attach_to_the_catalog(): void
    {
        $product = $this->makeProduct();

        $productResult = $this->invoke(new UploadFileToProductTool(), [
            'product_id' => (int) $product->getId(),
            'file_name' => 'spec-sheet.md',
            'content' => '# Spec',
        ]);

        $this->assertSame('success', $productResult['status']);
        $this->assertContains('spec-sheet.md', $product->getFiles()->pluck('name')->all());

        /** @var Variants $variant */
        $variant = $product->variants()->firstOrFail();

        $variantResult = $this->invoke(new UploadFileToVariantTool(), [
            'variant_id' => (int) $variant->getId(),
            'file_name' => 'sku-certificate.md',
            'content' => '# Certificate',
        ]);

        $this->assertSame('success', $variantResult['status']);
        $this->assertContains('sku-certificate.md', $variant->getFiles()->pluck('name')->all());
    }

    public function test_upload_file_to_product_denies_a_non_admin_actor(): void
    {
        $product = $this->makeProduct();

        auth()->setUser(Users::factory()->create());

        $raw = $this->handle(new UploadFileToProductTool(), [
            'product_id' => (int) $product->getId(),
            'file_name' => 'spec-sheet.md',
            'content' => '# Spec',
        ]);

        $this->assertStringContainsString('administrator', $raw);
        $this->assertCount(0, $product->getFiles());
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function handle(object $tool, array $arguments): string
    {
        return (string) $tool
            ->withContext($this->currentApp, $this->currentCompany)
            ->handle(new Request($arguments));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function invoke(object $tool, array $arguments): array
    {
        return json_decode($this->handle($tool, $arguments), true);
    }

    private function makeLead(): Lead
    {
        return Lead::factory()
            ->withAppAndCompany($this->currentApp->getId(), $this->currentCompany->getId())
            ->create();
    }

    private function makeProduct(): Products
    {
        new InventorySetup($this->currentApp, $this->actingUser, $this->currentCompany)->run();

        return new CreateProductAction(
            new ProductDto(
                app: $this->currentApp,
                company: $this->currentCompany,
                user: $this->actingUser,
                name: 'Upload Tool Product ' . uniqid(),
                sku: 'UPL-' . uniqid(),
            ),
            $this->actingUser
        )->execute();
    }
}
