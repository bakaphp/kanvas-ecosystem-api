<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Users;

use Baka\Support\Str;
use Kanvas\Users\Models\Sources;
use Tests\TestCase;

class UserDeviceTest extends TestCase
{
    public function generateDevice(): array
    {
        $source = Sources::firstOrFail();

        return [
            'source_site' => $source->title,
            'device_id' => Str::uuid(),
            'source_username' => 'kanvasniche',
        ];
    }

    public function testLinkUserDevice()
    {
        $source = Sources::firstOrFail();

        $this->graphQL(/** @lang GraphQL */ '
            mutation linkDevice($data: DeviceInput!) {
                linkDevice(data: $data) 
            }
        ', [
            'data' => $this->generateDevice(),
        ])->assertJson([
            'data' => [
                'linkDevice' => true,
            ],
        ]);
    }

    public function testUnLinkUserDevice()
    {
        $source = Sources::firstOrFail();
        $deviceData = $this->generateDevice();

        $this->graphQL(/** @lang GraphQL */ '
            mutation linkDevice($data: DeviceInput!) {
                linkDevice(data: $data) 
            }
        ', [
            'data' => $deviceData,
        ])->assertJson([
            'data' => [
                'linkDevice' => true,
            ],
        ]);

        $this->graphQL(/** @lang GraphQL */ '
            mutation unLinkDevice($data: DeviceInput!) {
                unLinkDevice(data: $data) 
            }
        ', [
            'data' => $deviceData,

        ])->assertJson([
            'data' => [
                'unLinkDevice' => true,
            ],
        ]);
    }
}
