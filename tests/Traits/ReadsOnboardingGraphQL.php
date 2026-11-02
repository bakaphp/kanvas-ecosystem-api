<?php

declare(strict_types=1);

namespace Tests\Traits;

use Illuminate\Testing\TestResponse;
use Kanvas\Companies\Models\Companies;

trait ReadsOnboardingGraphQL
{
    /**
     * The field's payload, or a failure that prints the whole envelope: an error-only body would
     * otherwise surface as an opaque "Undefined array key".
     *
     * @return array<string, mixed>
     */
    protected function graphQLData(TestResponse $response, string $field): array
    {
        $data = $response->json('data.' . $field);
        $this->assertNotNull($data, json_encode($response->json()));

        return $data;
    }

    protected function currentCompany(): Companies
    {
        return auth()->user()->getCurrentCompany();
    }
}
