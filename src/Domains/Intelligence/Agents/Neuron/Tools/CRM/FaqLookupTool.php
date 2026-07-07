<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Answer a prospect's question from the business's FAQ knowledge base.
 *
 * FAQs are NOT a bespoke domain — they are message-type `faq` Message records (question/answer/
 * category in the message JSON), scoped by app + company. App and company are constructor-injected
 * (the agent's tenant), so the LLM can only ever read THIS company's FAQs — it never passes a
 * company id. The tool just optionally narrows by keyword.
 */
#[AgentTool(name: 'Company FAQ')]
class FaqLookupTool extends Tool
{
    private const FAQ_VERB = 'faq';

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
    ) {
        parent::__construct(
            name: 'get_company_faqs',
            description: 'Answer the prospect from the business\'s FAQ knowledge base — hours, pricing, location, services, policies, '
                . 'and anything else the business has documented. Call this FIRST whenever the prospect asks a question about the business. '
                . 'Pass the prospect\'s question as `query` to narrow the results; omit it to get the full FAQ list. '
                . 'If it returns nothing, the business has not documented that yet — do not invent an answer.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'query',
                type: PropertyType::STRING,
                description: 'The prospect\'s question or keywords, used to narrow the FAQ list. Omit to return all FAQs.',
                required: false,
            ),
        ];
    }

    public function __invoke(?string $query = null): array
    {
        $faqType = MessageTypeService::getOrCreate($this->app, self::FAQ_VERB);

        $messages = Message::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('message_types_id', $faqType->getId())
            ->where('is_deleted', 0)
            ->get();

        $faqs = [];
        foreach ($messages as $message) {
            $data = $message->getMessage();
            $question = trim((string) ($data['question'] ?? ''));
            $answer = trim((string) ($data['answer'] ?? ''));
            if ($question === '' && $answer === '') {
                continue;
            }
            $category = isset($data['category']) ? trim((string) $data['category']) : '';
            $faqs[] = [
                'question' => $question,
                'answer' => $answer,
                'category' => $category !== '' ? $category : null,
            ];
        }

        if ($query !== null && trim($query) !== '') {
            $needle = mb_strtolower(trim($query));
            $matched = array_values(array_filter($faqs, static function (array $faq) use ($needle): bool {
                $haystack = mb_strtolower(
                    $faq['question'] . ' ' . $faq['answer'] . ' ' . ($faq['category'] ?? '')
                );

                return str_contains($haystack, $needle);
            }));

            // Only narrow when the keyword actually matched something — an empty filtered
            // result would read to the LLM as "this business has no FAQs", which is wrong.
            if ($matched !== []) {
                $faqs = $matched;
            }
        }

        return [
            'status' => 'success',
            'count' => count($faqs),
            'faqs' => $faqs,
        ];
    }
}
