<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Twilio\Queries;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Connectors\Twilio\Actions\DescribeCompanyTwilioAction;

class CompanyTwilioQuery
{
    use ResolvesActingContext;

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public function __invoke(mixed $root, array $args): array
    {
        return new DescribeCompanyTwilioAction($this->actingContext()->company)->execute();
    }
}
