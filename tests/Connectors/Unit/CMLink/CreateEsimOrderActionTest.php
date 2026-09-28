<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\CMLink;

use Kanvas\Connectors\CMLink\Actions\CreateEsimOrderAction;
use Kanvas\Connectors\CMLink\Services\CustomerService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\Models\Order;
use Tests\TestCase;

final class CreateEsimOrderActionTest extends TestCase
{
    public function testThrowsValidationExceptionWhenCmLinkReturnsNoEsimData(): void
    {
        $action = $this->actionWithEsimInfo([
            'code' => '1007002',
            'description' => 'ICCID does not exist',
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('ICCID does not exist');

        $action->execute();
    }

    public function testThrowsValidationExceptionWhenDownloadUrlIsMissing(): void
    {
        $action = $this->actionWithEsimInfo([
            'code' => '0000000',
            'data' => ['state' => 'Released'],
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('CMLink returned no eSim info for ICCID');

        $action->execute();
    }

    private function actionWithEsimInfo(array $esimInfo): CreateEsimOrderAction
    {
        $customerService = new class ($esimInfo) extends CustomerService {
            public function __construct(private array $esimInfo)
            {
            }

            public function getEsimInfo(string $iccid): array
            {
                return $this->esimInfo;
            }
        };

        return new class ($customerService) extends CreateEsimOrderAction {
            public function __construct(CustomerService $customerService)
            {
                $this->customerService = $customerService;
                $this->order = new Order();
                $this->availableVariant = new Variants();
                $this->availableVariant->sku = '8910000000000000000';
            }

            protected function validateOrder(): void
            {
            }

            protected function processNewOrder(): void
            {
            }
        };
    }
}
