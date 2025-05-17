<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PlateRecognizer\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\PlateRecognizer\Client;
use Kanvas\Connectors\PlateRecognizer\DataTransferObject\Vehicle;

class VehicleRecognitionService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected ?CompanyInterface $company = null
    ) {
        $this->client = new Client($app, $company);
    }

    /**
     * Process multiple vehicle images to determine if they're the same vehicle
     * and extract license plate and registration info
     *
     * @param array $imagePaths Array of file paths to the vehicle images
     * @return Vehicle|null Vehicle data with license plate and other info
     */
    public function processVehicleImages(array $imagePaths): ?Vehicle
    {
        $results = [];
        $mostConfidentResult = null;
        $highestConfidence = 0;

        // Process each image with Plate Recognizer
        foreach ($imagePaths as $path) {
            $plateData = $this->client->recognizePlate($path);

            // Skip if no data detected in this image
            if (! $plateData || empty($plateData['results'])) {
                continue;
            }

            foreach ($plateData['results'] as $result) {
                // Extract plate data if available
                $plateNumber = null;
                $plateConfidence = 0;
                $plateRegion = null;

                if (isset($result['plate']) && isset($result['plate']['props']['plate'][0])) {
                    $plateNumber = $result['plate']['props']['plate'][0]['value'];
                    $plateConfidence = $result['plate']['props']['plate'][0]['score'];

                    if (isset($result['plate']['props']['region'][0])) {
                        $plateRegion = $result['plate']['props']['region'][0]['value'];
                    }
                }

                // Skip if no plate detected and we're tracking by plate number
                if (! $plateNumber) {
                    continue;
                }

                // Extract vehicle data
                $vehicleType = null;
                $vehicleScore = 0;
                $vehicleMake = null;
                $vehicleModel = null;
                $vehicleColor = null;
                $vehicleOrientation = null;

                if (isset($result['vehicle'])) {
                    $vehicleType = $result['vehicle']['type'] ?? null;
                    $vehicleScore = $result['vehicle']['score'] ?? 0;

                    if (isset($result['vehicle']['props'])) {
                        $props = $result['vehicle']['props'];

                        if (isset($props['make_model'][0])) {
                            $vehicleMake = $props['make_model'][0]['make'] ?? null;
                            $vehicleModel = $props['make_model'][0]['model'] ?? null;
                        }

                        if (isset($props['color'][0])) {
                            $vehicleColor = $props['color'][0]['value'] ?? null;
                        }

                        if (isset($props['orientation'][0])) {
                            $vehicleOrientation = $props['orientation'][0]['value'] ?? null;
                        }
                    }
                }

                $vehicleData = [
                    'plate_number' => $plateNumber,
                    'confidence' => $plateConfidence,
                    'region' => $plateRegion ?? '',
                    'make' => $vehicleMake ?? '',
                    'model' => $vehicleModel ?? '',
                    'color' => $vehicleColor ?? '',
                    'orientation' => $vehicleOrientation ?? '',
                    'type' => $vehicleType ?? '',
                    'vehicle_score' => $vehicleScore,
                    'vehicle_box' => $result['vehicle']['box'] ?? null,
                    'plate_box' => $result['plate']['box'] ?? null,
                    'direction' => $result['direction'] ?? null,
                    'direction_score' => $result['direction_score'] ?? 0,
                    'raw_data' => $result,
                ];

                $results[$plateNumber][] = $vehicleData;

                // Track the highest confidence result
                if ($plateConfidence > $highestConfidence) {
                    $highestConfidence = $plateConfidence;
                    $mostConfidentResult = $vehicleData;
                }
            }
        }

        if (empty($results)) {
            return null;
        }

        // Determine the most likely license plate (most frequent in results)
        $plateCounts = [];
        foreach ($results as $plateNumber => $plateInstances) {
            $plateCounts[$plateNumber] = count($plateInstances);
        }

        arsort($plateCounts);
        $mostLikelyPlate = key($plateCounts);

        // If we have multiple detections of the same plate, use the one with highest confidence
        $finalVehicleData = null;
        if (isset($results[$mostLikelyPlate]) && count($results[$mostLikelyPlate]) > 0) {
            usort($results[$mostLikelyPlate], function ($a, $b) {
                return $b['confidence'] <=> $a['confidence'];
            });

            $finalVehicleData = $results[$mostLikelyPlate][0];
        } else {
            // Fallback to the most confident result overall
            $finalVehicleData = $mostConfidentResult;
        }

        // Return null if we couldn't find any valid data
        if (! $finalVehicleData) {
            return null;
        }

        // Create and return the Vehicle DTO object
        return new Vehicle(
            plateNumber: $finalVehicleData['plate_number'],
            confidence: $finalVehicleData['confidence'],
            region: $finalVehicleData['region'],
            make: $finalVehicleData['make'],
            model: $finalVehicleData['model'],
            color: $finalVehicleData['color'],
            orientation: $finalVehicleData['orientation'],
            type: $finalVehicleData['type'],
            vehicleScore: $finalVehicleData['vehicle_score'],
            vehicleBox: $finalVehicleData['vehicle_box'],
            plateBox: $finalVehicleData['plate_box'],
            direction: $finalVehicleData['direction'],
            directionScore: $finalVehicleData['direction_score'],
            rawData: $finalVehicleData['raw_data']
        );
    }
}
