<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Airalo\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\Airalo\Client;
use Kanvas\Exceptions\ValidationException;
use Exception;

class AiraloOrderService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app
    ) {
        $this->client = new Client($app);
    }

    public function makeOrder($bundles): array
    {
        try {
            $order = $this->createOrder([
                'quantity' => $bundles[0]['quantity'],
                'package_id' => $bundles[0]['item'],
                'type' => 'sim',
                'description' => $bundles[0]['quantity'] . ' ' . $bundles[0]['item'],
            ]);

            if (!isset($order['data']['sims'][0]['qrcode'])) {
                throw new ValidationException('QR Code not found');
            }

            if (!isset($order['data']['sims'][0]['iccid'])) {
                throw new ValidationException('ICCID not found');
            }

            $lpaCode = $order['data']['sims'][0]['qrcode'];
            $iccid = $order['data']['sims'][0]['iccid'];
            $smdpAddress = $order['data']['sims'][0]['lpa'];
            $matchingId = $order['data']['sims'][0]['matching_id'];
            $apnType = $order['data']['sims'][0]['apn_type'];
            $apnValue = $order['data']['sims'][0]['apn_value'];

            return [
                'status' => $order['meta']['message'],
                'order_reference' => $order['data']['code'],
                'lpa_code' => $lpaCode,
                'iccid' => $iccid,
                'plan' => $order['data']['package_id'],
                'description' => $order['data']['description'],
                'smdp_address' => $smdpAddress,
                'matching_id' => $matchingId,
                'apn_type' => $apnType,
                'apn_value' => $apnValue,
                'quantity' => $order['data']['quantity'],
                'price' => $order['data']['price'],
            ];
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function rechargeOrder($iccid, $bundleName): array
    {
        try {
            $rechargeData = $this->client->post('/orders/topups', [
                'iccid' => $iccid,
                'package_id' => $bundleName . '-topup',
                'description' => '',
            ], true);

            return [
                'id' => $rechargeData['data']['id'],
                'description' => $rechargeData['data']['description'],
                'plan' => $rechargeData['data']['package_id'],
                'quantity' => $rechargeData['data']['quantity'],
                'price' => $rechargeData['data']['price'],
                'code_reference' => $rechargeData['data']['code'],
            ];
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function createOrder($options): array
    {
        try {
            return $this->client->post('/orders', $options, true);
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function checkStatus(string $iccid): array
    {
        try {
            if (empty($iccid)) {
                throw new ValidationException('ICCID cannot be empty');
            }

            $response = $this->client->get("/sims/{$iccid}/usage", [], true);

            return [
                'status' => $response['data']['status'],
                'remaining' => $response['data']['remaining'],
                'total' => $response['data']['total'],
            ];
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }
}
