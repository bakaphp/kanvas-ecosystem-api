<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ESim;

use Baka\Traits\KanvasJobsTrait;
use DateTime;
use Exception;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\EasyActivation\Services\OrderService;
use Kanvas\Connectors\ESimGo\Enums\IccidStatusEnum;
use Kanvas\Connectors\ESimGo\Services\ESimService;
use Kanvas\Social\Messages\Models\Message;

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
            ->whereIsPublic()
            ->orderBy('id', 'desc')
            ->get();

        $eSimService = new ESimService($app);
        $easyActivationOrderService = new OrderService($app);

        foreach ($messages as $message) {
            $this->processMessage($message, $eSimService, $easyActivationOrderService);
        }
    }

    private function processMessage(Message $message, ESimService $eSimService, OrderService $easyActivationOrderService): void
    {
        $iccid = $message->message['data']['iccid'] ?? null;
        $bundle = $message->message['data']['plan'] ?? null;
        $network = strtolower($message->message['items'][0]['variant']['attributes']['Variant Network'] ?? '');

        if (! $iccid) {
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
                $easyActivationOrderService
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
        OrderService $easyActivationOrderService
    ): ?array {
        switch ($network) {
            case 'esimgo':
                $iccidStatus = $eSimService->checkStatus($iccid);
                $response = $eSimService->getAppliedBundleStatus($iccid, $bundle);

                return $this->formatEsimGoResponse($message, $response, $iccidStatus);
            case 'easyactivations':
                return $this->formatEasyActivationResponse(
                    $message,
                    $easyActivationOrderService->checkStatus($iccid)
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

    private function updateMessageStatus(Message $message, array $response, string $network): void
    {
        $messageData = $message->message;
        $messageData['esim_status'] = $response;
        $message->message = $messageData;
        $message->saveOrFail();

        $this->info("Message ID: {$message->id} has been updated with the eSIM status.");

        $inactiveStatuses = [
            IccidStatusEnum::INACTIVE->value,
            IccidStatusEnum::EXPIRED->value,
            IccidStatusEnum::COMPLETED->value,
        ];

        if (in_array(strtolower($response['bundleState']), $inactiveStatuses, true)) {
            $message->setPrivate();
            $this->info("Message ID: {$message->id} has been set to private.");
        }
    }
}
