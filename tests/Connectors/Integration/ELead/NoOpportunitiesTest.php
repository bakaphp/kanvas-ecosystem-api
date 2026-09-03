<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\ELead;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kanvas\Connectors\Elead\Entities\Lead;
use Tests\TestCase;

final class NoOpportunitiesTest extends TestCase
{
    public function testACustomerWithoutOpportunitiesIsAnEmptyResultNotAFault(): void
    {
        $this->assertTrue(Lead::isNoOpportunitiesResponse($this->eleadNotFound()));
    }

    public function testOtherNotFoundErrorsStillSurface(): void
    {
        $this->assertFalse(
            Lead::isNoOpportunitiesResponse($this->eleadNotFound(code: 'CustomerNotFoundError')),
            'only the no-opportunities answer is an empty result; other 404s are real'
        );
        $this->assertFalse(
            Lead::isNoOpportunitiesResponse($this->eleadNotFound(body: 'Not Found')),
            'a non-JSON 404 body is not something we can classify away'
        );
        $this->assertFalse(
            Lead::isNoOpportunitiesResponse($this->eleadNotFound(status: 403)),
            'authorization failures must keep reporting'
        );
    }

    public function testMessageKeepsTheSubstringPullLeadActionMatchesOn(): void
    {
        // PullLeadAction falls back to local people-matching by looking for this exact substring;
        // changing the wording silently disables that path.
        $this->assertStringContainsString(
            'No Opportunities found',
            Lead::noOpportunitiesFound('8be2e396-3637-f111-a08a-e0069a76427b')->getMessage()
        );
    }

    private function eleadNotFound(int $status = 404, ?string $code = null, ?string $body = null): ClientException
    {
        $body ??= json_encode([
            'code' => $code ?? 'OpportunitiesNotFoundError',
            'message' => 'No Opportunities found.',
            'link' => '',
            'referenceId' => '925650b9-5344-4b2f-8681-000000000000',
        ]);

        return new ClientException(
            'Client error',
            new Request('GET', '/sales/v2/elead/opportunities/search-by-customerId/8be2e396'),
            new Response($status, [], $body)
        );
    }
}
