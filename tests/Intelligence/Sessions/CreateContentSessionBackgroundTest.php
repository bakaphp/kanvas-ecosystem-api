<?php

declare(strict_types=1);

namespace Tests\Intelligence\Sessions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Support\Setup;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Actions\CreateContentSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class CreateContentSessionBackgroundTest extends TestCase
{
    public function testGeneratesBackgroundFromArrayShapedRole(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        new Setup($app, $user, $company)->run();

        $agent = Agent::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'role' => [
                'background' => ['You represent {{$company_name}}.', null, 'Be helpful.'],
                'steps' => ['Step one.', 'Step two.'],
                'output' => null,
            ],
        ]);

        $content = new CreateContentSessionAction(
            $this->userSession(
                $app,
                $company,
                $agent,
                $user
            )
        )->execute();

        $this->assertArrayHasKey('background', $content);
        $background = $content['background'];
        $this->assertIsArray($background);

        // each section flattens into a single newline-joined string; nulls become blank lines
        $this->assertIsString($background['background']);
        $this->assertSame("You represent {$company->name}.\n\nBe helpful.", $background['background']);
        $this->assertSame("Step one.\nStep two.", $background['steps']);

        // a null section stays null
        $this->assertNull($background['output']);
    }

    public function testGeneratesBackgroundFromStringShapedRole(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        new Setup($app, $user, $company)->run();

        $agent = Agent::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'role' => [
                'background' => 'You represent {{$company_name}}.',
                'steps' => 'Just do it.',
            ],
        ]);

        $content = new CreateContentSessionAction(
            $this->userSession(
                $app,
                $company,
                $agent,
                $user
            )
        )->execute();
        $background = $content['background'];

        // string-shape sections render in place and stay strings (no comma splitting)
        $this->assertSame("You represent {$company->name}.", $background['background']);
        $this->assertSame('Just do it.', $background['steps']);
    }

    public function testRenderingKeepsLinesThatReferenceUnknownVariables(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        new Setup($app, $user, $company)->run();

        $agent = Agent::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'role' => [
                'background' => ['Hello {{$company_name}}.', '{!! json_encode($note) !!}'],
            ],
        ]);

        $content = new CreateContentSessionAction(
            $this->userSession(
                $app,
                $company,
                $agent,
                $user
            )
        )->execute();
        $background = $content['background'];

        // the renderable line still resolves and the whole section survives
        $this->assertIsString($background['background']);
        $this->assertStringContainsString("Hello {$company->name}.", $background['background']);
    }

    private function userSession(
        Apps $app,
        Companies $company,
        Agent $agent,
        Users $user
    ): Session {
        return new Session(
            app: $app,
            company: $company,
            agent: $agent,
            entity_namespace: Users::class,
            entity_id: (string) $user->getId(),
            user: ['id' => $user->getId(), 'name' => $user->displayname],
            userModel: $user,
        );
    }
}
