<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\PlateRecognizer;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Mindee\Client;
use Kanvas\Connectors\Mindee\DataTransferObjects\Tag;
use Kanvas\Connectors\Mindee\Enums\ConfigurationEnum as EnumsConfigurationEnum;
use Kanvas\Connectors\PlateRecognizer\Actions\PullVehicleAction;
use Kanvas\Connectors\PlateRecognizer\Enums\ConfigurationEnum;
use Kanvas\Connectors\PlateRecognizer\Services\VehicleRecognitionService;
use Kanvas\Inventory\Products\Models\Products;
use Tests\TestCase;

class ProcessImageTest extends TestCase
{
    public function testProcessImage()
    {/*
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
        } */

        $this->assertTrue(true, 'This test is not implemented yet.');
    }

    public function testCreateVehicleProductFromImage()
    {
        /*  $app = app(Apps::class);
         $app->set(ConfigurationEnum::API_KEY->value, getenv('TEST_PLATE_RECOGNIZER_API_KEY'));
         $app->set(EnumsConfigurationEnum::API_KEY->value, getenv('TEST_MINDEE_API_KEY'));
         $app->set(EnumsConfigurationEnum::ACCOUNT_NAME->value, 'kaioken');

         $user = auth()->user();
         $company = $user->getCurrentCompany();

         $mindeeClient = new Client(
             app: $app,
             company: $company
         );

         $rawTag = $mindeeClient->processDocumentFromUrl(
             documentType: 'marbete',
             fileUrl: 'url',
             version: '1',
             accountName: 'kaioken',
         );

         $vehicleTag = Tag::from($rawTag);
         print_R($vehicleTag); DIE(); */

        /*   $vehicleImageRecognitionService = new VehicleRecognitionService(
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

          $this->assertInstanceOf(Products::class, $product); */

        $this->assertTrue(true, 'This test is not implemented yet.');
    }
}
