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
        $response = $this->get('/currencies');
        $response->assertStatus(200);
    }
}
