<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PlateRecognizer\Workflows;

use Baka\Support\Str;
use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\PlateRecognizer\Actions\PullVehicleAction;
use Kanvas\Connectors\PlateRecognizer\DataTransferObject\Vehicle;
use Kanvas\Connectors\PlateRecognizer\Services\VehicleRecognitionService;
use Kanvas\Connectors\WaSender\Services\MessageService;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class ProcessVehicleImageActivity extends KanvasActivity
{
    public $tries = 1;

    public function execute(Message $message, Apps $app, array $params): array
    {
        $this->overWriteAppPermissionService($app);

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::PLATE_RECOGNIZER,
            integrationOperation: function ($message, $app, $integrationCompany, $additionalParams) use ($params) {
                sleep(10);

                $vehicleImageRecognitionService = new VehicleRecognitionService(
                    app: $message->app,
                    company: $message->company
                );

                $message->refresh();
                $parentMessage = $message->parent ?? $message;

                $newImagesList = [];

                $parentFiles = $parentMessage->getFiles();
                if ($parentFiles && $parentFiles->isNotEmpty()) {
                    foreach ($parentFiles as $file) {
                        $newImagesList[] = $file->url;
                    }
                }

                foreach ($parentMessage->children as $childMessage) {
                    $images = $childMessage->getFiles();
                    if ($images->isEmpty()) {
                        continue;
                    }

                    foreach ($images as $image) {
                        $newImagesList[] = $image->url;
                    }
                }

                if ($parentMessage->get('created_product')) {
                    return [
                        'product' => null,
                        'vehicle' => null,
                        'message' => 'Vehicle already created',
                    ];
                }

                $vehicle = $vehicleImageRecognitionService->processVehicleImages($newImagesList);

                if ($vehicle === null) {
                    $this->notifyFailed($parentMessage);

                    return [
                        'product' => null,
                        'vehicle' => null,
                    ];
                    //throw new Exception('Vehicle recognition failed.');
                }

                $product = new PullVehicleAction(
                    app: $app,
                    company: $message->company,
                    user: $message->user,
                    vehicle: $vehicle,
                )->execute($newImagesList);

                $this->notifySuccess($parentMessage, $vehicle, $product);

                return [
                    'product' => $product,
                    'vehicle' => $vehicle,
                ];
            },
            company: $message->company,
        );
    }

    private function notifyFailed(Message $message): void
    {
        if ($message->get('created_product_failed') || $message->get('created_product')) {
            return ;
        }
        $messageService = new MessageService(
            $message->app,
            $message->company
        );

        $channelId = Str::replace('@s.whatsapp.net', '', $message->message['chat_jid']);

        if ($channelId === null) {
            return ;
        }

        $messageService->sendTextMessage(
            $channelId,
            "❌ Sorry, I couldn't identify the vehicle from the images. Please try again with clearer images or send me the vehicle details directly."
        );

        $message->set('created_product_failed', true);
    }

    private function notifySuccess(Message $message, Vehicle $vehicle, Products $product): void
    {
        if ($message->get('created_product') || $product->get('whatsapp_notification')) {
            return ;
        }
        $messageService = new MessageService(
            $message->app,
            $message->company
        );

        $channelId = Str::replace('@s.whatsapp.net', '', $message->message['chat_jid']);

        if ($channelId === null) {
            return ;
        }

        $success = "✅ Vehicle registered successfully!\n\n" .
               'License Plate: ' . $vehicle->plateNumber . "\n" .
               'Make: ' . ($vehicle->make ?? 'Unknown') . "\n" .
               'Model: ' . ($vehicle->model ?? 'Unknown') . "\n" .
               'Color: ' . ($vehicle->color ?? 'Unknown') . "\n" .
               'Type: ' . ($vehicle->type ?? 'Unknown') . "\n\n" .
               'The vehicle has been added to your inventory.';

        $messageService->sendTextMessage(
            $channelId,
            $success
        );

        $message->set('created_product', true);
        $product->set('whatsapp_notification', true);
    }
}
