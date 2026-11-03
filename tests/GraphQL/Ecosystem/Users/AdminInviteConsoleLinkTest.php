<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Users;

use Illuminate\Support\Facades\Notification;
use Kanvas\Notifications\Templates\Invite as InviteTemplate;
use Tests\TestCase;
use Tests\Traits\ActsAsAppOwner;

final class AdminInviteConsoleLinkTest extends TestCase
{
    use ActsAsAppOwner;

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
            fn (InviteTemplate $invite): bool => $invite->getData()['app']['frontend_url'] === self::CONSOLE
        );
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
