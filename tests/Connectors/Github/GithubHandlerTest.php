<?php

declare(strict_types=1);

namespace Tests\Connectors\Github;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Github\Enums\ConfigurationEnum;
use Kanvas\Connectors\Github\Handlers\GithubHandler;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Regions\Models\Regions;
use Tests\TestCase;

final class GithubHandlerTest extends TestCase
{
    private function handler(array $data): GithubHandler
    {
        $user = auth()->user();

        return new GithubHandler(
            app(Apps::class),
            $user->getCurrentCompany(),
            Regions::query()->firstOrFail(),
            $data
        );
    }

    public function testStoresTheTokenOnceItProvesItCanReadTheRepository(): void
    {
        Http::fake(['api.github.com/*' => Http::response([['tag_name' => 'v1.0.0']])]);

        $this->assertTrue($this->handler([
            'github_token' => 'ghp_valid',
            'repository' => 'bakaphp/kanvas-ecosystem-api',
        ])->setup());

        $this->assertSame('ghp_valid', app(Apps::class)->get(ConfigurationEnum::TOKEN->value));
    }

    /**
     * The address bar is what people have open when they reach this form, so the URL has to work —
     * pasting it used to build /repos/https://github.com/owner/repo/releases and 404.
     */
    public function testAcceptsTheRepositoryUrlPeoplePasteFromTheBrowser(): void
    {
        Http::fake(['api.github.com/*' => Http::response([['tag_name' => 'v1.0.0']])]);

        $this->assertTrue($this->handler([
            'github_token' => 'ghp_valid',
            'repository' => 'https://github.com/bakaphp/kanvas-ecosystem-api',
        ])->setup());

        Http::assertSent(fn (Request $request): bool => str_starts_with(
            $request->url(),
            'https://api.github.com/repos/bakaphp/kanvas-ecosystem-api/releases'
        ));
    }

    public function testRejectsAnUnusableRepositoryWithoutSpendingARequest(): void
    {
        Http::fake();

        try {
            $this->handler([
                'github_token' => 'ghp_valid',
                'repository' => 'the kanvas repo',
            ])->setup();
            $this->fail('an unusable repository name must not reach GitHub');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('owner/repo', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    /**
     * A token that authenticates but cannot see the repository is the failure worth catching at setup:
     * on a private repo GitHub answers 404, so it is indistinguishable from a typo later on.
     */
    public function testRejectsATokenThatCannotReadTheRepository(): void
    {
        Http::fake(['api.github.com/*' => Http::response('Not Found', 404)]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/Could not read releases/');

        $this->handler([
            'github_token' => 'ghp_wrong_scope',
            'repository' => 'bakaphp/kanvas-ecosystem-api',
        ])->setup();
    }

    public function testTheTokenIsNotStoredWhenValidationFails(): void
    {
        Http::fake(['api.github.com/*' => Http::response('Unauthorized', 401)]);
        app(Apps::class)->set(ConfigurationEnum::TOKEN->value, 'ghp_previous');

        try {
            $this->handler([
                'github_token' => 'ghp_bad',
                'repository' => 'bakaphp/kanvas-ecosystem-api',
            ])->setup();
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(
            'ghp_previous',
            app(Apps::class)->get(ConfigurationEnum::TOKEN->value),
            'a failed setup must not overwrite a working token'
        );
    }

    public function testRequiresBothATokenAndARepository(): void
    {
        $this->expectException(ValidationException::class);

        $this->handler(['github_token' => 'ghp_valid'])->setup();
    }
}
