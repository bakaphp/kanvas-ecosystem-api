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
            integration: IntegrationsEnum::CREDIT700,
            integrationOperation: function ($message, $app, $integrationCompany, $additionalParams) use ($params) {
                sleep(10);

                $vehicleImageRecognitionService = new VehicleRecognitionService(
                    app: $message->app,
                    company: $message->company
                );

                $parentMessage = $message->parent ?? $message;

                foreach ($parentMessage->children as $childMessage) {
                    $images = $childMessage->getFiles();
                    if (! empty($images)) {
                        $images = array_map(function ($image) {
                            return $image->url;
                        }, $images);
                    }
                }

                $vehicle = $vehicleImageRecognitionService->processVehicleImages($images);

                if ($vehicle === null) {
                    $this->notifyFailed($message);

                    throw new Exception('Vehicle recognition failed.');
                }

                $product = new PullVehicleAction(
                    app: $app,
                    company: $message->company,
                    user: $message->user,
                    vehicle: $vehicle,
                )->execute($images);

                $this->notifySuccess($message, $vehicle);

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
    }

    private function notifySuccess(Message $message, Vehicle $vehicle): void
    {
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
    }
}
