<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ESim;

use Baka\Traits\KanvasJobsTrait;
use Carbon\Carbon;
use DateTime;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\CMLink\Enums\PlanTypeEnum;
use Kanvas\Connectors\CMLink\Services\CustomerService;
use Kanvas\Connectors\EasyActivation\Services\OrderService;
use Kanvas\Connectors\ESim\DataTransferObject\ESimStatus;
use Kanvas\Connectors\ESim\Enums\ConfigurationEnum;
use Kanvas\Connectors\ESim\Enums\ProviderEnum;
use Kanvas\Connectors\ESim\Support\FileSizeConverter;
use Kanvas\Connectors\ESimGo\Enums\IccidStatusEnum;
use Kanvas\Connectors\ESimGo\Services\ESimService;
use Kanvas\Connectors\VentaMobile\Services\ESimService as VentaMobileESimService;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

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

        Order::disableSearchSyncing();

        // Process messages in chunks to avoid memory exhaustion
        $chunkSize = 100; // Adjust based on your server's memory capacity

        Message::fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->whereIsPublic()
            ->orderBy('id', 'desc')
            ->whereHas('messageType', function ($q) {
                $q->where('verb', 'esim');
            })
            ->chunk($chunkSize, function ($messages) use ($app, $company) {
                // Initialize services once per chunk
                $eSimService = new ESimService($app);
                $easyActivationOrderService = new OrderService($app);
                $cmLinkCustomerService = new CustomerService($app, $company);
                $ventaMobileService = new VentaMobileESimService($app, $company);

                foreach ($messages as $message) {
                    $this->processMessage(
                        $message,
                        $eSimService,
                        $easyActivationOrderService,
                        $cmLinkCustomerService,
                        $ventaMobileService
                    );
                }
            });
    }

    private function processMessage(
        Message $message,
        ESimService $eSimService,
        OrderService $easyActivationOrderService,
        CustomerService $cmLinkCustomerService,
        VentaMobileESimService $ventaMobileService
    ): void {
        $iccid = $message->message['data']['iccid'] ?? null;
        $bundle = $message->message['data']['plan'] ?? null;
        //$network = strtolower($message->message['items'][0]['variant']['attributes']['Variant Network'] ?? '');
        $network = '';
        $variantNetwork = '';

        if (isset($message->message['items'][0]['variant']['products_id'])) {
            $network = strtolower(Products::getById($message->message['items'][0]['variant']['products_id'])->getAttributeBySlug(ConfigurationEnum::PROVIDER_SLUG->value)?->value ?? '');
        }

        if (empty($network) && $message->appModuleMessage && $message->appModuleMessage->entity instanceof Order) {
            $network = strtolower($message->appModuleMessage->entity->items()->first()->variant?->product?->getAttributeBySlug(ConfigurationEnum::PROVIDER_SLUG->value)?->value ?? '');
        }

        if ($message->appModuleMessage && $message->appModuleMessage->entity instanceof Order) {
            $variantNetwork = $message->appModuleMessage->entity->items()->first()->variant?->getAttributeBySlug(ConfigurationEnum::VARIANT_PROVIDER_SLUG->value)?->value ?? '';
        }

        if ($variantNetwork !== '') {
            $network = strtolower($variantNetwork);
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
                $cmLinkCustomerService,
                $ventaMobileService
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
        CustomerService $cmLinkCustomerService,
        VentaMobileESimService $ventaMobileService
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
                    $cmLinkCustomerService->getEsimInfo($iccid)['data'],
                    $cmLinkCustomerService,
                );

            case strtolower(ProviderEnum::VENTA_MOBILE->value):
                $serviceInfoArr = $ventaMobileService->getServiceByIccid($iccid);
                if (empty($serviceInfoArr)) {
                    return null;
                }
                $serviceInfo = $serviceInfoArr[0];
                $serviceId = $serviceInfo['services_info']['id_service_inst'] ?? null;
                if (! $serviceId) {
                    return null;
                }
                $balance = $ventaMobileService->getServiceBalance($serviceId);

                $extensionDetails = null;

                try {
                    $extensionDetails = $ventaMobileService->getServiceExtensions($serviceId);
                } catch (Exception $e) {
                    // Extension details are optional, continue without them
                }

                return $this->formatVentaMobileResponse($message, $serviceInfo, $balance, $extensionDetails);
            default:
                return null;
        }
    }

    private function formatEsimGoResponse(Message $message, array $response, array $iccidStatus): array
    {
        $firstInstallTimestamp = $iccidStatus['firstInstalledDateTime'] ?? null;
        $installDate = $firstInstallTimestamp
            ? (new DateTime())->setTimestamp((int) ($firstInstallTimestamp / 1000))
            : null;

        $esimStatus = [
            ...$response,
            'bundleState' => IccidStatusEnum::getStatus($iccidStatus['profileStatus']),
            'installed_date' => $installDate?->format('Y-m-d H:i:s'),
            'expiration_date' => null,
            'phone_number' => null,
        ];
        // Notifications for EsimGo
        $isValidState = in_array(strtolower($esimStatus['bundleState']), ['released', 'installed', 'active', 'enabled', 'enable']);
        $this->checkAndSendNotifications($message, $esimStatus, $isValidState);

        return $esimStatus;
    }

    private function formatEasyActivationResponse(Message $message, array $response): array
    {
        $esimStatus = [
            ...$response,
            'bundleState' => strtolower($response['esim_status']),
            'installed_date' => null,
            'expiration_date' => isset($response['expire_date'])
                ? (new DateTime($response['expire_date']))->format('Y-m-d\TH:i:s.u\Z')
                : null,
            'phone_number' => null,
        ];
        // Notifications for EasyActivation
        $isValidState = in_array(strtolower($esimStatus['bundleState']), ['released', 'installed', 'active', 'enabled', 'enable']);
        $this->checkAndSendNotifications($message, $esimStatus, $isValidState);

        return $esimStatus;
    }

    private function formatCmLinkResponse(Message $message, array $response, CustomerService $cmLinkCustomerService): array
    {
        $iccid = $message->message['data']['iccid'] ?? null;
        $userPlans = $cmLinkCustomerService->getUserPlans($iccid);
        $estTimezone = 'America/New_York';
        $nowInEST = Carbon::now()->setTimezone($estTimezone);

        $activePlan = null;
        $planStatus = null;

        // First determine if there's a valid active plan
        if ($iccid && ! empty($userPlans['userDataBundles'])) {
            foreach ($userPlans['userDataBundles'] as $plan) {
                if ($plan['status'] == 3) {
                    if (! empty($plan['expireTime'])) {
                        // Convert the expireTime to EST timezone before comparison
                        $expireTimeInEST = Carbon::parse($plan['expireTime'])->setTimezone($estTimezone);
                        if ($nowInEST->greaterThan($expireTimeInEST)) {
                            continue;
                        }
                    }
                    $activePlan = $plan;
                    $planStatus = 'active';

                    break;
                }
            }

            if ($activePlan === null) {
                foreach ($userPlans['userDataBundles'] as $plan) {
                    if ($plan['status'] == 1) {
                        $activePlan = $plan;
                        $planStatus = 'released';

                        break;
                    }
                }
            }
        }

        $reportedState = strtolower($response['state']);

        if ($planStatus !== null) {
            $status = $planStatus;
        } else {
            // No conflict, use reported state
            $status = $reportedState;
        }

        $validStates = ['released', 'installed', 'active', 'enabled', 'enable'];
        $isValidState = in_array($status, $validStates);

        $installedDate = $response['installTime'] ?? (! empty($message->message['order']['created_at']) ? $message->message['order']['created_at'] : now()->format('Y-m-d H:i:s'));
        $isActive = IccidStatusEnum::getStatus($status) == 'active';

        $activationDate = null;
        if ($iccid && $isActive && $activePlan) {
            if (! empty($activePlan['activeTime'])) {
                $activationDate = $activePlan['activeTime'];
            }
        }

        $variant = $message->appModuleMessage->entity->items()->first()->variant;
        $totalData = $variant->getAttributeBySlug('data')?->value ?? 0;
        $totalBytesData = FileSizeConverter::toBytes($totalData);

        $remainingData = $totalBytesData;

        if ($iccid && $isValidState) {
            // Convert remainFlow to bytes - assuming it's in MB
            if (isset($activePlan['remainFlow'])) {
                $remainingData = (float)$activePlan['remainFlow'] * 1024 * 1024; // Convert MB to bytes
            }
        } elseif ($isValidState == false && $remainingData <= 0) {
            $remainingData = $totalBytesData;
        }

        if ($activationDate == null) {
            $expirationDate = null;
        } else {
            $expirationBaseDate = $activationDate;
            $expirationDate = Carbon::parse($expirationBaseDate)
                ->addDays((int) $variant->getAttributeBySlug('esim-days')?->value)
                ->format('Y-m-d H:i:s');
        }

        // Initialize spentMessage as null
        $spentMessage = null;
        if ($expirationDate != null) {
            $expirationDay = Carbon::parse($expirationDate)->setTimezone($estTimezone);
        }
        $today = Carbon::now()->setTimezone($estTimezone);

        if ($remainingData <= 0 && $isValidState == false) {
            $remainingData = $totalBytesData;
        } elseif ($remainingData > $totalBytesData) {
            $remainingData = $totalBytesData;
        } elseif ($remainingData == 0 && $isValidState == true && $expirationDate != null) {
            /**
             * @todo Move those spanish strings to app settings
             */
            if ($today->startOfDay()->equalTo($expirationDay->startOfDay()) || $today->greaterThan($expirationDay)) {
                $spentMessage = 'Has agotado el límite diario en alta velocidad, ahora estarás navegando en una velocidad de 384kbps';
            } else {
                $spentMessage = 'Has agotado el límite diario en alta velocidad, ahora estarás navegando en una velocidad de 384kbps hasta el siguiente día';
            }
        }

        if ($activationDate) {
            $activationDate = Carbon::parse($activationDate)->setTimezone($estTimezone)->format('Y-m-d H:i:s');
        }

        if ($expirationDate != null) {
            $expirationDate = Carbon::parse($expirationDate)->setTimezone($estTimezone)->format('Y-m-d H:i:s');
        }

        /**
         * @todo Move this to somewhere more central
         */
        if ($expirationDate == null) {
            $expired = false;
        } else {
            $expired = $nowInEST->greaterThan($expirationDate);
        }

        if ($expired) {
            $message->setPrivate();
        } elseif ($isValidState) {
            $message->setPublic();
        } else {
            $message->setPrivate();
        }

        $bundleStatus = isset($activePlan) ? IccidStatusEnum::getStatusById($activePlan['status']) : IccidStatusEnum::getStatus(strtolower($response['state']));

        if (! $expired && $bundleStatus == 'active') {
            $response['state'] = 'Enable';
        }

        $esimStatus = new ESimStatus(
            id: $response['activationCode'],
            callTypeGroup: 'data',
            initialQuantity: $totalBytesData,
            remainingQuantity: $remainingData,
            assignmentDateTime: $installedDate,
            assignmentReference: $response['activationCode'],
            bundleState: $bundleStatus,
            unlimited: $variant->getAttributeBySlug('variant-type')?->value === PlanTypeEnum::UNLIMITED->value,
            expirationDate: $expirationDate,
            imei: $message->message['data']['imei_number'] ?? null,
            esimStatus: $response['state'],
            message: $response['installDevice'],
            installedDate: $installedDate,
            activationDate: $activationDate,
            spentMessage: $spentMessage,
        );

        $esimStatusArray = $esimStatus->toArray();
        // Check and send notifications if needed
        $this->checkAndSendNotifications($message, $esimStatusArray, $isValidState);

        return $esimStatusArray;
    }

    /**
     * Check if notifications should be sent for a specific ESim and send them if needed
     */
    private function checkAndSendNotifications(Message $message, array $esimStatus, bool $isValidState): void
    {
        // If the ESim is not in a valid state, don't send notifications
        if (! $isValidState) {
            return;
        }

        // Get the source associated with the message
        $source = $message->message['order']['metadata']['source'] ?? null;
        if ($source !== 'mobile') {
            return;
        }

        $notifyUser = $message->user;
        if (! $notifyUser) {
            return;
        }

        // Check for required esimStatus fields
        if (! isset($esimStatus['unlimited'], $esimStatus['initialQuantity'], $esimStatus['remainingQuantity'])) {
            return;
        }

        if ($esimStatus['unlimited']) {
            $dataNotification = [
                'title' => 'Has alcanzado tu límite diario de datos a alta velocidad.',
                'message' => 'Ahora navegarás a una velocidad reducida de 384kbps.',
            ];
            $this->checkUnlimitedPlanUsage($esimStatus, $notifyUser, $message, $dataNotification);
            $this->checkUnlimitedPlanExpiration($esimStatus, $notifyUser, $message);
        } else {
            $this->checkDataUsageThresholds($esimStatus, $notifyUser, $message);
        }
    }

    /**
     * Check data usage thresholds and send notifications at 70% and 90% usage
     */
    private function checkDataUsageThresholds(array $esimStatus, Users $notifyUser, Message $message): void
    {
        if (! isset($esimStatus['initialQuantity'], $esimStatus['remainingQuantity'])) {
            return;
        }
        $initialQuantity = $esimStatus['initialQuantity'];
        $remainingQuantity = $esimStatus['remainingQuantity'];
        if (! is_numeric($initialQuantity) || ! is_numeric($remainingQuantity) || $initialQuantity <= 0) {
            return;
        }
        $usedPercentage = (($initialQuantity - $remainingQuantity) / $initialQuantity) * 100;
        if ($usedPercentage >= 70 && $usedPercentage < 75 && $message->get('sent_70') != true) {
            $this->sendPushNotification(
                $notifyUser,
                '¡Atención! Has usado el 70% de tus datos.',
                'Aún tienes conexión, pero tu plan está por agotarse. Verifica tu consumo en la app.',
                'plan-warning-usage-notification',
                $message,
                ['destination_id' => $message->getId(), 'destination_type' => 'MESSAGE'],
            );
            $message->set('sent_70', true);
        }
        if ($usedPercentage >= 90 && $usedPercentage < 95 && $message->get('sent_90') != true) {
            $this->sendPushNotification(
                $notifyUser,
                '¡Casi sin datos!',
                'Has consumido el 90% de tu plan. Considera recargar para seguir navegando sin interrupciones.',
                'plan-warning-usage-notification',
                $message,
                ['destination_id' => $message->getId(), 'destination_type' => 'MESSAGE'],
            );
            $message->set('sent_90', true);
        }
    }

    /**
     * Check if unlimited plan is about to expire and send notification
     */
    private function checkUnlimitedPlanExpiration(array $esimStatus, Users $notifyUser, Message $message): void
    {
        $expirationDate = $esimStatus['expirationDate'] ?? $esimStatus['expiration_date'] ?? null;
        if (! $expirationDate) {
            return;
        }

        try {
            $expirationDate = Carbon::parse($expirationDate);
        } catch (\Exception $e) {
            return;
        }
        $hoursLeft = now()->diffInHours($expirationDate);
        if ($hoursLeft >= 20 && $hoursLeft <= 24 && $message->get('sent_unlimited') != true) {
            $this->sendPushNotification(
                $notifyUser,
                '¡Tu plan está por finalizar!',
                'Aprovecha al máximo tu conexión. Tu plan ilimitado vence en menos de 24 horas.',
                'plan-warning-usage-notification',
                $message,
                ['destination_id' => $message->getId(), 'destination_type' => 'MESSAGE'],
            );
            $message->set('sent_unlimited', true);
        }
    }

    /**
     * Check if unlimited plan is about to expire and send notification
     */
    private function checkUnlimitedPlanUsage(array $esimStatus, Users $notifyUser, Message $message, array $dataNotification): void
    {
        if (! isset($esimStatus['initialQuantity'], $esimStatus['remainingQuantity'])) {
            return;
        }
        $initialQuantity = $esimStatus['initialQuantity'];
        $remainingQuantity = $esimStatus['remainingQuantity'];
        if (! is_numeric($initialQuantity) || ! is_numeric($remainingQuantity) || $initialQuantity <= 0) {
            return;
        }
        $usedPercentage = (($initialQuantity - $remainingQuantity) / $initialQuantity) * 100;
        if ($usedPercentage >= 100 && $message->get('sent_unlimited_usage') != true) {
            $this->sendPushNotification(
                $notifyUser,
                $dataNotification['title'],
                $dataNotification['message'],
                'plan-warning-usage-notification',
                $message,
                ['destination_id' => $message->getId(), 'destination_type' => 'MESSAGE'],
            );
            $message->set('sent_unlimited_usage', true);
        }
    }

    /**
     * Send push notification to user
     */
    private function sendPushNotification(
        Users $notifyUser,
        string $title,
        string $notificationMessage,
        string $templateName,
        Message $message,
        array $additionalData = [],
    ): void {
        $app = $message->app;

        $data = [
            'title' => $title,
            'message' => $notificationMessage,
            'app' => $app,
            'data' => $additionalData,
        ];

        $vias = [NotificationChannelEnum::getNotificationChannelBySlug('PUSH')];

        $notification = new Blank(
            $templateName,
            $data,
            $vias,
            $notifyUser
        );

        Notification::send(collect([$notifyUser]), $notification);
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

        $isUnlimited = $response['unlimited'] ?? false;
        $bundleState = strtolower($response['bundleState']);
        $expirationDate = isset($response['expiration_date']) ? Carbon::parse($response['expiration_date']) : null;

        $shouldForcePublic = $isUnlimited && in_array($bundleState, ['disabled', 'disable']) && $expirationDate && now()->lessThan($expirationDate);

        if (in_array($bundleState, $inactiveStatuses, true) && ! $shouldForcePublic) {
            $message->setPrivate();
            $this->info("Message ID: {$message->id} has been set to private.");
        } else {
            $message->setPublic();
            $this->info("Message ID: {$message->id} has been set to public.");
        }
    }

    private function formatVentaMobileResponse(Message $message, array $serviceInfo, array $balance, ?array $extensionDetails = null): array
    {
        $activationDate = null;
        $activationTimestamp = null;

        if (! empty($balance)) {
            foreach ($balance as $balanceEntry) {
                if (isset($balanceEntry['incomes']) && is_array($balanceEntry['incomes'])) {
                    foreach ($balanceEntry['incomes'] as $income) {
                        if (isset($income['dt_issue'])) {
                            $activationTimestamp = $income['dt_issue'];
                            $activationDate = Carbon::createFromTimestamp($activationTimestamp)->format('Y-m-d H:i:s');

                            break 2;
                        }
                    }
                }
            }
        }

        if (! $activationDate && ! empty($extensionDetails)) {
            foreach ($extensionDetails as $extension) {
                if (isset($extension['dt_start']) && is_numeric($extension['dt_start'])) {
                    $activationDate = Carbon::createFromTimestamp($extension['dt_start'])->format('Y-m-d H:i:s');

                    break;
                } elseif (isset($extension['dt_create']) && is_numeric($extension['dt_create'])) {
                    $activationDate = Carbon::createFromTimestamp($extension['dt_create'])->format('Y-m-d H:i:s');

                    break;
                }
            }
        }

        if (! $activationDate) {
            $orderCreationDate = $message->appModuleMessage->entity->created_at ?? null;
            $activationDate = $orderCreationDate ? Carbon::parse($orderCreationDate)->format('Y-m-d H:i:s') : '';
        }

        $expirationDate = null;
        if (! empty($balance)) {
            foreach ($balance as $balanceEntry) {
                if (isset($balanceEntry['dt_to']) && is_numeric($balanceEntry['dt_to'])) {
                    $expirationDate = Carbon::createFromTimestamp($balanceEntry['dt_to'])->format('Y-m-d H:i:s');

                    break;
                }
            }
        }

        if (! $expirationDate && ! empty($extensionDetails)) {
            foreach ($extensionDetails as $extension) {
                if (isset($extension['dt_end']) && is_numeric($extension['dt_end'])) {
                    $expirationDate = Carbon::createFromTimestamp($extension['dt_end'])->format('Y-m-d H:i:s');

                    break;
                } elseif (isset($extension['dt_stop']) && is_numeric($extension['dt_stop'])) {
                    $expirationDate = Carbon::createFromTimestamp($extension['dt_stop'])->format('Y-m-d H:i:s');

                    break;
                }
            }
        }

        // Calculate expiration from activation + plan days if not found in API
        if (! $expirationDate && $activationDate) {
            $variant = $message->appModuleMessage->entity->items()->first()->variant;
            $planDays = $variant->getAttributeBySlug('esim-days')?->value ?? $variant->getAttributeBySlug('esim_days')?->value ?? 0;
            if ($planDays > 0) {
                $expirationDate = Carbon::parse($activationDate)->addDays((int) $planDays)->format('Y-m-d H:i:s');
            }
        }

        $status = IccidStatusEnum::RELEASED->value;
        if ($expirationDate !== null && $expirationDate !== '') {
            $expiration = Carbon::parse($expirationDate);
            $status = $expiration->isFuture() ? IccidStatusEnum::ACTIVE->value : IccidStatusEnum::EXPIRED->value;
        }

        $phoneNumber = $serviceInfo['services_info']['msisdn'] ?? null;
        $variant = $message->appModuleMessage->entity->items()->first()->variant;

        $totalData = $variant->getAttributeBySlug('data')?->value ?? 0;
        $totalBytesData = FileSizeConverter::toBytes($totalData);
        $remainingData = $totalBytesData;

        if (! empty($balance)) {
            foreach ($balance as $bal) {
                // The 'value' field contains the remaining data in bytes
                if (isset($bal['value']) && is_numeric($bal['value'])) {
                    $remainingData = (float)$bal['value'];

                    break;
                }
            }
        }

        $esimStatus = new ESimStatus(
            id: (string) ($serviceInfo['services_info']['id_service_inst'] ?? ''),
            callTypeGroup: 'data',
            initialQuantity: $totalBytesData,
            remainingQuantity: $remainingData,
            assignmentDateTime: $activationDate,
            assignmentReference: (string) ($serviceInfo['services_info']['id_service_inst'] ?? ''),
            bundleState: $status,
            unlimited: false,
            phoneNumber: $phoneNumber,
            expirationDate: $expirationDate,
            imei: $message->message['data']['imei_number'] ?? null,
            esimStatus: $status,
            message: $serviceInfo['services_info']['description'] ?? null,
            installedDate: $activationDate,
            activationDate: $activationDate,
            spentMessage: null,
        );

        $esimStatusArray = $esimStatus->toArray();
        // Notifications for VentaMobile
        $isValidState = in_array(strtolower($esimStatusArray['esim_status']), ['released', 'installed', 'active', 'enabled', 'enable']);
        $this->checkAndSendNotifications($message, $esimStatusArray, $isValidState);

        return $esimStatusArray;
    }
}
