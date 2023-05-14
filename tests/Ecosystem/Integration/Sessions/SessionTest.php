<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Filesystem;

use Baka\Support\Str;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Filesystem\Services\FilesystemServices;
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
