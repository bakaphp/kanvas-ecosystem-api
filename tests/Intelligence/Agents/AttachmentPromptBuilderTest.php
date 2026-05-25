<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

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
}
