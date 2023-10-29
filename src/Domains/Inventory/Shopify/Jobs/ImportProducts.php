<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Shopify\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Inventory\Shopify\Client;

class ImportProducts implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $graphQL = <<<Query
        query{
            products(first: 10){
                nodes {
                    id,
                    description,
                    descriptionHtml,
                    options{
                        id,
                        name,
                        values
                    },
                    productCategory{
                        productTaxonomyNode{
                            id,
                            name
                        }
                    },
                    productType,
                    title,
                }
                edges {
                    cursor
                }
            }
        }
        Query;
        $shopifyClient = Client::getClient('https://frederick-penalo.myshopify.com');
        $response = $shopifyClient->post('', [
            'json' => ['query' => $graphQL]
        ]);
        dd(json_decode($response->getBody()->getContents()));
        // $data = $shopify->GraphQL->post($graphQL);
        // foreach ($data['data']['products']['edges'] as $product) {

        //     Query;
        //     $variables = [
        //         'id' => $product['node']['id']
        //     ];
        //     $product = $shopify->GraphQL->post($graphQL, null, null, $variables);

        //     dump($product);
        // }
    }
}
