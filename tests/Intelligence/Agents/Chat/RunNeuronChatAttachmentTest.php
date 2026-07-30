<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Chat;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\Chat\RunNeuronChatAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use Tests\Stubs\Intelligence\CapturingNeuronAgentStub;
use Tests\TestCase;

/**
 * Proves a Neuron-backed agent receives every supported attachment kind natively: images, audio and
 * PDFs ride as their binary content blocks; CSV/text rides inline as a TextContent block. The whole
 * point is a "usable" agent — if any of these silently dropped, the model would never see the file.
 */
class RunNeuronChatAttachmentTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    public function testNeuronAgentReceivesImageAudioPdfAndCsvAsContentBlocks(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create([
                'name' => 'Capturing (Neuron Test)',
                'provider' => 'neuron',
                'handler' => CapturingNeuronAgentStub::class,
            ]);

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'name' => 'Capturing',
                'agent_type_id' => $agentType->getId(),
                'soul' => 'Test agent',
                'instructions' => 'Read the attached files',
                'output_format' => 'plain text',
            ]);

        $handler = new CapturingNeuronAgentStub();
        $handler->setConfiguration(agent: $agent, user: $user);

        $media = [
            $this->writeTempFile('png', $this->pngBytes()),
            $this->writeTempFile('wav', $this->wavBytes()),
            $this->writeTempFile('pdf', $this->pdfBytes()),
            $this->writeTempFile('csv', "name,age\nAlice,30\nBob,25\n"),
        ];

        $response = new RunNeuronChatAction(
            agent: $agent,
            session: null,
            message: 'Please read these files',
            app: $app,
            user: $user,
            handler: $handler,
            media: $media,
        )->execute();

        $this->assertSame('Captured reply', $response);

        $blocks = $this->capturedBlocks($handler);

        $image = $this->firstOfType($blocks, ImageContent::class);
        $this->assertNotNull($image, 'Image must be attached as an ImageContent block');
        $this->assertSame('image/png', $image->mediaType);

        $audio = $this->firstOfType($blocks, AudioContent::class);
        $this->assertNotNull($audio, 'Audio must be attached as an AudioContent block');
        $this->assertSame('audio/x-wav', $audio->mediaType);

        $pdf = $this->firstOfType($blocks, FileContent::class);
        $this->assertNotNull($pdf, 'PDF must be attached as a FileContent block');
        $this->assertSame('application/pdf', $pdf->mediaType);

        $csv = $this->firstTextContaining($blocks, 'Attached file (text/');
        $this->assertNotNull($csv, 'CSV must be embedded inline as a TextContent block');
        $this->assertStringContainsString('Alice', $csv->content);
        $this->assertStringContainsString('Bob', $csv->content);
    }

    public function testUnsupportedAttachmentTypeIsSkipped(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create(['provider' => 'neuron', 'handler' => CapturingNeuronAgentStub::class]);

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $agentType->getId()]);

        $handler = new CapturingNeuronAgentStub();
        $handler->setConfiguration(agent: $agent, user: $user);

        // A ZIP is not a native-multimodal kind — it must not become a content block.
        $zip = $this->writeTempFile('zip', "PK\x03\x04" . str_repeat("\x00", 20));

        new RunNeuronChatAction(
            agent: $agent,
            session: null,
            message: 'here is a zip',
            app: $app,
            user: $user,
            handler: $handler,
            media: [$zip],
        )->execute();

        $blocks = $this->capturedBlocks($handler);

        $this->assertNull($this->firstOfType($blocks, FileContent::class));
        $this->assertNull($this->firstOfType($blocks, ImageContent::class));
        $this->assertNull($this->firstOfType($blocks, AudioContent::class));
        // Only the user's own message text should be present, never the zip bytes.
        $this->assertNull($this->firstTextContaining($blocks, 'Attached file ('));
    }

    /**
     * @return list<ContentBlockInterface>
     */
    private function capturedBlocks(CapturingNeuronAgentStub $handler): array
    {
        $this->assertNotNull($handler->capturedProvider, 'Provider should have been invoked');

        $blocks = [];
        foreach ($handler->capturedProvider->messages as $message) {
            foreach ($message->getContentBlocks() as $block) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * @param list<ContentBlockInterface> $blocks
     * @param class-string<ContentBlockInterface> $type
     */
    private function firstOfType(array $blocks, string $type): ?ContentBlockInterface
    {
        foreach ($blocks as $block) {
            if ($block instanceof $type) {
                return $block;
            }
        }

        return null;
    }

    /**
     * @param list<ContentBlockInterface> $blocks
     */
    private function firstTextContaining(array $blocks, string $needle): ?TextContent
    {
        foreach ($blocks as $block) {
            if ($block instanceof TextContent && str_contains($block->content, $needle)) {
                return $block;
            }
        }

        return null;
    }

    private function writeTempFile(string $extension, string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'neuron_attach_') . '.' . $extension;
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function pngBytes(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
            true,
        );
    }

    private function wavBytes(): string
    {
        return 'RIFF' . pack('V', 36) . 'WAVE' . 'fmt ' . pack('V', 16) . pack('v', 1) . pack('v', 1)
            . pack('V', 8000) . pack('V', 8000) . pack('v', 1) . pack('v', 8) . 'data' . pack('V', 0);
    }

    private function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj<< /Type /Catalog >>endobj\ntrailer<< /Root 1 0 R >>\n%%EOF";
    }
}
