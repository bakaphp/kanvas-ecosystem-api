<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESimGo\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\ESimGo\Client;

class EsimGoOrderService
{
    protected Client $client;
    protected int $perPage = 4000;

    public function __construct(
        protected AppInterface $app
    ) {
        $this->client = new Client($app);
    }

    // Order related functions
    public function createOrder($options): array
    {
        return $this->client->post('/v2.4/orders', $options);
    }

    public function makeOrder($bundles): array
    {
        // Step 1: Create ESimGo Order
        $order = $this->createOrder([
            'type' => 'transaction',
            'assign' => false,
            'Order' => $bundles,
        ]);

        // Step 2: Apply eSIM
        $applicationData = $this->client->post('/v2.4/esims/apply', [
            'iccid' => '',
            'name' => $bundles[0]['item'],
            'startTime' => '',
            'repeat' => 0,
        ]);

        $iccid = $applicationData['esims'][0]['iccid'];

        // Step 3: Get eSIM Details
        $esimDetail = $this->client->get("/v2.4/esims/{$iccid}");
        $matchingId = $esimDetail['matchingId'];
        $smdpAddress = $esimDetail['smdpAddress'];
        $puk = $esimDetail['puk'];
        $firstInstalledDateTime = $esimDetail['firstInstalledDateTime'];

        // Step 4: Generate LPA Code
        $lpaCode = 'LPA:1$' . $smdpAddress . '$' . $matchingId;

        return [
            'puk' => $puk,
            'lpa_code' => $lpaCode,
            'iccid' => $iccid,
            'status' => $order['status'],
            'quantity' => $order['order'][0]['quantity'],
            'price_per_unit' => $order['order'][0]['pricePerUnit'],
            'type' => $order['order'][0]['type'],
            'plan' => $order['order'][0]['item'],
            'smdp_address' => $smdpAddress,
            'matching_id' => $matchingId,
            'first_installed_datetime' => $firstInstalledDateTime,
            'order_reference' => $order['orderReference'],
        ];
    }

    public function rechargeOrder($iccid, $bundleName): array
    {
        // Step 1: Create Order
        $this->createOrder([
            'type' => 'transaction',
            'assign' => false,
            'Order' => [
                [
                    'type' => 'bundle',
                    'quantity' => 1,
                    'item' => $bundleName,
                ],
            ],
        ]);

        // Step 2: Apply eSIM
        $applicationData = $this->client->post('/v2.4/esims/apply', [
            'iccid' => $iccid,
            'name' => $bundleName,
            'startTime' => '',
            'repeat' => 0,
        ]);

        $data = reset($applicationData['esims']);

        return [
            'id' => 'N/A',
            'iccid' => $data['iccid'],
            'plan' => $data['bundle'],
            'description' => $data['status'],
            'quantity' => 1,
            'creation_date' => date('Y-m-d'),
            'price' => 'N/A',
            'code_reference' => $applicationData['applyReference'] ?? 'N/A',
        ];
    }

    // Fetch related functions
    public function fetchAllPackagesAndCountries($page = 1): array
    {
        return $this->client->get("/v2.4/catalogue?perPage={$this->perPage}&page={$page}");
    }

    public function fetchBundleGroups(): array
    {
        return $this->client->get('/v2.4/organisation/groups');
    }

    public function fetchBundlesByGroup($group, $page = 1): array
    {
        return $this->client->get("/v2.4/catalogue?perPage={$this->perPage}&group={$group}&page={$page}");
    }

    public function fetch(): array
    {
        $page = 1;
        $allBundles = [];
        
        do {
            $response = $this->fetchAllPackagesAndCountries($page);
            $allBundles = array_merge($allBundles, $response['bundles'] ?? []);
            $page++;
        } while ($page <= ($response['pageCount'] ?? 1));

        return $allBundles;
    }
} 