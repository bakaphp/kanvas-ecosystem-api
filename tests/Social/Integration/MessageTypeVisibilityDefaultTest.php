<?php

declare(strict_types=1);

namespace Tests\Social\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Tests\TestCase;

class MessageTypeVisibilityDefaultTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['social'];

    public function testSystemVerbIsPrivateByDefault(): void
    {
        $type = MessageTypeService::getOrCreate(app(Apps::class), 'system');

        $this->assertFalse($type->is_public);
        $this->assertFalse(MessageType::isPublicByDefaultForVerb('system'));
    }

    public function testAnyOtherVerbIsPublicByDefault(): void
    {
        $type = MessageTypeService::getOrCreate(app(Apps::class), 'visibility-' . uniqid());

        $this->assertTrue($type->is_public);
    }

    public function testAnExplicitFlagWinsOverTheVerbDefault(): void
    {
        $type = MessageTypeService::getOrCreate(app(Apps::class), 'visibility-' . uniqid(), isPublic: false);

        $this->assertFalse($type->is_public);
    }
}
