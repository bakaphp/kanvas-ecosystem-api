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

    /**
     * @todo refactor this
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));

        $messages = Message::fromApp($app)->fromCompany($company)->notDeleted()->whereIsPublic()->orderBy('id', 'desc')->get();

        $eSimService = new ESimService($app);
        $easyActivationOrderService = new OrderService($app);

        foreach ($messages as $message) {
            $iccid = $message->message['data']['iccid'] ?? null;
            $bundle = $message->message['data']['plan'] ?? null;
            $network = strtolower($message->message['items'][0]['variant']['attributes']['Variant Network'] ?? '') ?: '';

            if ($iccid == null) {
                $this->info("Message ID: {$message->id} does not have an ICCID.");
                //$message->setPrivate();

                continue;
            }

            try {
                if ($network == 'esimgo') {
                    $response = $eSimService->getAppliedBundleStatus($iccid, $bundle);
                    $iccidStatus = $eSimService->checkStatus($iccid);
                } elseif ($network == 'easyactivations') {
                    $response = $easyActivationOrderService->checkStatus($iccid);
                } else {
                    $this->info("Message ID: {$message->id} does not have a valid network.");
                    //$message->setPrivate();

                    continue;
                }
            } catch (Exception $e) {
                $this->info("Message ID: {$message->id} has an error: {$e->getMessage()}");
                //$message->setPrivate();

                continue;
            }

            if (! empty($response)) {
                if ($network == 'esimgo') {
                    $inactiveStatuses = [
                        IccidStatusEnum::INACTIVE->value,
                        IccidStatusEnum::EXPIRED->value,
                        IccidStatusEnum::COMPLETED->value,
                    ];

                    $firstInstallTimestamp = $iccidStatus['firstInstalledDateTime'] ?? null;
                    $isUnlimited = filter_var($response['unlimited'] ?? false, FILTER_VALIDATE_BOOLEAN);
                    $messageData = $message->message;

                    if ($firstInstallTimestamp) {
                        $installDate = (new DateTime())->setTimestamp($firstInstallTimestamp / 1000); // Convert milliseconds to seconds
                        $firstInstallDate = $installDate->format('Y-m-d H:i:s');

                        $esimDays = $messageData['items'][0]['variant']['attributes']['esim_days'] ?? 0;
                        $expiredDate = (! $isUnlimited && $esimDays > 0)
                            ? $installDate->modify('+' . $esimDays . ' days')->format('Y-m-d H:i:s')
                            : null;
                    } else {
                        $firstInstallDate = null;
                        $expiredDate = null;
                    }

                    $response['bundleState'] = IccidStatusEnum::getStatus($iccidStatus['profileStatus']);
                    $response['installed_date'] = $firstInstallDate;
                    $response['expiration_date'] = $expiredDate ?? ($messageData['esim_status']['expiration_date'] ?? null);
                    $response['phone_number'] = $messageData['esim_status']['phone_number'] ?? null;
                    $messageData['esim_status'] = $response;
                } elseif ($network == 'easyactivations') {
                    $response['bundleState'] = $response['esim_status'];
                    $response['installed_date'] = $message->created_at;
                    $response['expiration_date'] = $response['expire_date'] ?? null;
                    $response['phone_number'] = $messageData['phone_number'] ?? null;
                    $messageData['esim_status'] = $response;
                }
                $message->message = $messageData;
                $message->saveOrFail();

                $this->info("Message ID: {$message->id} has been updated with the eSIM status.");
                if (in_array($response['bundleState'], $inactiveStatuses, true)) {
                    $message->setPrivate();
                    $this->info("Message ID: {$message->id} has been set to private.");
                }
            }
        }

        return;
    }
}
