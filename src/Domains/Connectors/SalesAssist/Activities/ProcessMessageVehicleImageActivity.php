<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Support\Str;
use Exception;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Mindee\Actions\PullVehicleFromTagAction;
use Kanvas\Connectors\Mindee\Client as MindeeClient;
use Kanvas\Connectors\Mindee\DataTransferObjects\Tag;
use Kanvas\Connectors\PlateRecognizer\Actions\PullVehicleAction;
use Kanvas\Connectors\PlateRecognizer\DataTransferObject\Vehicle;
use Kanvas\Connectors\PlateRecognizer\Services\VehicleRecognitionService;
use Kanvas\Connectors\WaSender\Services\MessageService;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class ProcessMessageVehicleImageActivity extends KanvasActivity
{
    public $tries = 1;

    public function execute(Message $message, Apps $app, array $params): array
    {
        $this->overWriteAppPermissionService($app);

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($message, $app, $integrationCompany, $additionalParams) use ($params) {
                sleep(10);

                $message->refresh();
                $parentMessage = $message->parent ?? $message;

                // Begin a transaction and acquire a lock for this message
                DB::beginTransaction();

                try {
                    // Lock the message record to prevent concurrent processing
                    $lockedMessage = Message::where('id', $parentMessage->id)
                        ->lockForUpdate()
                        ->first();

                    // If another process has already created a product for this message
                    if ($lockedMessage->get('created_product')) {
                        DB::commit();

                        return [
                            'product' => null,
                            'vehicle' => null,
                            'message' => 'Vehicle already created',
                        ];
                    }

                    $newImagesList = $this->collectImagesFromMessages($parentMessage);

                    // Try Mindee client first
                    $mindeeResult = $this->processMindee($message, $app, $params, $newImagesList);

                    if ($mindeeResult['success']) {
                        $this->notifyMindeeSuccess($parentMessage, $mindeeResult['tag'], $mindeeResult['product']);

                        DB::commit();

                        return [
                            'product' => $mindeeResult['product'],
                            'vehicle' => $mindeeResult['tag'],
                            'service' => 'mindee',
                        ];
                    }

                    // If Mindee fails, try PlateRecognizer
                    $plateResult = $this->processPlateRecognizer($message, $app, $newImagesList);

                    if ($plateResult['success']) {
                        $this->notifyPlateSuccess($parentMessage, $plateResult['vehicle'], $plateResult['product']);

                        DB::commit();

                        return [
                            'product' => $plateResult['product'],
                            'vehicle' => $plateResult['vehicle'],
                            'service' => 'platerecognizer',
                        ];
                    }

                    // If both services fail
                    $this->notifyFailed($parentMessage);

                    DB::commit();

                    return [
                        'product' => null,
                        'vehicle' => null,
                        'service' => null,
                    ];
                } catch (Exception $e) {
                    DB::rollBack();

                    throw $e;
                }
            },
            company: $message->company,
        );
    }

    private function collectImagesFromMessages(Message $parentMessage): array
    {
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

        return $newImagesList;
    }

    private function processMindee(Message $message, Apps $app, array $params, array $imagesList): array
    {
        try {
            $mindeeClient = new MindeeClient(
                app: $message->app,
                company: $message->company
            );

            $vehicleTag = null;
            $rawTag = null;

            foreach ($imagesList as $imageUrl) {
                $rawTag = $mindeeClient->processDocumentFromUrl(
                    documentType: $params['documentType'] ?? 'marbete',
                    fileUrl: $imageUrl,
                    version: '1',
                    accountName: $params['accountName'] ?? null
                );
                $vehicleTag = Tag::from($rawTag);

                if ($rawTag !== null && $vehicleTag->vehicleIdentificationNumber !== null) {
                    break;
                }
            }

            if ($rawTag === null || $vehicleTag->vehicleIdentificationNumber === null) {
                return [
                    'success' => false,
                    'tag' => null,
                    'product' => null,
                ];
            }

            $product = new PullVehicleFromTagAction(
                app: $app,
                company: $message->company,
                user: $message->user,
                vehicleTag: $vehicleTag,
            )->execute($imagesList);

            return [
                'success' => true,
                'tag' => $vehicleTag,
                'product' => $product,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'tag' => null,
                'product' => null,
            ];
        }
    }

    private function processPlateRecognizer(Message $message, Apps $app, array $imagesList): array
    {
        try {
            $vehicleImageRecognitionService = new VehicleRecognitionService(
                app: $message->app,
                company: $message->company
            );

            $vehicle = $vehicleImageRecognitionService->processVehicleImages($imagesList);

            if ($vehicle === null) {
                return [
                    'success' => false,
                    'vehicle' => null,
                    'product' => null,
                ];
            }

            $product = new PullVehicleAction(
                app: $app,
                company: $message->company,
                user: $message->user,
                vehicle: $vehicle,
            )->execute($imagesList);

            return [
                'success' => true,
                'vehicle' => $vehicle,
                'product' => $product,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'vehicle' => null,
                'product' => null,
            ];
        }
    }

    private function notifyFailed(Message $message): void
    {
        if ($message->get('created_product_failed') || $message->get('created_product')) {
            return;
        }
        $messageService = new MessageService(
            $message->app,
            $message->company
        );

        $channelId = Str::replace('@s.whatsapp.net', '', $message->message['chat_jid']);

        if ($channelId === null) {
            return;
        }

        $messageService->sendTextMessage(
            $channelId,
            "❌ Sorry, I couldn't identify the vehicle from the images. Please try again with clearer images or send me the vehicle details directly."
        );

        $message->set('created_product_failed', true);
    }

    private function notifySuccess(Message $message, $entity, Products $product, string $service): void
    {
        $messageService = new MessageService(
            $message->app,
            $message->company
        );

        $channelId = Str::replace('@s.whatsapp.net', '', $message->message['chat_jid']);

        if ($channelId === null) {
            return;
        }

        $isMindee = $service === 'mindee';
        $success = "✅ Vehicle registered successfully!\n\n" .
            'License Plate: ' . ($isMindee ? $entity->licensePlateNumber : $entity->plateNumber) . "\n" .
            'VIN: ' . ($isMindee ? ($entity->vehicleIdentificationNumber ?? 'Unknown') : 'N/A') . "\n" .
            'Make: ' . ($entity->make ?? 'Unknown') . "\n" .
            'Model: ' . ($entity->model ?? 'Unknown') . "\n" .
            'Color: ' . ($entity->color ?? 'Unknown') . "\n" .
            ($isMindee ? '' : 'Type: ' . ($entity->type ?? 'Unknown') . "\n\n") .
            'The vehicle has been added to your inventory.';

        if ($message->get('created_product') || $product->get('whatsapp_notification')) {
            $success = "✅ Vehicle already processed successfully!\n\n" .
                'License Plate: ' . ($isMindee ? $entity->licensePlateNumber : $entity->plateNumber) . "\n" .
                'VIN: ' . ($isMindee ? ($entity->vehicleIdentificationNumber ?? 'Unknown') : 'N/A') . "\n" .
                'Make: ' . ($entity->make ?? 'Unknown') . "\n" .
                'Model: ' . ($entity->model ?? 'Unknown') . "\n" .
                'Color: ' . ($entity->color ?? 'Unknown') . "\n" .
                ($isMindee ? '' : 'Type: ' . ($entity->type ?? 'Unknown') . "\n\n") .
                'The vehicle has been added to your inventory.';
        }

        $messageService->sendTextMessage(
            $channelId,
            $success
        );

        $message->set('created_product', true);
        $product->set('whatsapp_notification', true);
    }

    private function notifyMindeeSuccess(Message $message, Tag $tag, Products $product): void
    {
        $this->notifySuccess($message, $tag, $product, 'mindee');
    }

    private function notifyPlateSuccess(Message $message, Vehicle $vehicle, Products $product): void
    {
        $this->notifySuccess($message, $vehicle, $product, 'platerecognizer');
    }
}
