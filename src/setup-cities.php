<?php

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$path = './canada.ndjson'; // adjust path as needed
$cities = [];

$file = new \SplFileObject($path);

while (!$file->eof()) {
    $line = trim($file->fgets());

    if (empty($line)) continue;

    $decoded = json_decode($line, true);

    if (json_last_error() === JSON_ERROR_NONE) {
        $cities[] = $decoded;
    } else {
        // Optionally log or debug
        echo "Invalid JSON line: $line";
    }
}

if (! isset($cities) || empty($cities)) {
    echo "No cities found in JSON file\n";
    exit;
}

$citiesData = [];

// Process parking locations
foreach ($cities as $city) {
    $cityData = [
        'name'    => $city['name'] ?? $city['display_name'] ?? null,
        'state'   => $city['address']['state'] ?? null,
        'latitude' => $city['location'][1] ?? null,
        'longitude' => $city['location'][0] ?? null,
    ];
    echo 'Processed: ' . $city['name'] . PHP_EOL;
    $citiesData[] = $cityData;
}

// Custom function to format arrays with short syntax
function formatArrayShortSyntax($array) {
    $export = var_export($array, true);
    $export = preg_replace('/^([ ]*)(.*)/m', '$1$1$2', $export);
    $array = preg_split("/\r\n|\n|\r/", $export);
    $array = preg_replace(["/\s*array\s\($/", "/\)(,)?$/", "/\s=>\s$/"], [NULL, ']$1', ' => ['], $array);
    $export = join(PHP_EOL, array_filter(["["] + $array));
    return $export;
}

// Generate the seeder file
$seederContent = "<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Kanvas\Locations\Models\Cities;
use Kanvas\Locations\Models\Countries;
use Kanvas\Locations\Models\States;

class CanadaCitiesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            // Get Canada country
            \$canada = Countries::where('code', 'CA')->firstOrFail();

            // Process cities data
            foreach (\$this->getCitiesData() as \$cityData) {
                // Find or create state
                \$state = States::firstOrCreate(
                    [
                        'name' => \$cityData['state'],
                        'countries_id' => \$canada->id,
                    ],
                    [
                        'code' => \$this->getProvinceCode(\$cityData['state']),
                    ]
                );

                // Create city
                Cities::firstOrCreate(
                    [
                        'name' => \$cityData['name'],
                        'states_id' => \$state->id,
                        'countries_id' => \$canada->id,
                    ],
                    [
                        'latitude' => \$cityData['latitude'],
                        'longitude' => \$cityData['longitude'],
                    ]
                );
            }
        } catch (\Exception \$e) {
            \$this->command->error('Error seeding Canadian cities: ' . \$e->getMessage());
        }

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Get the province code for a given province name.
     */
    private function getProvinceCode(string \$provinceName): string
    {
        \$provinces = [
            'Alberta' => 'AB',
            'British Columbia' => 'BC',
            'Manitoba' => 'MB',
            'New Brunswick' => 'NB',
            'Newfoundland and Labrador' => 'NL',
            'Nova Scotia' => 'NS',
            'Ontario' => 'ON',
            'Prince Edward Island' => 'PE',
            'Quebec' => 'QC',
            'Saskatchewan' => 'SK',
            'Northwest Territories' => 'NT',
            'Nunavut' => 'NU',
            'Yukon' => 'YT',
        ];

        return \$provinces[\$provinceName] ?? '';
    }

    /**
     * Get the cities data array.
     */
    private function getCitiesData(): array
    {
        return " . formatArrayShortSyntax($citiesData) . ";
    }
}";

file_put_contents('database/seeders/CanadaCitiesSeeder.php', $seederContent);
echo "Seeder file generated successfully!\n";
