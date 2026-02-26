<?php

declare(strict_types=1);

namespace Tests\GraphQL\Connector\Movipass;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PasoRapidoRechargeStatsTest extends TestCase
{
    use DatabaseTransactions;

    public function testExportOrderPaymentsForPasoRapido(): void
    {
        $this->graphQL('
            query {
                exportOrderPayments(
                    input: { orderTypeNames: ["paso_rapido"], paidStates: ["paid"] }
                    format: EXCEL
                ) {
                    status
                    download_url
                    file_name
                    message
                }
            }
        ')->assertSuccessful()
          ->assertJsonStructure([
              'data' => [
                  'exportOrderPayments' => [
                      'status',
                      'download_url',
                      'file_name',
                      'message',
                  ],
              ],
          ]);
    }

    public function testListOrdersFilteredByPasoRapidoPaymentStatus(): void
    {
        $this->graphQL('
            query {
                orders(
                    first: 25
                    where: {
                        AND: [
                            { column: PAYMENT_STATUS, operator: EQ, value: "paid" }
                            { column: TOTAL_NET_AMOUNT, operator: GT, value: "0" }
                        ]
                    }
                    orderType: { column: NAME, operator: EQ, value: "paso_rapido" }
                    orderBy: [{ column: CREATED_AT, order: DESC }]
                ) {
                    data {
                        id
                        order_number
                        total_net_amount
                        created_at
                        metadata
                        user { id firstname lastname email }
                    }
                    paginatorInfo { total currentPage lastPage }
                }
            }
        ')->assertSuccessful()
          ->assertJsonStructure([
              'data' => [
                  'orders' => [
                      'data'          => [],
                      'paginatorInfo' => ['total', 'currentPage', 'lastPage'],
                  ],
              ],
          ]);
    }
}
