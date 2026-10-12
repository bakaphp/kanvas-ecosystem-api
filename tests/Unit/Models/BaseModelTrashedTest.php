<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Regions\Models\Regions;
use Tests\TestCase;

/**
 * trashed() used to call getDeletedAtColumn(), which only Laravel's SoftDeletes trait defines, so
 * on an ecosystem model without that trait it fataled on every call.
 */
class BaseModelTrashedTest extends TestCase
{
    public function testTrashedReadsTheIsDeletedFlag(): void
    {
        $this->assertFalse(new FilesystemEntities(['is_deleted' => 0])->trashed());
        $this->assertTrue(new FilesystemEntities(['is_deleted' => 1])->trashed());
    }

    /**
     * Regions mixes in Laravel's untyped SoftDeletes::trashed(); a typed override on the base
     * class is a fatal at class load, which no other test reaches.
     */
    public function testModelsMixingInLaravelSoftDeletesStillLoad(): void
    {
        $this->assertIsBool(new Regions()->trashed());
    }
}
