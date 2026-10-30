<?php

declare(strict_types=1);

namespace Tests\Connectors\EchoPay;

use Kanvas\Connectors\EchoPay\Exceptions\EchoPayException;
use Kanvas\Connectors\EchoPay\Services\ShopperMessageResolver;
use Tests\TestCaseUnit;

final class EchoPayExceptionTest extends TestCaseUnit
{
    public function testReasonIsReadFromTheWrappedGatewayBody(): void
    {
        $exception = new EchoPayException(
            message: 'Payment not approved',
            code: 400,
            errorBody: ['data' => ['errorInformation' => ['reason' => 'INSUFFICIENT_FUND']]]
        );

        $this->assertSame('INSUFFICIENT_FUND', $exception->getReason());
    }

    public function testReasonIsReadFromAnUnwrappedGatewayBody(): void
    {
        $errorBody = ['errorInformation' => ['reason' => 'INVALID_ACCOUNT']];
        $exception = new EchoPayException(message: 'Payment not approved', code: 400, errorBody: $errorBody);

        $this->assertSame('INVALID_ACCOUNT', $exception->getReason());
    }

    public function testReasonIsNullWithoutErrorInformation(): void
    {
        $this->assertNull(new EchoPayException('Gateway timeout', 0)->getReason());
    }

    public function testResolverTranslatesAKnownReason(): void
    {
        $this->assertSame(
            __('payment_errors.INSUFFICIENT_FUND', [], 'es'),
            ShopperMessageResolver::resolve('INSUFFICIENT_FUND', 'Decline - Insufficient funds', 'fallback')
        );
    }

    public function testResolverFallsBackToTheGatewayMessageForAnUnknownReason(): void
    {
        $this->assertSame(
            'Something new',
            ShopperMessageResolver::resolve('BRAND_NEW_REASON', 'Something new', 'fallback')
        );
    }

    public function testResolverFallsBackToTheReasonThenTheFallback(): void
    {
        $this->assertSame('BRAND_NEW_REASON', ShopperMessageResolver::resolve('BRAND_NEW_REASON', null, 'fallback'));
        $this->assertSame('fallback', ShopperMessageResolver::resolve(null, null, 'fallback'));
    }

    public function testRiskReasonsNeverRevealFraudScreeningToTheShopper(): void
    {
        foreach (['SCORE_EXCEEDS_THRESHOLD', 'DECISION_PROFILE_REJECT', 'AVS_FAILED', 'RISK_DECLINED', 'PENDING_REVIEW', 'BLACKLISTED_CUSTOMER', 'CUSTOMER_WATCHLIST_MATCH', 'ADDRESS_COUNTRY_WATCHLIST_MATCH', 'EMAIL_COUNTRY_WATCHLIST_MATCH', 'IP_COUNTRY_WATCHLIST_MATCH', 'SUSPENDED_ACCOUNT'] as $reason) {
            $message = ShopperMessageResolver::resolve($reason, 'Raw gateway text', 'fallback');

            $this->assertNotSame('Raw gateway text', $message);
            $this->assertNotSame('payment_errors.' . $reason, $message);
        }
    }

    public function testUserMessageUsesTheResolver(): void
    {
        $exception = new EchoPayException(
            message: 'Payment not approved',
            code: 400,
            errorBody: ['data' => ['errorInformation' => ['reason' => 'EXPIRED_CARD', 'message' => 'Expired card']]]
        );

        $this->assertSame(__('payment_errors.EXPIRED_CARD', [], 'es'), $exception->getUserMessage());
    }
}
