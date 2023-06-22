<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Sessions;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Sessions\Models\Sessions;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class SessionTest extends TestCase
{
    public function testLogout()
    {
        $user = Users::where('id', '>', 0)->first();

        $session = new Sessions();
        $this->assertIsBool($session->end($user, app(Apps::class), Str::uuid()->toString()));
    }

    public function testLogoutFromAllDevices()
    {
        $user = Users::where('id', '>', 0)->first();

        $session = new Sessions();
        $this->assertIsBool($session->end($user, app(Apps::class)));
    }
}
