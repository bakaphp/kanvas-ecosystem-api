<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\CustomerSuccess;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\DataTransferObject\CustomerUpdateDraft;
use Kanvas\Intelligence\Agents\Services\CustomerSuccess\CustomerUpdateRenderer;
use Kanvas\Templates\Models\Templates;
use Tests\TestCase;

final class CustomerUpdateRendererTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem'];

    private function render(string $body): string
    {
        $draft = new CustomerUpdateDraft(
            organization: new Organization(),
            subject: 'Subject line',
            body: $body,
            coveredFrom: null,
            coveredThrough: null,
            releaseTags: [],
        );

        return new CustomerUpdateRenderer()->toEmailHtml(new CustomerUpdateRenderer()->toMarkdown($draft));
    }

    public function testTheMastheadIntroSectionsAndSignOffEachGetTheirOwnMarkup(): void
    {
        $html = $this->render(
            "August '26 Highlights\n\n"
            . "So much to cover. Let's dive in.\n\n"
            . "Announcing Company Brain\nKanvas now includes a Brain agent.\n\n"
            . "That is all for now.\nSee you next time."
        );

        $this->assertStringContainsString('<h1', $html);
        $this->assertStringContainsString("August '26 Highlights", $html);
        $this->assertStringContainsString('<h2', $html);
        $this->assertStringContainsString('Announcing Company Brain', $html);
        $this->assertStringContainsString('Kanvas now includes a Brain agent.', $html);
        $this->assertStringContainsString('See you next time.', $html);
    }

    /**
     * A headline with nothing under it would render as a heading for content that is not there.
     */
    public function testASingleLineBlockBecomesAParagraphNotAHeading(): void
    {
        $html = $this->render("Masthead\n\nIntro line.\n\nA lone line.\n\nSign off.");

        $this->assertStringNotContainsString('<h2', $html);
        $this->assertStringContainsString('A lone line.', $html);
    }

    /**
     * The body is model output. It reaches an inbox, so it is escaped rather than trusted.
     */
    public function testModelOutputIsEscapedNotInjected(): void
    {
        $html = $this->render("Masthead\n\nIntro\n\nHead\n<script>alert(1)</script>\n\nBye.");

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * The note is read by a person scrolling the thread. Markdown keeps the structure without the
     * inline-styled tags, which in a channel bubble read as a pasted email.
     */
    public function testTheNoteVariantIsMarkdownNotHtml(): void
    {
        $draft = new CustomerUpdateDraft(
            organization: new Organization(),
            subject: 'Subject line',
            body: "August '26 Highlights\n\nIntro line.\n\nHead\nBody sentence.\n\nBye.\nSee you.",
            coveredFrom: null,
            coveredThrough: null,
            releaseTags: [],
        );

        $markdown = new CustomerUpdateRenderer()->toMarkdown($draft);

        $this->assertStringContainsString("# August '26 Highlights", $markdown);
        $this->assertStringContainsString('## Head', $markdown);
        $this->assertStringContainsString("Bye.\nSee you.", $markdown, 'the sign-off keeps its line break');
        $this->assertStringNotContainsString('<', $markdown, 'no markup of any kind reaches the thread');
    }

    public function testStylesAreInlineBecauseMailClientsDropStyleBlocks(): void
    {
        $html = $this->render("Masthead\n\nIntro\n\nHead\nBody.\n\nBye.");

        $this->assertStringNotContainsString('<style', $html);
        $this->assertStringContainsString('style="font', $html);
        $this->assertStringContainsString('max-width:600px', $html);
    }

    /**
     * The surround has to be editable without a deploy, so a DB template of this name beats the
     * built-in shell. The body is never the template's business — it arrives already converted.
     */
    public function testADatabaseTemplateOverridesTheBuiltInShell(): void
    {
        $app = app(Apps::class);

        Templates::create([
            'apps_id' => $app->getId(),
            'companies_id' => 0,
            'users_id' => auth()->user()->getId(),
            'name' => CustomerUpdateRenderer::TEMPLATE_NAME,
            'template' => '<table class="branded"><tr><td>{!! $body !!}</td></tr></table>',
        ]);

        $html = new CustomerUpdateRenderer()->toEmailHtml("# Masthead\n\nIntro.", $app);

        $this->assertStringContainsString('<table class="branded">', $html);
        $this->assertStringContainsString('Intro.', $html, 'the rendered body still lands inside it');
        $this->assertStringNotContainsString('max-width:600px', $html, 'the built-in shell is replaced, not nested');
    }

    public function testItFallsBackToTheBuiltInShellWhenNoTemplateExists(): void
    {
        $html = new CustomerUpdateRenderer()->toEmailHtml("# Masthead\n\nIntro.", app(Apps::class));

        $this->assertStringContainsString('max-width:600px', $html);
        $this->assertStringContainsString('Intro.', $html);
    }
}
