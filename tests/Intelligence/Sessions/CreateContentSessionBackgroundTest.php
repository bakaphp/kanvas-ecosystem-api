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

        // each section is returned as an array of rendered lines; nulls are preserved
        $this->assertSame(['You represent ' . $company->name . '.', null, 'Be helpful.'], $background['background']);
        $this->assertSame(['Step one.', 'Step two.'], $background['steps']);

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
                'background' => "You represent {{\$company_name}}.\nBe helpful.",
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

        // string-shape sections split on newlines into an array of rendered lines
        $this->assertSame(['You represent ' . $company->name . '.', 'Be helpful.'], $background['background']);
        $this->assertSame(['Just do it.'], $background['steps']);
    }

    public function testUnknownVariablesRenderEmptyAndNeverLeaveRawBladeTags(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        new Setup($app, $user, $company)->run();

        $agent = Agent::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'role' => [
                'background' => ['Hello {{$company_name}}.', 'Vehicle: {{$vehicle_interest}}.'],
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
        $lines = $content['background']['background'];

        $this->assertSame('Hello ' . $company->name . '.', $lines[0]);
        // unknown variable resolves to empty, not raw {{ }}
        $this->assertSame('Vehicle: .', $lines[1]);
    }

    public function testRendersRealAgentRolePromptText(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        new Setup($app, $user, $company)->run();

        $agent = Agent::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'role' => [
                'background' => [
                    'You are **Sally**, a 24/7 Sales Agent designed to manage every stage of lead engagement—from initial contact to appointment scheduling and follow-ups—to streamline the sales process and maximize lead conversion.',
                    'You represent {{$company_name}}, a dealership in {{$branch_city}}, {{$branch_state}}.',
                    'Full address: {{$branch_address}}.',
                    'Current work_hours/after_hours status: {{$work_hours_status}}.',
                    null,
                    'If vehicle of interest → {{$vehicle_interest}} is already known or the customer explicitly mentions/chooses a vehicle → use that as the reference vehicle.',
                    '- Memorial Day — Last Monday of May',
                    '“It looks like I can’t confirm that specific detail from here.”',
                ],
                'steps' => [
                    '### **Step 2 — Next Step ({{$kanvas_flow_state}} = "NEXT_STEP")**',
                    '* If yes: send Trade-In link: {{ $tradeIn }}.',
                ],
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

        $background = $content['background']['background'];
        $this->assertIsArray($background);

        // null blank-line marker is preserved as an array entry
        $this->assertContains(null, $background);

        $joined = implode("\n", array_map(static fn (?string $line): string => $line ?? '', $background));

        // long text, em-dashes and smart quotes survive intact
        $this->assertStringContainsString('You are **Sally**, a 24/7 Sales Agent designed to manage every stage of lead engagement—from initial contact', $joined);
        $this->assertStringContainsString('“It looks like I can’t confirm that specific detail from here.”', $joined);

        // known variables render, unknown ones resolve to empty — no raw Blade tags anywhere
        $this->assertStringContainsString('You represent ' . $company->name . ',', $joined);
        $this->assertStringNotContainsString('{{', $joined);
        $this->assertStringNotContainsString('{{', implode("\n", $content['background']['steps']));
    }

    public function testRendersRealAgentRolePromptTextWhenBackgroundIsString(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        new Setup($app, $user, $company)->run();

        // legacy shape: each section is a single multi-line string, not an array of lines
        $agent = Agent::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'role' => [
                'background' => implode("\n", [
                    'You are **Sally**, a 24/7 Sales Agent designed to manage every stage of lead engagement—from initial contact to appointment scheduling and follow-ups—to streamline the sales process and maximize lead conversion.',
                    'You represent {{$company_name}}, a dealership in {{$branch_city}}, {{$branch_state}}.',
                    'Current work_hours/after_hours status: {{$work_hours_status}}.',
                    '',
                    'If vehicle of interest → {{$vehicle_interest}} is already known → use that as the reference vehicle.',
                    '“It looks like I can’t confirm that specific detail from here.”',
                ]),
                'steps' => implode("\n", [
                    '### **Step 2 — Next Step ({{$kanvas_flow_state}} = "NEXT_STEP")**',
                    '* If yes: send Trade-In link: {{ $tradeIn }}.',
                ]),
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

        $background = $content['background']['background'];

        // string section is split into an array of rendered lines, blank line kept as ''
        $this->assertIsArray($background);
        $this->assertContains('', $background);

        $joined = implode("\n", $background);
        $this->assertStringContainsString('You are **Sally**, a 24/7 Sales Agent designed to manage every stage of lead engagement—from initial contact', $joined);
        $this->assertStringContainsString('“It looks like I can’t confirm that specific detail from here.”', $joined);
        $this->assertStringContainsString('You represent ' . $company->name . ',', $joined);
        $this->assertStringNotContainsString('{{', $joined);
        $this->assertStringNotContainsString('{{', implode("\n", $content['background']['steps']));

        // a null section stays null even in the string shape
        $this->assertNull($content['background']['output']);
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
