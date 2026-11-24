<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\AppKey;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Tests\TestCase;

/**
 * A tenant id handed to a scope names that tenant. Falling back to the bound app or to the logged-in
 * user's company answers for whoever the process served last, and a queue worker has no user at all.
 */
final class TenantScopesHonourIdsTest extends TestCase
{
    public function testAnAppIdScopesToThatAppNotTheBoundOne(): void
    {
        $otherApp = app(Apps::class)->getId() + 100000;

        $this->assertStringContainsString('`apps_id` = ' . $otherApp, $this->agents()->fromApp($otherApp)->toRawSql());
    }

    public function testACompanyIdScopesToThatCompanyNotTheLoggedInUsers(): void
    {
        $this->assertNotSame(77, auth()->user()->getCurrentCompany()->getId());

        $this->assertStringContainsString('`companies_id` = 77', $this->agents()->fromCompany(77)->toRawSql());
    }

    public function testACompanyIdIsNotWidenedByAnAppKey(): void
    {
        app()->instance(AppKey::class, new AppKey());

        try {
            $this->assertStringContainsString('`companies_id` = 77', $this->agents()->fromCompany(77)->toRawSql());
        } finally {
            app()->forgetInstance(AppKey::class);
        }
    }

    public function testANumericStringIdIsAnIdToo(): void
    {
        $otherApp = (string) (app(Apps::class)->getId() + 100000);
        app()->instance(AppKey::class, new AppKey());

        try {
            $sql = $this->agents()->fromApp($otherApp)->fromCompany('77')->toRawSql();
        } finally {
            app()->forgetInstance(AppKey::class);
        }

        $this->assertStringContainsString('`apps_id` = ' . $otherApp, $sql);
        $this->assertStringContainsString('`companies_id` = 77', $sql);
    }

    public function testWithoutAnIdTheBoundAppStillScopes(): void
    {
        $this->assertStringContainsString(
            '`apps_id` = ' . app(Apps::class)->getId(),
            $this->agents()->fromApp()->toRawSql()
        );
    }

    private function agents(): Builder
    {
        return Agent::query()->withoutGlobalScopes();
    }
}
