<?

namespace Kanvas\Intelligence\Services;

use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Google\Enums\ConfigurationEnum;
use Google\Cloud\BigQuery\BigQueryClient;
use OpenAI;
use OpenAI\Responses\Chat\CreateResponseUsage;

class LlmBudgetAlertService
{
    public function checkBudgetAlert(): void
    {
        // $geminiCostData = $this->checkGeminiCostData();
        $openCostData = $this->checkOpenAICostData();
        // $grokCostData = $this->checkGrokCostData();

        // print_r($geminiCostData);
        print_r($openCostData);
        die();
    }

    private function checkGeminiCostData(): void
    {
        $projectId = 'vocal-door-424204-q8';
        $datasetId = 'gemini_monthly_cost';
        $tableId   = 'gcp_billing_export_v1_XXXXXX';
        $app = Apps::find(13);
        $googlePaymentConfig = $app->get(ConfigurationEnum::GOOGLE_CLIENT_CONFIG->value);
        $bigQuery = new BigQueryClient([
            'credentials' => $googlePaymentConfig,
            'projectId' => $projectId,
        ]);

        $query = "
            SELECT
              SUM(cost) AS total_cost
            FROM `$projectId.$datasetId.$tableId`
            WHERE service.description = 'Generative Language API'
              AND EXTRACT(YEAR FROM usage_start_time) = EXTRACT(YEAR FROM CURRENT_DATE())
              AND EXTRACT(MONTH FROM usage_start_time) = EXTRACT(MONTH FROM CURRENT_DATE())
        ";

        $queryJobConfig = $bigQuery->query($query);
        $queryResults = $bigQuery->runQuery($queryJobConfig);
        foreach ($queryResults as $row) {
            printf("Gemini Month-to-Date Cost: \$%.2f\n", $row['total_cost']);
        }
    }

    private function checkOpenAICostData(): array
    {
        $app = Apps::find(13);
        $app->reGenerateRedisSettings();
        $firstDayOfMonthTimestamp = mktime(0, 0, 0, date('n'), 1, date('Y'));
        $currentDate = time();

        $chatCompletionsUsageCount = function () use ($app, $firstDayOfMonthTimestamp, $currentDate) {
            $usageData = Http::withHeaders([
                'Authorization' => 'Bearer ' . $app->get('open_ai_admin_api_key'),
                'Content-Type' => 'application/json'
            ])->get('https://api.openai.com/v1/organization/usage/completions?start_time=' . $firstDayOfMonthTimestamp . '&end_time=' . $currentDate . '&limit=31');

            $usageData = json_decode($usageData);
            $totalInputTokens = 0;
            foreach ($usageData->data as $bucket) {
                foreach ($bucket->results as $result) {
                    $totalInputTokens += $result->input_tokens;
                }
            }
            return $totalInputTokens;
        };

        $imageUsageCount = function () use ($app, $firstDayOfMonthTimestamp, $currentDate) {
            $usageData = Http::withHeaders([
            'Authorization' => 'Bearer ' . $app->get('open_ai_admin_api_key'),
            'Content-Type' => 'application/json'
            ])->get('https://api.openai.com/v1/organization/usage/images?start_time=' . $firstDayOfMonthTimestamp . '&end_time=' . $currentDate . '&limit=31');

            $usageData = json_decode($usageData);
            $totalInputTokens = 0;
            foreach ($usageData->data as $bucket) {
                foreach ($bucket->results as $result) {
                    // print_r($result);
                    // die();
                    $totalInputTokens += $result->images;
                }
            }

            return $totalInputTokens;
        };


        $chatTokens = $chatCompletionsUsageCount();
        $imageCount = $imageUsageCount();

        return [
            'chat_completions' => [
                'input_tokens' => $chatTokens,
                'total_cost' => $chatTokens * $app->get('open_ai_chat_completion_cost_per_token'),
            ],
            'images' => [
                'images_count' => $imageCount,
                'total_cost' => $imageCount * $app->get('open_ai_image_generation_cost_per_token'),
            ],
            
            'total' => $chatTokens + $imageCount
        ];
    }

    private function checkGrokCostData(): CreateResponseUsage
    {
        $app = Apps::find(13);
        $app->reGenerateRedisSettings();
        $openAIClient = OpenAI::factory()
            ->withApiKey($app->get('grok_api_key'))
            ->withBaseUri("https://api.x.ai/v1")
            ->make();

        $response = $openAIClient->chat()->create([
            'model' => 'grok-4',
            'messages' => [
                ['role' => 'user', 'content' => 'This is a test'],
            ],
        ]);

        return $response->usage;
    }
}
