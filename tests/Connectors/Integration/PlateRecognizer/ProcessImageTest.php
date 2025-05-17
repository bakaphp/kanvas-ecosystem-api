<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\PlateRecognizer;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\PlateRecognizer\Actions\PullVehicleAction;
use Kanvas\Connectors\PlateRecognizer\Enums\ConfigurationEnum;
use Kanvas\Connectors\PlateRecognizer\Services\VehicleRecognitionService;
use Kanvas\Inventory\Products\Models\Products;
use Tests\TestCase;

class ProcessImageTest extends TestCase
{
    /* public function testProcessImage()
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::API_KEY->value, getenv('TEST_PLATE_RECOGNIZER_API_KEY'));

        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $vehicleImageRecognitionService = new VehicleRecognitionService(
            app: $app,
            company: $company
        );

        $images = [
        ];

        $vehicle = $vehicleImageRecognitionService->processVehicleImages($images);

        if ($vehicle) {
            $this->assertNotNull($vehicle->plateNumber);
        } else {
            $this->fail('Vehicle recognition failed.');
        }
    }
 */
    /*  public function testCreateVehicleProductFromImage()
     {
         $app = app(Apps::class);
         $app->set(ConfigurationEnum::API_KEY->value, getenv('TEST_PLATE_RECOGNIZER_API_KEY'));

         $user = auth()->user();
         $company = $user->getCurrentCompany();

         $vehicleImageRecognitionService = new VehicleRecognitionService(
             app: $app,
             company: $company
         );

         $images = [
         ];

         $vehicle = $vehicleImageRecognitionService->processVehicleImages($images);

         $product = new PullVehicleAction(
             app: $app,
             company: $company,
             user: $user,
             vehicle: $vehicle,
         )->execute($images);

         $this->assertInstanceOf(Products::class, $product);
     } */
}
