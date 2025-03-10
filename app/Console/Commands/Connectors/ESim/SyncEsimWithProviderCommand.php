<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ESim;

use Baka\Traits\KanvasJobsTrait;
use Carbon\Carbon;
use DateTime;
use Exception;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\CMLink\Enums\PlanTypeEnum;
use Kanvas\Connectors\CMLink\Services\CustomerService;
use Kanvas\Connectors\CMLink\Services\OrderService as ServicesOrderService;
use Kanvas\Connectors\EasyActivation\Services\OrderService;
use Kanvas\Connectors\ESim\DataTransferObject\ESimStatus;
use Kanvas\Connectors\ESim\Enums\ProviderEnum;
use Kanvas\Connectors\ESim\Support\FileSizeConverter;
use Kanvas\Connectors\ESimGo\Enums\IccidStatusEnum;
use Kanvas\Connectors\ESimGo\Services\ESimService;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Souk\Orders\Models\Order;

class SyncEsimWithProviderCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:esim-connector-sync-esim {app_id} {company_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Sync Esim with providers';

    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        $company = Companies::getById((int) $this->argument('company_id'));

        $messages = Message::fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            //->whereIsPublic()
            ->orderBy('id', 'desc')
            ->get();

        $eSimService = new ESimService($app);
        $easyActivationOrderService = new OrderService($app);
        $cmLinkCustomerService = new CustomerService($app, $company);

        foreach ($messages as $message) {
            $this->processMessage(
                $message,
                $eSimService,
                $easyActivationOrderService,
                $cmLinkCustomerService
            );
        }
    }

    private function processMessage(
        Message $message,
        ESimService $eSimService,
        OrderService $easyActivationOrderService,
        CustomerService $cmLinkCustomerService
    ): void {
        $iccid = $message->message['data']['iccid'] ?? null;
        $bundle = $message->message['data']['plan'] ?? null;
        //$network = strtolower($message->message['items'][0]['variant']['attributes']['Variant Network'] ?? '');
        $network = '';
        if (isset($message->message['items'][0]['variant']['products_id'])) {
            $network = strtolower(Products::getById($message->message['items'][0]['variant']['products_id'])->getAttributeBySlug('product-provider')?->value ?? '');
        }

        if (empty($network) && $message->appModuleMessage && $message->appModuleMessage->entity instanceof Order) {
            $network = strtolower($message->appModuleMessage->entity->items()->first()->variant?->product?->getAttributeBySlug('product-provider')?->value ?? '');
        }

        if (empty($network)) {
            $this->info("Message ID: {$message->id} does not have a network.");

            return;
        }

        if ($iccid === null) {
            $this->info("Message ID: {$message->id} does not have an ICCID.");

            return;
        }

        try {
            $response = $this->getProviderStatus(
                $message,
                $network,
                $iccid,
                $bundle,
                $eSimService,
                $easyActivationOrderService,
                $cmLinkCustomerService
            );
            if (empty($response)) {
                return;
            }

            $this->updateMessageStatus($message, $response, $network);
        } catch (Exception $e) {
            $this->info("Message ID: {$message->id} has an error: {$e->getMessage()}");
        }
    }

    private function getProviderStatus(
        Message $message,
        string $network,
        string $iccid,
        ?string $bundle,
        ESimService $eSimService,
        OrderService $easyActivationOrderService,
        CustomerService $cmLinkCustomerService
    ): ?array {
        switch ($network) {
            case strtolower(ProviderEnum::E_SIM_GO->value):
                $iccidStatus = $eSimService->checkStatus($iccid);
                $response = $eSimService->getAppliedBundleStatus($iccid, $bundle);

                return $this->formatEsimGoResponse($message, $response, $iccidStatus);
            case strtolower(ProviderEnum::EASY_ACTIVATION->value):
                return $this->formatEasyActivationResponse(
                    $message,
                    $easyActivationOrderService->checkStatus($iccid)
                );

            case strtolower(ProviderEnum::CMLINK->value):
                /*   print_r($cmLinkCustomerService->getEsimInfo($iccid));
                  print_r($cmLinkCustomerService->getUserPlans($iccid));
                  print_r($cmLinkCustomerService->getUsageDetails($iccid, '20250208', '20250215')); */
                $esimDataUsage = $cmLinkCustomerService->getEsimInfo($iccid);
                if (empty($esimDataUsage['data'])) {
                    return null;
                }

                return $this->formatCmLinkResponse(
                    $message,
                    $cmLinkCustomerService->getEsimInfo($iccid)['data']
                );

            default:
                return null;
        }
    }

    private function formatEsimGoResponse(Message $message, array $response, array $iccidStatus): array
    {
        $firstInstallTimestamp = $iccidStatus['firstInstalledDateTime'] ?? null;
        $installDate = $firstInstallTimestamp
            ? (new DateTime())->setTimestamp($firstInstallTimestamp / 1000)
            : null;

        return [
            ...$response,
            'bundleState' => IccidStatusEnum::getStatus($iccidStatus['profileStatus']),
            'installed_date' => $installDate?->format('Y-m-d H:i:s'),
            'expiration_date' => null,
            'phone_number' => null,
        ];
    }

    private function formatEasyActivationResponse(Message $message, array $response): array
    {
        return [
            ...$response,
            'bundleState' => strtolower($response['esim_status']),
            'installed_date' => null,
            'expiration_date' => isset($response['expire_date'])
                ? (new DateTime($response['expire_date']))->format('Y-m-d\TH:i:s.u\Z')
                : null,
            'phone_number' => null,
        ];
    }

    private function formatCmLinkResponse(Message $message, array $response): array
    {
        $installedDate = $response['installTime'] ?? (! empty($message->message['order']['created_at']) ? $message->message['order']['created_at'] : now()->format('Y-m-d H:i:s'));

        $variant = $message->appModuleMessage->entity->items()->first()->variant;
        $totalData = $variant->getAttributeBySlug('data')?->value ?? 0;
        $orderNumber = $message->message['order']['order_number'] ?? null;
        $dataUsage = 0;
        $totalBytesData = FileSizeConverter::toBytes($totalData);
        if ($orderNumber !== null) {
            $orderService = new ServicesOrderService($message->app, $message->company);
            $dataUsage = $orderService->getOrderStatus($orderNumber)['total'];
        }
        // Calculate remaining data usage, ensuring it doesn't go negative
        $remainingData = max(0, $totalBytesData - max(0, $dataUsage));

        $esimStatus = new ESimStatus(
            id: $response['activationCode'],
            callTypeGroup: 'data',
            initialQuantity: $totalBytesData,
            remainingQuantity: $remainingData,
            assignmentDateTime: $installedDate,
            assignmentReference: $response['activationCode'],
            bundleState: IccidStatusEnum::getStatus(strtolower($response['state'])),
            unlimited: $variant->getAttributeBySlug('variant-type')?->value === PlanTypeEnum::UNLIMITED->value,
            expirationDate: Carbon::parse($installedDate)->addDays((int) $variant->getAttributeBySlug('esim-days')?->value)->format('Y-m-d H:i:s'),
            imei: $message->message['data']['imei_number'] ?? null,
            esimStatus: $response['state'],
            message: $response['installDevice'],
            installedDate: $installedDate,
        );

        return $esimStatus->toArray();
    }

    private function updateMessageStatus(Message $message, array $response, string $network): void
    {
        $messageData = $message->message;
        $messageData['esim_status'] = $response;
        $message->message = $messageData;
        $message->saveOrFail();

        $order = $message->appModuleMessage->entity;
        $metadata = is_array($order->metadata) ? $order->metadata : [];
        $metadata['esim_status'] = $response;
        $order->metadata = $metadata;
        $order->saveOrFail();

        $this->info("Message ID: {$message->id} has been updated with the eSIM status.");

        $inactiveStatuses = [
            IccidStatusEnum::INACTIVE->value,
            IccidStatusEnum::EXPIRED->value,
            IccidStatusEnum::COMPLETED->value,
            IccidStatusEnum::DELETED->value,
            IccidStatusEnum::DISABLED->value,
            IccidStatusEnum::DISABLE->value,
        ];

        if (in_array(strtolower($response['bundleState']), $inactiveStatuses, true)) {
            $message->setPrivate();
            $this->info("Message ID: {$message->id} has been set to private.");
        } else {
            $message->setPublic();
            $this->info("Message ID: {$message->id} has been set to public.");
        }
    }
}
