<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Actions\ProvisionAgentUserAction;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class ProvisionAgentUserActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem'];

    public function testTheAgentUserIsNamedAfterTheAgent(): void
    {
        $name = 'Sally ' . Str::lower(Str::random(8));

        $user = new ProvisionAgentUserAction(app(Apps::class), $this->company(), $name)->execute();

        $this->assertSame(Str::slug($name), $user->displayname);
    }

    public function testADisplayNameTakenAnywhereOnThePlatformIsNotReused(): void
    {
        $name = 'Sally ' . Str::lower(Str::random(8));
        $slug = Str::slug($name);
        Users::factory()->create(['displayname' => $slug]);

        $user = new ProvisionAgentUserAction(app(Apps::class), $this->company(), $name)->execute();

        $this->assertSame($slug . '-2', $user->displayname);
        $this->assertSame(1, Users::query()->where('displayname', $slug)->count());
    }

    private function company(): Companies
    {
        return auth()->user()->getCurrentCompany();
    }
}
