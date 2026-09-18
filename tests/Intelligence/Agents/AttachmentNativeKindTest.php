<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Filesystem\Services\FileTextExtractor;
use Kanvas\Intelligence\Agents\Services\AttachmentDescriptionService;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regression for the Slack "paste the JSON in the chat instead" reply: finfo labels a well-formed
 * .json as application/json, which nativeKind() did not classify, so the bytes were dropped and the
 * agent only ever saw the attachment's URL. A .txt in the same message worked, which is what made it
 * look like a Slack problem rather than a MIME-classification one.
 */
class AttachmentNativeKindTest extends TestCase
{
    public static function textualMimeTypeProvider(): array
    {
        return [
            'json' => ['application/json'],
            'ndjson' => ['application/x-ndjson'],
            'xml (application)' => ['application/xml'],
            'xml (text)' => ['text/xml'],
            'yaml' => ['application/yaml'],
            'sql' => ['application/sql'],
            'javascript' => ['application/javascript'],
            'shell' => ['application/x-sh'],
            'csv' => ['application/csv'],
            'plain' => ['text/plain'],
            'structured json suffix' => ['application/vnd.api+json'],
            'structured xml suffix' => ['application/atom+xml'],
            'charset parameter' => ['text/plain; charset=utf-8'],
            'uppercase' => ['APPLICATION/JSON'],
        ];
    }

    #[DataProvider('textualMimeTypeProvider')]
    public function testTextualMimeTypesRideAsText(string $mimeType): void
    {
        $this->assertSame('text', AttachmentDescriptionService::nativeKind($mimeType));
    }

    public function testBinaryKindsAreUnchanged(): void
    {
        $this->assertSame('image', AttachmentDescriptionService::nativeKind('image/png'));
        $this->assertSame('audio', AttachmentDescriptionService::nativeKind('audio/mpeg'));
        $this->assertSame('pdf', AttachmentDescriptionService::nativeKind('application/pdf'));
    }

    /** Nothing here has a parser or a native block, so it must stay URL-in-prompt. */
    public function testNonTextualTypesStayUnsupported(): void
    {
        $this->assertNull(AttachmentDescriptionService::nativeKind('application/zip'));
        $this->assertNull(AttachmentDescriptionService::nativeKind('video/mp4'));
        $this->assertNull(AttachmentDescriptionService::nativeKind('application/octet-stream'));
    }

    /**
     * The extractor reads DOCX/XLSX and no content block can carry them, so an internal agent gets
     * them as extracted text — the gap that had read_file parsing a file the prompt path dropped.
     */
    public function testOfficeDocumentsAreReadableForInternalAgentsOnly(): void
    {
        $docx = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        $xlsx = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        foreach ([$docx, $xlsx, 'application/vnd.ms-excel'] as $mimeType) {
            $this->assertSame('text', AttachmentDescriptionService::nativeKind($mimeType));
            $this->assertNull(
                AttachmentDescriptionService::nativeKind($mimeType, allowStructuredText: false),
                $mimeType . ' must not reach a customer-facing prompt',
            );
        }
    }

    /** Both doors now cover the same formats; they used to disagree in both directions. */
    public function testTheInlinePathAndTheReadFileToolAgree(): void
    {
        foreach (['application/json', 'application/xml', 'application/yaml', 'application/x-sh'] as $mimeType) {
            $this->assertSame('text', AttachmentDescriptionService::nativeKind($mimeType));
            $this->assertNotNull(
                FileTextExtractor::extensionForMimeType($mimeType),
                $mimeType . ' is inlined, so read_file must not refuse it',
            );
        }
    }

    public function testAnUnparseableDocumentYieldsNoBlockRatherThanAnEmptyOne(): void
    {
        $notReallyADocx = "PK\x03\x04" . str_repeat("\x00", 64);

        $this->assertNull(AttachmentDescriptionService::contentBlockFor(
            $notReallyADocx,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ));
    }

    public function testJsonBytesBecomeAnInlineTextBlock(): void
    {
        $json = (string) json_encode([
            'name' => 'pim_demo_products',
            'columns' => [['name' => 'sku', 'type' => 'varchar']],
        ]);

        $this->assertSame('application/json', FilesystemServices::detectMimeTypeFromBytes($json));

        $block = AttachmentDescriptionService::contentBlockFor($json);

        $this->assertInstanceOf(TextContent::class, $block);
        $this->assertStringContainsString('pim_demo_products', (string) $block->toArray()['content']);
    }

    public function testImageBytesStillBecomeAnImageBlock(): void
    {
        $png = (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );

        $this->assertInstanceOf(ImageContent::class, AttachmentDescriptionService::contentBlockFor($png));
    }

    public function testUnsupportedAndEmptyBytesYieldNoBlock(): void
    {
        $this->assertNull(AttachmentDescriptionService::contentBlockFor(''));
        $this->assertNull(AttachmentDescriptionService::contentBlockFor("PK\x03\x04" . str_repeat("\x00", 64)));
    }

    /**
     * A prospect picks these bytes, so the set reachable from a customer surface stays exactly what
     * it was before structured text was added: text/* plus CSV, nothing else. Their JSON keeps
     * riding as a URL, which is already how docx/xlsx behave there.
     */
    public function testCustomerFacingSurfaceKeepsOnlyThePreExistingTextSet(): void
    {
        foreach (['text/plain', 'text/csv', 'application/csv', 'application/x-csv', 'text/markdown'] as $legacy) {
            $this->assertSame(
                'text',
                AttachmentDescriptionService::nativeKind($legacy, allowStructuredText: false),
                $legacy . ' was inlined before and must stay inlined',
            );
        }

        foreach ([
            'application/json',
            'application/xml',
            'application/yaml',
            'application/x-sh',
            'application/vnd.api+json',
        ] as $structured) {
            $this->assertNull(
                AttachmentDescriptionService::nativeKind($structured, allowStructuredText: false),
                $structured . ' must not be inlined on a customer surface',
            );
        }
    }

    /** Images, audio and PDF are the point of the customer lane — a licence photo, an insurance PDF. */
    public function testCustomerFacingSurfaceStillTakesBinaryMedia(): void
    {
        foreach (['image/png', 'audio/mpeg', 'application/pdf'] as $mimeType) {
            $this->assertNotNull(
                AttachmentDescriptionService::nativeKind($mimeType, allowStructuredText: false),
                $mimeType . ' must still ride natively on a customer surface',
            );
        }
    }

    public function testCustomerFacingContentBlockDropsJsonButKeepsPlainText(): void
    {
        $json = (string) json_encode(['schema' => 'pim_demo_products']);

        $this->assertNull(
            AttachmentDescriptionService::contentBlockFor($json, allowStructuredText: false),
        );

        $this->assertInstanceOf(
            TextContent::class,
            AttachmentDescriptionService::contentBlockFor("plain notes\nsecond line", allowStructuredText: false),
        );
    }

    public function testOversizedTextIsTruncatedNotDropped(): void
    {
        $huge = str_repeat('a', AttachmentDescriptionService::MAX_TEXT_ATTACHMENT_BYTES + 5_000);
        $wrapped = AttachmentDescriptionService::wrapTextForBlock($huge, 'text/plain');

        $this->assertStringContainsString('[truncated]', $wrapped);
        $this->assertLessThan(strlen($huge), strlen($wrapped));
    }
}
