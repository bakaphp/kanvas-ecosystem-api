<?php

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use League\Csv\Reader;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

const MODE_LOCAL = 'local';
const MODE_DEV = 'dev';
const MODE_PROD = 'prod';

const URLS = [
    MODE_LOCAL => 'http://localhost/graphql',
    MODE_DEV => 'https://graphapidev.kanvas.dev/graphql',
    MODE_PROD => 'https://graphapi.kanvas.dev/graphql',
];

class Movipass
{
    protected string $url;

    public function __construct(
        protected string $appUuid,
        protected string $email,
        protected string $password,
        protected int $regionId,
        protected string $branchUid,
        protected int $warehouseId,
        protected int $channelId,
        protected int $companyId,
        protected string $mode = MODE_LOCAL,
        protected bool $compactedProducts = false,
        protected string $currency = 'DOP',
        protected ?string $adminKey = null,
    ) {
        $this->url = URLS[$this->mode];
    }

    private function getToken(string $email, string $password): string
    {
        
        $client = new Client([ 'verify' => false ]);
        $headers = [
            'X-Kanvas-Key' => $this->appUuid,
            // 'X-Kanvas-Location' => $this->branchUid,
            // ...($this->adminKey ? ['X-Kanvas-Admin-Key' => $this->adminKey] : []),
        ];

        $login = <<<GQL
mutation login(\$data: LoginInput!) {
  login(data: \$data) {
    id
    token
    refresh_token
    token_expires
    refresh_token_expires
    time
    timezone
  }
}
GQL;

        $getToken = $client->post(
            $this->url,
            [
                'headers' => $headers,
                'json' => [
                    'query' => $login,
                    'variables' => [
                        'data' => [
                            'email' => $email,
                            'password' => $password,
                        ],
                    ],
                ],
            ]
        );

        $loginResponse = json_decode($getToken->getBody()->getContents(), true);
        $token = 'Bearer ' . $loginResponse['data']['login']['token'];
        return $token;
    }

    private function getStorageLocations(string $csvFilePath, int $headerOffset = 0, bool $compactedProducts = true): array
    {
        $csv = Reader::createFromPath($csvFilePath);
        $csv->setHeaderOffset($headerOffset);
        $csv->skipEmptyRecords();
        $records = $csv->getRecords();

        $storageLocations = [];
        foreach ($records as $offset => $record) {
            if ($offset < $headerOffset) {
                continue;
            }

            $name = $record["nombre"];

            $recordData = [
                "name" => $record["nombre"],
                "address" => $record["nombre"],
                "latitude" => $record["Latitud"],
                "longitude" => $record["Longitud"],
                "type" => $record["tipo"],
                "block" => $record["Bloque"],
                "hours" => $record["Horario"],
                "price" => $record["Precio"],
                "price_peak" => $record["Precio hora pico"],
                "min_time" => $record["tiempo minimo"],
            ];

            if (isset($storageLocations[$name])) {
                $storageLocations[$name]["total_spaces"]++;
                $storageLocations[$name]["slots"][] = $recordData;
            } else {
                $storageLocations[$name] = [
                    "name" => $name,
                    "total_spaces" => $compactedProducts ? (int) $record["parqueos"] : 1,
                    "slots" => [$recordData]
                ];
            }
        }

        return $storageLocations;
    }

    public function execute(): void
    {
        $branchUid = $this->branchUid; // Branch UUID
        $email = $this->email; // Login email
        $password = $this->password; // Password
        $regionId = (int) ($this->regionId); // Region ID
        #$warehouseId = 466;
        $warehouseId = $this->warehouseId;
        #$channelId = 594;
        $channelId = $this->channelId;
        #$companyId = 9618;
        $companyId = $this->companyId;

        // Read the JSON file with storage locations
        $csvFile = './parking_lots.csv';
        $storageData = $this->getStorageLocations($csvFile, 0, $this->compactedProducts);

        if (empty($storageData)) {
            echo "No storage locations found in CSV file\n";
            exit;
        }

        $impoundSlots = [];

        // Process storage locations
        foreach ($storageData as $location) {
            $locationId = $location['id'] ?? $location['name'];
            $name = $location['name'];
            $address = $location['slots'][0]['address'];
            $phone = $location['slots'][0]['phone'] ?? 'Not available';
            $price = $location['slots'][0]['price'] ?? 0;
            $currency = $location['slots'][0]['currency'] ?? $this->currency;
            $isPrivate = $location['slots'][0]['type'] == 'Privado' ?? false;
            $totalSpaces = $location['total_spaces'] ?? 0;
            $openHours = $location['horario_apertura'] ?? '00:00';
            $closeHours = $location['horario_cierre'] ?? '00:00';
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));

            $variants = [];
            foreach ($location['slots'] as $index => $slot) {
                $variants[] = [
                    'name' => $name ?? $slug,
                    'description' => "Storage located at $address",
                    'sku' => 'SLOT-' . $slug . '-V' . ($index + 1),
                    'price' => (float) $slot['price'],
                    // 'discountPrice' => (float) $slot['price'],
                    'is_published' => true,
                    'slug' => $slug . '-standard',
                    'files' => [],
                    'warehouses' => [[
                        'id' => $warehouseId,
                        'price' => (float) $slot['price'],
                        'quantity' => $this->compactedProducts ?  $totalSpaces : 1,
                        'sku' => 'SLOT-' . $locationId . '-V' . ($index + 1),
                        'is_new' => true,
                    ]],
                    'channels' => [[
                        'warehouses_id' => $warehouseId,
                        'channels_id' => $channelId,
                        'price' => (float) $slot['price'],
                        'discounted_price' => (float) $slot['price'],
                        'is_published' => true,
                    ]],
                    'attributes' => [
                        [
                            'name' => 'coordinates',
                            'value' => [
                                'lat' => $slot['latitude'],
                                'long' => $slot['longitude'],
                            ],
                        ],
                        [
                            'name' => 'slot_hours',
                            'value' => [
                                'open' => $openHours,
                                'close' => $closeHours,
                            ],
                        ],
                        [
                            'name' => 'type',
                            'value' => $isPrivate ? 'Private' : 'Public',
                        ],
                        [
                            'name' => 'currency',
                            'value' => $currency,
                        ],
                    ],
                ];
            }

            // Prepare coordinates
            $latitude = $location['slots'][0]['latitude'] ?? 0;
            $longitude = $location['slots'][0]['longitude'] ?? 0;

            // Generate a slug from name
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));

            // Determine category based on private/public status
            $categoryName = 'impound lot';
            $categoryCode = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', $categoryName));

            // Create product attributes
            $attributes = [
                [
                    'name' => 'coordinates',
                    'value' => [
                        'lat' => $latitude,
                        'long' => $longitude,
                    ],
                ],
                [
                    'name' => 'slot_hours',
                    'value' => [
                        'open' => $openHours,
                        'close' => $closeHours,
                    ],
                ],
                [
                    'name' => 'capacity',
                    'value' => [
                        'occupiedSpaces' => 0,
                        'availableSpaces' => $totalSpaces,
                        'totalSpaces' => $totalSpaces,
                    ],
                ],
                [
                    'name' => 'slots',
                    'value' => $totalSpaces,
                ],
                [
                    'name' => 'type',
                    'value' => $isPrivate ? 'Private' : 'Public',
                ],
                [
                    'name' => 'currency',
                    'value' => $currency,
                ],
            ];

            // Create product data structure
            $productData = [
                'name' => $name,
                'description' => "Impound lot located at $address",
                'slug' => $slug,
                'sku' => 'PARK-' . $locationId,
                'regionId' => $regionId,
                'isPublished' => true,
                'files' => [], // No images in this case
                'productType' => [
                    'name' => 'Impound lot',
                    'description' => 'Impound lot',
                    'is_published' => true,
                    'weight' => 1,
                ],
                'customFields' => [],
                'variants' => $variants,
                'categories' => [
                    [
                        'name' => $categoryName,
                        'code' => $categoryCode,
                        'is_published' => true,
                        'position' => 1,
                    ],
                ],
                'attributes' => $attributes,
            ];

            echo 'Processed: ' . $name . PHP_EOL;
            $impoundSlots[] = $productData;
        }

        // $token = $this->getToken($email, $password);

        // dd($impoundSlots);


        $client = new Client(['verify' => false]);
        $token = "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzUxMiJ9.eyJpc3MiOiJodHRwczovL2dyYXBoYXBpZGV2LmthbnZhcy5kZXYiLCJhdWQiOiJodHRwczovL2dyYXBoYXBpZGV2LmthbnZhcy5kZXYiLCJqdGkiOiIyOGQxNmMzZi04ZGE2LTRkODAtOTU5Ny1mYjg3MzRjODA2OGYiLCJpYXQiOjE3NTAxMDMwMDguNDEzODIzLCJuYmYiOjE3NTAxMDMwMDguNDEzODIzLCJleHAiOjE3NTI3MzEwMDguNDEzODIzLCJzZXNzaW9uSWQiOiIyOGQxNmMzZi04ZGE2LTRkODAtOTU5Ny1mYjg3MzRjODA2OGYiLCJlbWFpbCI6Implc3VzQGthbnZhcy5kZXYiLCJkZXZpY2VJZCI6bnVsbH0.FXEvDtcaaJgEB8fG6-wxP4Zv_NMd9S80r-QSL6-4Wy-E4aA1e60xPxGAOmnzhDfuNsHD3UlA-wvXyFQjlzb5VA";
        $headers = [
            ...($token ? ['Authorization' => "Bearer $token"] : []),
            'X-Kanvas-App' => $this->appUuid,
            'X-Kanvas-Location' => $this->branchUid,
            ...($this->adminKey ? ['X-Kanvas-Admin-Key' => $this->adminKey] : []),
            // lets add authorization basic auth bello
            // 'Authorization' => 'Basic ' . base64_encode($this->appUuid . ':' . $this->adminKey),
            // 'Authorization' => 'Basic ' . base64_encode($email . ':' . $password),
        ];

        // Unpublish existing products
        $unPublishAllProducts = <<<GQL
mutation(\$id: ID!) {
    unPublishAllVariantsFromChannel(id: \$id)
}
GQL;

        // $unPublishResponse = $client->post(
        //     $this->url,
        //     [
        //         'headers' => $headers,
        //         'json' => [
        //             'query' => $unPublishAllProducts,
        //             'variables' => [
        //                 'id' => $this->channelId,
        //             ],
        //         ],
        //     ]
        // );

        // print_r($unPublishResponse->getBody()->getContents());

        // Import products mutation
        $mutation = <<<GQL
mutation(\$input: [ImporterProductInput!]!, \$regionId: Int!, \$companyId: Int!) {
    importProduct(input:\$input, companyId: \$companyId, regionId:\$regionId)
}
GQL;

        // Break products into chunks of 10 for processing
        $chunks = array_chunk($impoundSlots, 10);
        dd($chunks);

        foreach ($chunks as $chunk) {
            $response = $client->post(
                $this->url,
                [
                    'headers' => $headers,
                    'json' => [
                        'query' => $mutation,
                        'variables' => [
                            'regionId' => (int) $regionId,
                            'companyId' => (int) $companyId,
                            'input' => $chunk,
                        ],
                    ],
                ]
            );

            echo PHP_EOL . 'Branch ' . $branchUid . ' for region ' . $regionId .
                ' processed a total of ' . count($chunk) . ' impound lots - ' . $response->getBody()->getContents();
        }

        echo PHP_EOL . 'Finished importing ' . count($impoundSlots) . ' impound lots.' . PHP_EOL;
    }
}

// Lodal
// $movipass = new Movipass(
//     appUuid: 'd80b9781-49ba-4bd8-81d2-6026dbdd0ce1',
//     branchUid: $argv[1] ?? null,
//     email: $argv[2] ?? null,
//     password: $argv[3] ?? null,
//     regionId: (int) ($argv[4] ?? null),
//     warehouseId: 3737,
//     channelId: 3665,
//     companyId: 1272,
//     // If we want to add every row as a single variant and add quantity as the total spaces
//     compactedProducts: true,
//      // If we want that every row represents a slot that belongs to a product: variants as the total spaces
//     //  compactedProducts: false,
//     mode: MODE_LOCAL
// );

// Development
// $movipass = new Movipass(
//     appUuid: '9f8a5dfe-be5d-4958-b654-28150f86172a',
//     branchUid: $argv[1] ?? null,
//     email: $argv[2] ?? null,
//     password: $argv[3] ?? null,
//     regionId: (int) ($argv[4] ?? null),
//     warehouseId: 2376,
//     channelId: 2356,
//     companyId: 3520,
//     // If we want to add every row as a single variant and add quantity as the total spaces
//     compactedProducts: true,
//      // If we want that every row represents a slot that belongs to a product: variants as the total spaces
//     //  compactedProducts: false,
//     mode: MODE_DEV,
//     // adminKey: "JNudAtpEVDg7KyI3rzZhSlPD4Zii14kuCO6NmWIOXtMqf2ju9nvts2qKBKWMi4udjZ6Wmnz2znhNuNnqEcrg3UhV6hoTm3JXEFQbHxubIaIRzkerLHKAMwmD1xN27JH3"
// );
// $ php src/movipassd.php 9592d45b-9d5f-4381-aaa9-683837cf60ca jesus@kanvas.dev noseN0$3 2316

// Production
$movipass = new Movipass(
    appUuid: 'ea569c90-8caf-4ee7-a4eb-12e3c1374eb8',
    branchUid: $argv[1] ?? null,
    email: $argv[2] ?? null,
    password:  $argv[3] ?? null,
    regionId: (int) ($argv[4] ?? null),
    warehouseId: 466,
    channelId: 594,
    companyId: 9618,
    mode: MODE_PROD,
    adminKey: "fzUiK7wbz2Kv8wztEOBboQDbU4w4Y5xGq8ADIekU6yqOmbAa8yLYTRfNvG9fxSQFFcwdFBhvvWlVYXm5S3CUw3GKxZ3YOzeZUzY2vwo8ItWCPSGmDzVk4lOqVVz3VOTI"
);


$movipass->execute();
