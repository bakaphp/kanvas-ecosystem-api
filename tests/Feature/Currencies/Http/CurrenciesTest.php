<?php

namespace Tests\Feature\Currencies\Http;

use Tests\TestCase;

class CurrenciesTest extends TestCase
{
    /**
     * Get all currencies.
     *
     * @return void
     */
    public function testGetAllCurrencies()
    {
        $response = $this->get('/v1/currencies');
        $response->assertStatus(200);
    }
}
