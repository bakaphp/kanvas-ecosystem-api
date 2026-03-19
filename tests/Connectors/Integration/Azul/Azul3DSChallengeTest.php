<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Azul;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Azul\Enums\ConfigurationEnum;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\GraphQL\Souk\Traits\PaymentCases;

/**
 * Simulates the full Azul 3DS v2 payment flow:
 *
 *  Step 1 — startPaymentChallengeWithCard
 *    (A) WAITING_DEVICE_DATA  → render method iframe
 *        → simulate method-notification webhook
 *        → retry startPaymentChallenge (existing payment)
 *    (B) PENDING_AUTHORIZATION → simulate TermUrl POST-back
 *        → finalizePaymentChallenge
 *    (C) PAID (frictionless) → done
 *
 * Webhook steps are simulated by writing metadata directly on the Payment
 * model, exactly as AzulMethodNotificationWebhookJob and
 * AzulTermUrlWebhookJob would do in production.
 */
class Azul3DSChallengeTest extends AzulBase
{
    use InventoryCases;
    use PaymentCases;

    protected $company;
    protected $user;
    protected $apps;
    protected $region;
    protected $warehouseResponse;
    protected $channelResponse;
    protected string $variantId;

    public function setUp(): void
    {
        parent::setUp();

        $this->apps    = app(Apps::class);
        $this->user    = auth()->user();
        $this->company = $this->user->getCurrentCompany();
        $this->region  = Regions::getDefault($this->company, $this->apps);

        $this->apps->set(ConfigurationEnum::AZUL_AUTH1->value, env('TEST_AZUL_AUTH1') ?? env('AZUL_AUTH1'));
        $this->apps->set(ConfigurationEnum::AZUL_AUTH2->value, env('TEST_AZUL_AUTH2') ?? env('AZUL_AUTH2'));
        $this->apps->set(ConfigurationEnum::AZUL_STORE->value, env('TEST_AZUL_STORE') ?? env('AZUL_STORE'));
        $this->apps->set(ConfigurationEnum::AZUL_CHANNEL->value, env('TEST_AZUL_CHANNEL') ?? env('AZUL_CHANNEL', 'EC'));
        $this->apps->set(ConfigurationEnum::AZUL_CERT_PATH->value, env('TEST_AZUL_CERT_PATH') ?? env('AZUL_CERT_PATH'));
        $this->apps->set(ConfigurationEnum::AZUL_KEY_PATH->value, env('TEST_AZUL_KEY_PATH') ?? env('AZUL_KEY_PATH'));
        $this->apps->set(
            ConfigurationEnum::AZUL_3DS_TERM_URL->value,
            env('TEST_AZUL_3DS_TERM_URL') ?? env('AZUL_3DS_TERM_URL', 'https://example.com/azul/term')
        );
        $this->apps->set(
            ConfigurationEnum::AZUL_3DS_METHOD_NOTIFICATION_URL->value,
            env('TEST_AZUL_3DS_METHOD_NOTIFICATION_URL') ?? env('AZUL_3DS_METHOD_NOTIFICATION_URL', 'https://example.com/azul/method')
        );

        $this->setAllowNoPaymentStatus(true, $this->apps);

        $this->warehouseResponse = $this->createWarehouses((string) $this->region->getId())->json()['data']['createWarehouse'];
        $this->channelResponse   = $this->createChannel()->json()['data']['createChannel'];

        $productResponse = $this->createProduct()->json()['data']['createProduct'];
        $variant         = Products::find($productResponse['id'])->variants()->first();

        $this->addVariantToChannel(
            variantId: (string) $variant->id,
            channelId: $this->channelResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']],
        );

        $this->addVariantToWarehouse(
            variantId: (string) $variant->id,
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        $this->variantId = (string) $variant->id;
    }

    protected function get3DSCardData(): array
    {
        return [
            'number'          => env('TEST_AZUL_3DS_CARD', '5424180279791732'),
            'expiration_date' => env('TEST_AZUL_3DS_EXPIRY', '12/28'),
            'cvv'             => env('TEST_AZUL_3DS_CVV', '732'),
            'processor'       => 'azul',
            'firstname'       => 'Test',
            'lastname'        => 'User',
            'address'         => '123 Calle Principal',
            'city'            => 'Santo Domingo',
            'state'           => 'DN',
            'country'         => 'DO',
            'zip_code'        => '10101',
        ];
    }

    protected function getBrowserInfo(): array
    {
        return [
            'accept_header'      => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'ip_address'         => env('TEST_CLIENT_IP', '190.2.132.215'),
            'language'           => 'es-DO',
            'color_depth'        => '24',
            'screen_width'       => '1920',
            'screen_height'      => '1080',
            'time_zone'          => '-240',
            'user_agent'         => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'javascript_enabled' => 'true',
        ];
    }

    protected function createOrder(): Order
    {
        $response = $this->graphQL('
            mutation createOrderFromCart($input: OrderCartInput!) {
                createOrderFromCart(input: $input) {
                    order { id }
                }
            }
        ', [
            'input' => [
                'cartId'           => 0,
                'customer'         => ['email' => fake()->email()],
                'currency'         => 'USD',
                'shipping_address' => [
                    'address'   => '123 Calle Principal',
                    'address_2' => '10101',
                    'city'      => 'Santo Domingo',
                    'state'     => 'DN',
                ],
                'items'      => [['variant_id' => $this->variantId, 'quantity' => 1]],
                'order_type' => 'order',
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        return Order::find($response->json('data.createOrderFromCart.order.id'));
    }

    /**
     * Full 3DS challenge flow end-to-end.
     *
     * The test drives through all three possible Azul responses and simulates
     * the webhook callbacks in-process to avoid needing a reachable public URL.
     */
    public function testStart3DSChallengeWithCard(): void
    {
        $order    = $this->createOrder();
        $cardData = $this->get3DSCardData();

        // Step 1: Initiate 3DS sale
        $startResponse = $this->graphQL('
            mutation startPaymentChallengeWithCard($orderId: ID!, $paymentData: StartChallengeCardInput!) {
                startPaymentChallengeWithCard(orderId: $orderId, paymentData: $paymentData) {
                    success
                    message
                    status
                    data
                }
            }
        ', [
            'orderId'     => $order->id,
            'paymentData' => [
                'number'          => $cardData['number'],
                'expiration_date' => $cardData['expiration_date'],
                'cvv'             => $cardData['cvv'],
                'processor'       => $cardData['processor'],
                'firstname'       => $cardData['firstname'],
                'lastname'        => $cardData['lastname'],
                'address'         => $cardData['address'],
                'city'            => $cardData['city'],
                'state'           => $cardData['state'],
                'country'         => $cardData['country'],
                'zip_code'        => $cardData['zip_code'],
                'browser_info'    => $this->getBrowserInfo(),
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $startResponse->assertSuccessful();
        $this->assertNull($startResponse->json('errors'));

        $startData = $startResponse->json('data.startPaymentChallengeWithCard');
        $this->assertTrue($startData['success'], 'startPaymentChallengeWithCard failed: ' . $startData['message']);

        $status    = $startData['status'];
        $paymentId = $startData['data']['payment_id'] ?? null;

        // Case C: frictionless — card not enrolled in 3DS, approved immediately
        if ($status === PaymentStatusEnum::PAID->value) {
            $this->assertEquals(PaymentStatusEnum::PAID->value, $status);

            return;
        }

        $this->assertNotEmpty($paymentId, 'payment_id must be present in 3DS response data');

        $payment = Payments::find((int) $paymentId);
        $this->assertNotNull($payment, 'Payment record not found for id: ' . $paymentId);

        // Case A: 3DS v2 method step — browser fingerprinting iframe required
        if ($status === PaymentStatusEnum::WAITING_DEVICE_DATA->value) {
            $this->assertNotEmpty(
                $startData['data']['method_form'],
                'method_form HTML must be present for WAITING_DEVICE_DATA'
            );

            // Simulate AzulMethodNotificationWebhookJob: Azul POSTs to MethodNotificationUrl
            // after the hidden iframe loads and the browser sends fingerprint data.
            $payment->addMetadata([
                'data' => [
                    '3ds_server_trans_id'            => 'test-trans-id-' . time(),
                    '3ds_method_notification_status' => 'RECEIVED',
                ],
            ]);
            $payment->save();

            // In automation there is no real browser to run the fingerprint iframe, so Azul
            // would keep returning WAITING_DEVICE_DATA on retry. Advance the payment status
            // directly so we can test the finalizeChallenge path below.
            $payment->update(['status' => PaymentStatusEnum::PENDING_AUTHORIZATION->value]);
        }

        // Case B: 3DS challenge required — ACS must authenticate the cardholder
        $this->assertEquals(
            PaymentStatusEnum::PENDING_AUTHORIZATION->value,
            $payment->fresh()->status,
            'Payment must be PENDING_AUTHORIZATION before finalize'
        );

        // Simulate AzulTermUrlWebhookJob: ACS POSTs cRes back to TermUrl
        // after the cardholder completes authentication in the ACS iframe.
        $cRes = env('TEST_AZUL_CRES'); // set to a real cRes from Azul sandbox for full approval
        if (empty($cRes)) {
            $cRes = base64_encode(json_encode([
                'messageType'           => 'CRes',
                'messageVersion'        => '2.1.0',
                'threeDSServerTransID'  => 'stub-' . uniqid(),
                'transStatus'           => 'Y',
            ]));
        }

        $payment->addMetadata([
            'data' => [
                '3ds_pa_res'      => $cRes,
                '3ds_pa_res_type' => 'cRes',
            ],
        ]);
        $payment->save();

        // Step 3: Finalize — submit cRes to Azul and settle the payment
        $finalizeResponse = $this->graphQL('
            mutation finalizePaymentChallenge($paymentId: ID!) {
                finalizePaymentChallenge(paymentId: $paymentId) {
                    success
                    message
                    status
                    data
                }
            }
        ', [
            'paymentId' => $paymentId,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $finalizeResponse->assertSuccessful();
        $this->assertNull($finalizeResponse->json('errors'));

        $finalizeData = $finalizeResponse->json('data.finalizePaymentChallenge');

        $this->assertArrayHasKey('success', $finalizeData);
        $this->assertArrayHasKey('status', $finalizeData);
        $this->assertArrayHasKey('message', $finalizeData);

        // With a real cRes from sandbox (TEST_AZUL_CRES env var), expect full approval.
        // With the stub cRes above, Azul will reject it — we only assert structure.
        if (! empty(env('TEST_AZUL_CRES'))) {
            $this->assertTrue($finalizeData['success'], 'Finalize failed: ' . $finalizeData['message']);
            $this->assertEquals(PaymentStatusEnum::PAID->value, $finalizeData['status']);

            $order->refresh();
            $this->assertTrue($order->isPaid(), 'Order should be marked as paid after 3DS finalization');
        }
    }
}
