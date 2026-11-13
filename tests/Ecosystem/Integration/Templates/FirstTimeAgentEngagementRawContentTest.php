<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Templates;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Support\MarkdownEmailRenderer;
use Kanvas\Templates\Actions\CreateTemplateAction;
use Kanvas\Templates\Actions\RenderTemplateAction;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use Tests\TestCase;

final class FirstTimeAgentEngagementRawContentTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem'];

    public function testEngagementTemplateRendersMarkdownHtmlWithoutEscapingIt(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new CreateTemplateAction(
            TemplateInput::from([
                'app' => $app,
                'company' => $company,
                'user' => $user,
                'name' => 'first-time-agent-engagement',
                'template' => '<div>{{ content }}</div>',
            ])
        )->execute();

        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_11_13_160000_raw_content_in_first_time_agent_engagement_template.php');
        $migration->up();

        $rendered = new RenderTemplateAction($app, $company)->execute(
            'first-time-agent-engagement',
            ['content' => MarkdownEmailRenderer::toEmailHtml('Hola')],
        );

        $this->assertStringContainsString('<p>Hola</p>', $rendered);
        $this->assertStringNotContainsString('&lt;p&gt;Hola&lt;/p&gt;', $rendered);
    }
}
