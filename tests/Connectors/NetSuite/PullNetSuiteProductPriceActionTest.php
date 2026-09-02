<?php

declare(strict_types=1);

namespace Tests\Connectors\NetSuite;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteProductPriceAction;
use Kanvas\Connectors\NetSuite\Services\NetSuiteProductService;
use Kanvas\Users\Models\Users;
use ReflectionProperty;
use Tests\TestCase;

/**
 * A barcode NetSuite doesn't carry comes back as an empty search result, and reading [0] off it crashed
 * the webhook mid-sync instead of reporting the miss (Sentry KANVAS-ECOSYSTEM-6B5).
 */
class PullNetSuiteProductPriceActionTest extends TestCase
{
    public function testABarcodeMissingFromNetSuiteIsReportedInsteadOfCrashing(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $action = new PullNetSuiteProductPriceAction($app, $company, $user);

        // Stubbed rather than mocked: the action builds its own services, and every later step of
        // execute() would reach NetSuite for real.
        new ReflectionProperty($action, 'productService')->setValue(
            $action,
            new class ($app, $company) extends NetSuiteProductService {
                public function searchProductByItemNumber(string|int $itemNumber): array
                {
                    return [];
                }
            }
        );

        $result = $action->execute('1234567811');

        $this->assertSame('Product not found in NetSuite', $result['error']);
        $this->assertSame('1234567811', $result['item']);
        $this->assertSame($company->getId(), $result['company']);
        $this->assertSame($app->getId(), $result['app']);
    }
}
