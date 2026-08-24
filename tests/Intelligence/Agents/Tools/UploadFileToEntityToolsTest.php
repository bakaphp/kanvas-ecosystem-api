<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UploadFileToLeadTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\UploadFileToProductTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\UploadFileToVariantTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Social\UploadFileToMessageTool;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\HasRunKey;
use Tests\TestCase;

final class UploadFileToEntityToolsTest extends TestCase
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

        $result = $this->tool(new UploadFileToLeadTool())->__invoke(
            lead_id: (int) $lead->getId(),
            file_name: 'integration-prd.md',
            content: "# PRD\n\nFull technical documentation the agent wrote.",
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame((int) $lead->getId(), $result['lead_id']);
        $this->assertSame('integration-prd.md', $result['file_name']);
        $this->assertNotEmpty($result['file_url']);

        $files = $lead->getFiles()->pluck('name')->all();
        $this->assertContains('integration-prd.md', $files);
    }

    public function test_two_uploads_on_the_same_lead_do_not_overwrite_each_other(): void
    {
        $lead = $this->makeLead();

        $this->tool(new UploadFileToLeadTool())->__invoke(
            lead_id: (int) $lead->getId(),
            file_name: 'first-doc.md',
            content: 'first',
        );

        $this->tool(new UploadFileToLeadTool())->__invoke(
            lead_id: (int) $lead->getId(),
            file_name: 'second-doc.md',
            content: 'second',
        );

        $files = $lead->getFiles()->pluck('name')->all();
        $this->assertContains('first-doc.md', $files);
        $this->assertContains('second-doc.md', $files);
    }

    public function test_upload_file_to_lead_sanitizes_a_traversing_file_name(): void
    {
        $lead = $this->makeLead();

        $result = $this->tool(new UploadFileToLeadTool())->__invoke(
            lead_id: (int) $lead->getId(),
            file_name: '../../../etc/passwd.md',
            content: 'nope',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame('passwd.md', $result['file_name']);
    }

    public function test_upload_file_to_lead_rejects_a_missing_payload(): void
    {
        $lead = $this->makeLead();

        $result = $this->tool(new UploadFileToLeadTool())->__invoke(lead_id: (int) $lead->getId());

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Nothing to upload', $result['message']);
    }

    public function test_upload_file_to_lead_rejects_both_content_and_url(): void
    {
        $lead = $this->makeLead();

        $result = $this->tool(new UploadFileToLeadTool())->__invoke(
            lead_id: (int) $lead->getId(),
            file_name: 'doc.md',
            content: 'text',
            file_url: 'https://example.com/doc.md',
        );

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('not both', $result['message']);
    }

    public function test_upload_file_to_lead_rejects_a_disallowed_extension(): void
    {
        $lead = $this->makeLead();

        $result = $this->tool(new UploadFileToLeadTool())->__invoke(
            lead_id: (int) $lead->getId(),
            file_name: 'payload.exe',
            content: 'MZ',
        );

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('file_name must be', $result['message']);
        $this->assertCount(0, $lead->getFiles());
    }

    public function test_upload_file_to_lead_rejects_a_file_name_without_extension(): void
    {
        $lead = $this->makeLead();

        $result = $this->tool(new UploadFileToLeadTool())->__invoke(
            lead_id: (int) $lead->getId(),
            file_name: 'readme',
            content: 'text',
        );

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('file_name must be', $result['message']);
    }

    public function test_upload_file_to_lead_rejects_content_over_the_size_cap(): void
    {
        $lead = $this->makeLead();

        $result = $this->tool(new UploadFileToLeadTool())->__invoke(
            lead_id: (int) $lead->getId(),
            file_name: 'huge.md',
            content: str_repeat('a', 262145),
        );

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('over the', $result['message']);
    }

    public function test_upload_file_to_lead_rejects_a_hallucinated_lead_id(): void
    {
        $result = $this->tool(new UploadFileToLeadTool())->__invoke(
            lead_id: 999999999,
            file_name: 'doc.md',
            content: 'text',
        );

        $this->assertSame('error', $result['status']);
        $this->assertArrayNotHasKey('file_url', $result);
    }

    public function test_upload_file_to_lead_does_not_write_onto_another_companys_lead(): void
    {
        $foreignLead = Lead::factory()
            ->withAppId($this->currentApp->getId())
            ->withCompanyId(Companies::factory()->create()->getId())
            ->create();

        $result = $this->tool(new UploadFileToLeadTool())->__invoke(
            lead_id: (int) $foreignLead->getId(),
            file_name: 'leak.md',
            content: 'should never land',
        );

        $this->assertSame('error', $result['status']);
        $this->assertCount(0, $foreignLead->getFiles());
    }

    public function test_upload_tools_key_their_run_budget_by_inputs(): void
    {
        $tools = [
            new UploadFileToLeadTool(),
            new UploadFileToMessageTool(),
            new UploadFileToProductTool(),
            new UploadFileToVariantTool(),
        ];

        foreach ($tools as $tool) {
            $this->assertInstanceOf(HasRunKey::class, $tool, $tool::class . ' must track runs by inputs.');

            $keyA = $tool->setInputs(['file_name' => 'a.md', 'content' => 'a'])->getRunKey();
            $keyB = $tool->setInputs(['file_name' => 'b.md', 'content' => 'b'])->getRunKey();
            $keyAAgain = $tool->setInputs(['file_name' => 'a.md', 'content' => 'a'])->getRunKey();

            $this->assertNotEquals($keyA, $keyB, $tool::class . ': distinct uploads must not share a run budget.');
            $this->assertEquals($keyA, $keyAAgain, $tool::class . ': identical calls must collapse to one key.');
        }
    }

    public function test_upload_file_to_message_attaches_to_the_message(): void
    {
        $messageType = MessageType::firstOrCreate(
            ['apps_id' => $this->currentApp->getId(), 'languages_id' => 1, 'verb' => 'upload-tool-test'],
            ['name' => 'Upload Tool Test']
        );

        $message = Message::factory()
            ->withAppId($this->currentApp->getId())
            ->withCompanyId($this->currentCompany->getId())
            ->withMessageType($messageType)
            ->create();

        $result = $this->tool(new UploadFileToMessageTool())->__invoke(
            message_id: (int) $message->getId(),
            file_name: 'handover-notes.md',
            content: '## Notes',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame((int) $message->getId(), $result['message_id']);
        $this->assertContains('handover-notes.md', $message->getFiles()->pluck('name')->all());
    }

    public function test_upload_file_to_product_and_variant_attach_to_the_catalog(): void
    {
        $product = $this->makeProduct();

        $productResult = $this->tool(new UploadFileToProductTool())->__invoke(
            product_id: (int) $product->getId(),
            file_name: 'spec-sheet.md',
            content: '# Spec',
        );

        $this->assertSame('success', $productResult['status']);
        $this->assertContains('spec-sheet.md', $product->getFiles()->pluck('name')->all());

        /** @var Variants $variant */
        $variant = $product->variants()->firstOrFail();

        $variantResult = $this->tool(new UploadFileToVariantTool())->__invoke(
            variant_id: (int) $variant->getId(),
            file_name: 'sku-certificate.md',
            content: '# Certificate',
        );

        $this->assertSame('success', $variantResult['status']);
        $this->assertContains('sku-certificate.md', $variant->getFiles()->pluck('name')->all());
    }

    public function test_upload_file_to_product_denies_a_non_admin_requester(): void
    {
        $product = $this->makeProduct();

        $result = $this->tool(new UploadFileToProductTool())
            ->forRequestingUser(Users::factory()->create())
            ->__invoke(
                product_id: (int) $product->getId(),
                file_name: 'spec-sheet.md',
                content: '# Spec',
            );

        $this->assertFalse($result['created']);
        $this->assertStringContainsString('administrator', $result['message']);
        $this->assertCount(0, $product->getFiles());
    }

    private function tool(object $tool): object
    {
        return $tool->withContext($this->currentApp, $this->currentCompany, $this->actingUser);
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
