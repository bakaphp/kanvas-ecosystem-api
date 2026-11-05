<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Users;

use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Templates\Invite as InviteTemplate;
use Kanvas\Templates\Actions\RenderTemplateAction;
use Tests\TestCase;
use Tests\Traits\ActsAsAppOwner;
use Tests\Traits\MakesTemplates;

final class AdminInviteConsoleLinkTest extends TestCase
{
    use ActsAsAppOwner;
    use MakesTemplates;

    private const FRONTEND = 'https://app.kanvas.dev';
    private const CONSOLE = 'https://console.kanvas.dev';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAppOwner();
        Notification::fake();

        config()->set('kanvas.app.frontend_url', self::FRONTEND);
        config()->set('kanvas.app.console_url', self::CONSOLE);
    }

    public function testAConsoleInviteLinksToTheConsole(): void
    {
        $this->inviteAdmin([['name' => 'console', 'data' => true]]);

        Notification::assertSentOnDemand(
            InviteTemplate::class,
            fn (InviteTemplate $invite): bool => $invite->getData()['app']->toTemplateData()['frontend_url'] === self::CONSOLE
        );
    }

    public function testAConsoleInviteRendersThroughBladeAndTwigRows(): void
    {
        $this->inviteAdmin([['name' => 'console', 'data' => true]]);
        $invite = $this->sentInvite();
        $app = app(Apps::class);
        $renderer = new RenderTemplateAction($app);

        $blade = $renderer->renderBody(
            $this->makeHandMadeBladeTemplate('{{ $app->name }} {{ $app->frontend_url }} {{ $app->getId() }}'),
            $invite->getData()
        );
        $twig = $renderer->renderBody(
            $this->makeTemplate('{{ app.name }} {{ app.frontend_url }} {{ app.id }}'),
            $invite->getData()
        );

        $expected = $app->name . ' ' . self::CONSOLE . ' ' . $app->getId();
        $this->assertSame($expected, $blade);
        $this->assertSame($expected, $twig);
    }

    public function testAnAppInviteKeepsTheAppFrontend(): void
    {
        $this->inviteAdmin([['name' => 'console', 'data' => false]]);

        Notification::assertSentOnDemand(
            InviteTemplate::class,
            fn (InviteTemplate $invite): bool => $invite->getData()['app']->toTemplateData()['frontend_url'] === self::FRONTEND
        );
    }

    public function testAnInviteWithoutTheFlagKeepsTheAppFrontend(): void
    {
        $this->inviteAdmin([]);

        Notification::assertSentOnDemand(
            InviteTemplate::class,
            fn (InviteTemplate $invite): bool => $invite->getData()['app']->toTemplateData()['frontend_url'] === self::FRONTEND
        );
    }

    private function sentInvite(): InviteTemplate
    {
        $sent = null;

        Notification::assertSentOnDemand(
            InviteTemplate::class,
            function (InviteTemplate $invite) use (&$sent): bool {
                $sent = $invite;

                return true;
            }
        );

        return $sent;
    }

    private function inviteAdmin(array $customFields): void
    {
        $this->graphQL(
            '
            mutation inviteAdmin($data: AdminInviteInput!) {
                inviteAdmin(input: $data) {
                    id
                    invite_hash
                }
            }',
            [
                'data' => [
                    'email' => fake()->unique()->safeEmail(),
                    'firstname' => fake()->firstName(),
                    'lastname' => fake()->lastName(),
                    'custom_fields' => $customFields,
                ],
            ]
        )->assertSuccessful()->assertJsonPath('errors', null);
    }
}
