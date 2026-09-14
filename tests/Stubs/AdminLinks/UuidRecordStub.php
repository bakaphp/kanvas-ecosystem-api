<?php

declare(strict_types=1);

namespace Tests\Stubs\AdminLinks;

use Baka\Traits\KanvasModelTrait;
use Illuminate\Database\Eloquent\Model;
use Kanvas\AdminLinks\Enums\AdminLinkSectionEnum;
use Kanvas\AdminLinks\Traits\HasAdminLink;
use Override;

class UuidRecordStub extends Model
{
    use HasAdminLink;
    use KanvasModelTrait;

    protected $table = 'leads';
    protected $guarded = [];

    #[Override]
    public function adminLinkSection(): AdminLinkSectionEnum
    {
        return AdminLinkSectionEnum::LEAD;
    }
}
