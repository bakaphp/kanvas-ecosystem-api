<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Tests\TestCase;

final class AppOrCurrentTest extends TestCase
{
    public function testARowWithItsOwnAppAnswersWithIt(): void
    {
        $own = new Apps();
        $own->id = app(Apps::class)->getId() + 1;

        $row = new IntegrationsCompany(['apps_id' => $own->id]);
        $row->setRelation('app', $own);

        $this->assertSame($own, $row->appOrCurrent());
    }

    public function testARowWithNoAppAnswersWithTheBoundOne(): void
    {
        $row = new IntegrationsCompany(['apps_id' => null]);

        $this->assertSame(app(Apps::class)->getId(), $row->appOrCurrent()->getId());
    }
}
