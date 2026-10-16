<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\Actions\ConfirmQuoteWithInsurerAction;
use Kanvas\Insurance\DataTransferObject\InsuranceQuoteRequest;
use Tests\Insurance\Fakes\FakeCustomerProvider;
use Tests\Insurance\Fakes\FakeFreshQuoteProvider;
use Tests\TestCase;

final class ConfirmQuoteWithInsurerTest extends TestCase
{
    private function request(): InsuranceQuoteRequest
    {
        return new InsuranceQuoteRequest(product: 'A-PA', payload: ['cliente' => ['nombre' => 'Ana']]);
    }

    private function freshProvider(): FakeFreshQuoteProvider
    {
        return new FakeFreshQuoteProvider('fake', fn (): never => throw new \LogicException('not used'), productCode: 'A-PA');
    }

    public function testAFreshQuoteIsCreatedAndUsedInsteadOfTheSearchedOne(): void
    {
        $provider = $this->freshProvider();

        $quote = new ConfirmQuoteWithInsurerAction(
            $provider,
            'SEARCHED',
            'A-PA',
            $this->request(),
            'insurance-order-9-abc12345'
        )->execute();

        $this->assertSame([['SEARCHED', 'A-PA', 'insurance-order-9-abc12345']], $provider->fresh);
        $this->assertSame('FRESH-SEARCHED', $quote->quoteNumber);
        $this->assertSame('FRESH-SEARCHED', $provider->updates[0][0]);
    }

    public function testWithoutARequestIdTheSearchedQuoteIsUsed(): void
    {
        $provider = $this->freshProvider();

        $quote = new ConfirmQuoteWithInsurerAction($provider, 'SEARCHED', 'A-PA', $this->request())->execute();

        $this->assertSame([], $provider->fresh);
        $this->assertSame('SEARCHED', $quote->quoteNumber);
    }

    public function testAProviderWithoutFreshQuotesKeepsTheSearchedQuote(): void
    {
        $provider = new FakeCustomerProvider('fake', fn (): never => throw new \LogicException('not used'), productCode: 'A-PA');

        $quote = new ConfirmQuoteWithInsurerAction(
            $provider,
            'SEARCHED',
            'A-PA',
            $this->request(),
            'insurance-order-9-abc12345'
        )->execute();

        $this->assertSame('SEARCHED', $quote->quoteNumber);
    }

    public function testAFreshQuoteTheInsurerRefusesStopsTheContract(): void
    {
        $provider = $this->freshProvider();
        $provider->freshSucceeds = false;

        $this->expectException(ValidationException::class);

        new ConfirmQuoteWithInsurerAction(
            $provider,
            'SEARCHED',
            'A-PA',
            $this->request(),
            'insurance-order-9-abc12345'
        )->execute();
    }
}
