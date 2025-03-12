<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ESim;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Locations\Models\Countries;

class GenerateEsimGenerateRegionalCountryInfoCommand extends Command
{
    use KanvasJobsTrait;

    protected array $countriesByRegion = [
        'Europa' => [
            ['code' => 'es', 'name' => 'España'],
            ['code' => 'fr', 'name' => 'Francia'],
            ['code' => 'de', 'name' => 'Alemania'],
            ['code' => 'it', 'name' => 'Italia'],
            ['code' => 'gb', 'name' => 'Reino Unido'],
            ['code' => 'pt', 'name' => 'Portugal'],
            ['code' => 'gr', 'name' => 'Grecia'],
            ['code' => 'nl', 'name' => 'Países Bajos'],
            ['code' => 'be', 'name' => 'Bélgica'],
            ['code' => 'ch', 'name' => 'Suiza'],
            ['code' => 'at', 'name' => 'Austria'],
            ['code' => 'se', 'name' => 'Suecia'],
            ['code' => 'no', 'name' => 'Noruega'],
            ['code' => 'dk', 'name' => 'Dinamarca'],
            ['code' => 'fi', 'name' => 'Finlandia'],
            ['code' => 'ie', 'name' => 'Irlanda'],
            ['code' => 'pl', 'name' => 'Polonia'],
            ['code' => 'cz', 'name' => 'República Checa'],
            ['code' => 'hu', 'name' => 'Hungría'],
            ['code' => 'ro', 'name' => 'Rumania'],
            ['code' => 'bg', 'name' => 'Bulgaria'],
            ['code' => 'hr', 'name' => 'Croacia'],
            ['code' => 'rs', 'name' => 'Serbia'],
            ['code' => 'si', 'name' => 'Eslovenia'],
            ['code' => 'sk', 'name' => 'Eslovaquia'],
            ['code' => 'ua', 'name' => 'Ucrania'],
            ['code' => 'ee', 'name' => 'Estonia'],
            ['code' => 'lv', 'name' => 'Letonia'],
            ['code' => 'lt', 'name' => 'Lituania'],
            ['code' => 'cy', 'name' => 'Chipre'],
            ['code' => 'mt', 'name' => 'Malta'],
            ['code' => 'lu', 'name' => 'Luxemburgo'],
            ['code' => 'is', 'name' => 'Islandia'],
        ],
        'América Latina' => [
            ['code' => 'ar', 'name' => 'Argentina'],
            ['code' => 'br', 'name' => 'Brasil'],
            ['code' => 'cl', 'name' => 'Chile'],
            ['code' => 'co', 'name' => 'Colombia'],
            ['code' => 'pe', 'name' => 'Perú'],
            ['code' => 've', 'name' => 'Venezuela'],
            ['code' => 'ec', 'name' => 'Ecuador'],
            ['code' => 'bo', 'name' => 'Bolivia'],
            ['code' => 'py', 'name' => 'Paraguay'],
            ['code' => 'uy', 'name' => 'Uruguay'],
            ['code' => 'gt', 'name' => 'Guatemala'],
            ['code' => 'hn', 'name' => 'Honduras'],
            ['code' => 'sv', 'name' => 'El Salvador'],
            ['code' => 'ni', 'name' => 'Nicaragua'],
            ['code' => 'cr', 'name' => 'Costa Rica'],
            ['code' => 'pa', 'name' => 'Panamá'],
        ],
        'Islas del Caribe' => [
            ['code' => 'cu', 'name' => 'Cuba'],
            ['code' => 'do', 'name' => 'República Dominicana'],
            ['code' => 'ht', 'name' => 'Haití'],
            ['code' => 'pr', 'name' => 'Puerto Rico'],
            ['code' => 'jm', 'name' => 'Jamaica'],
            ['code' => 'tt', 'name' => 'Trinidad y Tobago'],
            ['code' => 'bs', 'name' => 'Bahamas'],
            ['code' => 'bb', 'name' => 'Barbados'],
            ['code' => 'dm', 'name' => 'Dominica'],
            ['code' => 'gd', 'name' => 'Granada'],
            ['code' => 'lc', 'name' => 'Santa Lucía'],
            ['code' => 'vc', 'name' => 'San Vicente y las Granadinas'],
            ['code' => 'ag', 'name' => 'Antigua y Barbuda'],
            ['code' => 'kn', 'name' => 'San Cristóbal y Nieves'],
        ],
        'Asia' => [
            ['code' => 'cn', 'name' => 'China'],
            ['code' => 'jp', 'name' => 'Japón'],
            ['code' => 'kr', 'name' => 'Corea del Sur'],
            ['code' => 'in', 'name' => 'India'],
            ['code' => 'id', 'name' => 'Indonesia'],
            ['code' => 'ph', 'name' => 'Filipinas'],
            ['code' => 'vn', 'name' => 'Vietnam'],
            ['code' => 'th', 'name' => 'Tailandia'],
            ['code' => 'my', 'name' => 'Malasia'],
            ['code' => 'sg', 'name' => 'Singapur'],
            ['code' => 'pk', 'name' => 'Pakistán'],
            ['code' => 'bd', 'name' => 'Bangladesh'],
            ['code' => 'np', 'name' => 'Nepal'],
            ['code' => 'lk', 'name' => 'Sri Lanka'],
            ['code' => 'mm', 'name' => 'Myanmar'],
            ['code' => 'kh', 'name' => 'Camboya'],
            ['code' => 'la', 'name' => 'Laos'],
            ['code' => 'mn', 'name' => 'Mongolia'],
            ['code' => 'bt', 'name' => 'Bután'],
            ['code' => 'bn', 'name' => 'Brunéi'],
            ['code' => 'tl', 'name' => 'Timor Oriental'],
            ['code' => 'hk', 'name' => 'Hong Kong'],
            ['code' => 'tw', 'name' => 'Taiwán'],
            ['code' => 'il', 'name' => 'Israel'],
            ['code' => 'sa', 'name' => 'Arabia Saudita'],
            ['code' => 'ae', 'name' => 'Emiratos Árabes Unidos'],
            ['code' => 'qa', 'name' => 'Catar'],
            ['code' => 'kw', 'name' => 'Kuwait'],
            ['code' => 'bh', 'name' => 'Baréin'],
            ['code' => 'om', 'name' => 'Omán'],
            ['code' => 'jo', 'name' => 'Jordania'],
            ['code' => 'lb', 'name' => 'Líbano'],
            ['code' => 'sy', 'name' => 'Siria'],
            ['code' => 'iq', 'name' => 'Irak'],
            ['code' => 'ir', 'name' => 'Irán'],
            ['code' => 'tr', 'name' => 'Turquía'],
            ['code' => 'ye', 'name' => 'Yemen'],
        ],
        'África' => [
            ['code' => 'eg', 'name' => 'Egipto'],
            ['code' => 'za', 'name' => 'Sudáfrica'],
            ['code' => 'ng', 'name' => 'Nigeria'],
            ['code' => 'ma', 'name' => 'Marruecos'],
            ['code' => 'dz', 'name' => 'Argelia'],
            ['code' => 'tn', 'name' => 'Túnez'],
            ['code' => 'ke', 'name' => 'Kenia'],
            ['code' => 'et', 'name' => 'Etiopía'],
            ['code' => 'gh', 'name' => 'Ghana'],
            ['code' => 'tz', 'name' => 'Tanzania'],
            ['code' => 'ci', 'name' => 'Costa de Marfil'],
            ['code' => 'sn', 'name' => 'Senegal'],
            ['code' => 'cm', 'name' => 'Camerún'],
            ['code' => 'ug', 'name' => 'Uganda'],
            ['code' => 'zm', 'name' => 'Zambia'],
            ['code' => 'zw', 'name' => 'Zimbabue'],
            ['code' => 'mg', 'name' => 'Madagascar'],
            ['code' => 'rw', 'name' => 'Ruanda'],
            ['code' => 'ml', 'name' => 'Mali'],
            ['code' => 'bf', 'name' => 'Burkina Faso'],
            ['code' => 'ne', 'name' => 'Níger'],
            ['code' => 'td', 'name' => 'Chad'],
            ['code' => 'sd', 'name' => 'Sudán'],
            ['code' => 'so', 'name' => 'Somalia'],
            ['code' => 'ly', 'name' => 'Libia'],
            ['code' => 'mu', 'name' => 'Mauricio'],
            ['code' => 'sz', 'name' => 'Esuatini'],
            ['code' => 'ls', 'name' => 'Lesoto'],
            ['code' => 'na', 'name' => 'Namibia'],
            ['code' => 'bw', 'name' => 'Botsuana'],
        ],
        'Norteamérica' => [
            ['code' => 'us', 'name' => 'Estados Unidos'],
            ['code' => 'ca', 'name' => 'Canadá'],
            ['code' => 'mx', 'name' => 'México'],
        ],
    ];

    protected $signature = 'kanvas:esim-generate-regional-country-info {app_id} {company_id} {product_type?}';
    protected $description = 'Generate eSIM recommendation';

    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        $company = Companies::getById((int) $this->argument('company_id'));

        // Get product type from argument or default to 'regional'
        $productTypeName = $this->argument('product_type') ?? 'regional';

        $localProductType = ProductsTypes::fromApp($app)
            ->fromCompany($company)
            ->where('slug', 'local')
            ->firstOrFail();

        $productType = ProductsTypes::fromApp($app)
        ->fromCompany($company)
        ->where(function ($query) use ($productTypeName) {
            // Try to match JSON data
            $query->where(function ($q) use ($productTypeName) {
                $q->whereRaw('JSON_VALID(name) = 1')
                  ->whereRaw('JSON_EXTRACT(name, "$.en") = ?', [json_encode($productTypeName)]);
            })
            // Or try to match non-JSON data directly
            ->orWhere('name', $productTypeName);
        })
        ->firstOrFail();

        $products = Products::fromApp($app)
            ->with('attributes')
            ->where('products_types_id', $productType->getId())
            ->get();

        $countryInfo = [];

        foreach ($products as $product) {
            $this->info('Processing ' . $productTypeName . ' product: ' . $product->name);

            foreach ($this->countriesByRegion[$product->name] as $countryData) {
                $this->info('Processing country: ' . $countryData['name']);

                $country = Countries::where('code', $countryData['code'])->first();

                if ($country === null) {
                    $this->error('Country not found: ' . $countryData['name']);

                    continue;
                }

                $countryCode = $country->code;
                //$countryName = $country->name;
                $country = $countryData['name'];

                $productWithAttributes = Products::fromApp($app)
                ->fromCompany($company)
                ->where(function ($query) use ($country) {
                    // Try to match JSON data
                    $query->where(function ($q) use ($country) {
                        $q->whereRaw('JSON_VALID(name) = 1')
                          ->whereRaw('JSON_EXTRACT(name, "$.en") = ?', [json_encode($country)]);
                    })
                    // Or try to match non-JSON data directly
                    ->orWhere('name', $country);
                })
                ->where('products_types_id', $localProductType->getId())
                ->with('attributes')
                ->first();

                $firstVariant = $productWithAttributes?->variants?->first();

                if ($firstVariant === null) {
                    $this->error('No variants found for product: ' . $product->name . ' in country: ' . $country);

                    continue;
                }

                $network = $firstVariant->getAttributeBySlug('variant-network')?->value;
                $speed = $firstVariant->getAttributeBySlug('variant-speed')?->value;

                $countryInfo[] = [
                    'country' => $country,
                    'flag' => 'https://flagcdn.com/w320/' . $countryCode . '.png',
                    'carriers' => [
                        [
                            'name' => $network,
                            'networks' => [
                                $speed,
                            ],
                        ],
                    ],
                ];
            }

            $product->addAttribute('Countries Details', $countryInfo);
            $countryInfo = [];
        }
    }

    public function getSpanishCountryName(string $countryCode, string $name): ?string
    {
        foreach ($this->countriesByRegion as $region => $countries) {
            foreach ($countries as $country) {
                if (strtolower($country['code']) === strtolower($countryCode)) {
                    return $country['name'];
                }
            }
        }

        return $name; // Country not found
    }
}
