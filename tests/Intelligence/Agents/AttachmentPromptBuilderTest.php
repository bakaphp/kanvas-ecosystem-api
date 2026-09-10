<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Agents\Helpers\AttachmentPromptBuilder;
use Tests\TestCase;

class AttachmentPromptBuilderTest extends TestCase
{
    public function testReturnsTheMessageUnchangedWhenThereAreNoAttachments(): void
    {
        $this->assertSame(
            'just a question',
            AttachmentPromptBuilder::withAttachments('just a question', []),
        );
    }

    public function testAppendsAnAttachedFilesListBelowTheMessage(): void
    {
        $result = AttachmentPromptBuilder::withAttachments('check these', [
            'https://cdn.example.com/a.pdf',
            'https://cdn.example.com/b.csv',
        ]);

        $this->assertSame(
            "check these\n\nAttached files:\n- https://cdn.example.com/a.pdf\n- https://cdn.example.com/b.csv",
            $result,
        );
    }

    public function testStandsAloneWhenTheMessageIsEmpty(): void
    {
        $result = AttachmentPromptBuilder::withAttachments('', [
            'https://cdn.example.com/only.pdf',
        ]);

        $this->assertSame(
            "Attached files:\n- https://cdn.example.com/only.pdf",
            $result,
        );
    }

    public function testReturnsTheMessageUnchangedWhenThereAreNoFilesystemMarkers(): void
    {
        $this->assertSame(
            'just a question',
            AttachmentPromptBuilder::withFilesystemMarkers('just a question', []),
        );
    }

    public function testAppendsOneFilesystemMarkerPerAttachedFile(): void
    {
        $result = AttachmentPromptBuilder::withFilesystemMarkers('here is the bill', [
            $this->file(41, 'invoice.pdf'),
            $this->file(42, 'terms.pdf'),
        ]);

        $this->assertSame(
            "here is the bill\n\n"
                . '[Attached file on this message — filesystem_id: 41, filename: "invoice.pdf"]' . "\n"
                . '[Attached file on this message — filesystem_id: 42, filename: "terms.pdf"]',
            $result,
        );
    }

    /**
     * The AP/AR agents key every file tool (extract_invoice_data, attach_bill_file, get_file_link) on a
     * filesystem_id, so an in-app or Slack upload has to carry the id, not just the URL the container
     * runtimes fetch. Both blocks land, in that order.
     */
    public function testUrlListAndFilesystemMarkersCompose(): void
    {
        $file = $this->file(7, 'invoice.pdf');

        $result = AttachmentPromptBuilder::withFilesystemMarkers(
            AttachmentPromptBuilder::withAttachments('please book this', [$file->url]),
            [$file],
        );

        $this->assertSame(
            "please book this\n\nAttached files:\n- https://cdn.example.com/invoice.pdf\n\n"
                . '[Attached file on this message — filesystem_id: 7, filename: "invoice.pdf"]',
            $result,
        );
    }

    private function file(int $id, string $name): Filesystem
    {
        $file = new Filesystem();
        $file->id = $id;
        $file->name = $name;
        $file->url = 'https://cdn.example.com/' . $name;

        return $file;
    }
}
