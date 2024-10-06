<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Shopify\Actions\SyncShopifyOrderAction;
use Kanvas\Enums\AppEnums;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Workflow\Models\ReceiverWebhookCall;

class KanvasVersionCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:version';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Whats the current version of kanvas niche you are running';

    // Array of US state names and abbreviations

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->newLine();
        $this->info('Kanvas Niche is running version : ' . AppEnums::VERSION->getValue());
        $this->newLine();
        $app = Apps::getById(18);
        $this->overwriteAppService($app);

        $shopAction = new SyncShopifyOrderAction(
            $app,
            Companies::getById(9091),
            Regions::getById(455),
            ReceiverWebhookCall::getById(11967)->payload
        );

        print_r($shopAction->execute());

        echo "Done";
        die();
        /**
        $app = Apps::getById(14);
        $company = Companies::getById(8501);
        $batchSize = 500;
        $indices = [
            'cro',
            'revenue',
            'audience-development',
            'audience',
            'product',
            'subscriptions',
            'events',
            'cto',
            'ad-buying',
        ];

        People::fromApp($app)
        ->fromCompany($company)
        ->notDeleted(0)
        ->orderBy('peoples.id', 'DESC')
        ->chunk($batchSize, function ($peoples) use ($indices) {
            foreach ($peoples as $people) {
                if ($people->get('status') <> 1) {
                    //  $people->set('status', 1);
                }
                //$people->searchable();
                $tags = $people->tags();
                if ($tags->count() == 0) {
                    continue;
                }
                $this->info('Processing people id : ' . $people->getId());

                foreach ($people->tags()->get() as $tag) {
                    if (strtolower($tag->name) !== 'vip' && in_array(strtolower($tag->name), $indices)) {
                        //      if (! $people->get('audience_segment')) {}
                    }

                    if ($this->isSimilarToUSState($tag->name) && ! in_array(strtolower($tag->name), $indices)) {
                        //$people->set('location', ucfirst($tag->name));
                        $people->addAddress(new Address(
                            address: ucfirst($tag->name),
                            state: ucfirst($tag->name),
                        ));

                        $this->info('Adding location to people id : ' . $people->getId());
                    }
                    //check if tag is similar to a location a US state
                }

                //send to index to algoli

                //audience_segment
                //location
            }
        });*/

        return;
    }

    // Function to check if the tag is a US state (either abbreviation or full name)
    protected function isSimilarToUSState(string $tag): bool
    {
        $us_states = [
            'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
            'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
            'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho',
            'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
            'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
            'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
            'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
            'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
            'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
            'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina',
            'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah',
            'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia',
            'WI' => 'Wisconsin', 'WY' => 'Wyoming',
        ];

        // Standardize the tag for comparison (case-insensitive)
        $tag = strtolower(trim($tag));
        $similarityThreshold = 75;
        // Check if tag is an abbreviation or full state name
        foreach ($us_states as $abbr => $state) {
            // Calculate similarity with abbreviation
            similar_text($tag, strtolower($abbr), $abbrSimilarity);
            if ($abbrSimilarity >= $similarityThreshold) {
                return true;
            }

            // Calculate similarity with full state name
            similar_text($tag, strtolower($state), $stateSimilarity);
            if ($stateSimilarity >= $similarityThreshold) {
                return true;
            }
        }

        return false;
    }
}
