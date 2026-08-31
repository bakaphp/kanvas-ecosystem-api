<?php

declare(strict_types=1);

namespace Tests\ActionEngine\Unit;

use Kanvas\ActionEngine\Actions\Enums\ActionEnum;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCaseUnit;

final class CreateEngagementActionResolutionTest extends TestCaseUnit
{
    private CreateEngagementAction $action;
    private ReflectionMethod $getBaseAction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = (new ReflectionClass(CreateEngagementAction::class))->newInstanceWithoutConstructor();
        $this->getBaseAction = new ReflectionMethod(CreateEngagementAction::class, 'getBaseAction');
    }

    public function testBlueLinkShareCodeResolvesToItsOwnAction(): void
    {
        $this->assertSame(
            ActionEnum::SHARE_BLUELINK->value,
            $this->getBaseAction->invoke($this->action, 'codeShare', ActionEnum::SHARE_BLUELINK->value)
        );
    }

    public function testElectrifyAmericaShareCodeResolvesToItsOwnAction(): void
    {
        $this->assertSame(
            ActionEnum::SHARE_ELECTRIFY_AMERICA->value,
            $this->getBaseAction->invoke($this->action, 'codeShare', ActionEnum::SHARE_ELECTRIFY_AMERICA->value)
        );
    }

    public function testCreditAppAndCosignerMappingsStillResolveToTheirBaseActions(): void
    {
        $this->assertSame(
            ActionEnum::CREDIT_APP->value,
            $this->getBaseAction->invoke($this->action, 'creditApp', ActionEnum::CREDIT_APP_2->value)
        );

        $this->assertSame(
            ActionEnum::CO_SIGNER->value,
            $this->getBaseAction->invoke($this->action, 'cosigner', ActionEnum::CO_SIGNER_2->value)
        );
    }
}
