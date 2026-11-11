<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Quotes\Sow\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;

/**
 * The `sow` Social message type and its `message_schema`.
 *
 * `message_schema` is read by MessageSchemaValidator as Laravel validation rules (it calls
 * Validator::make($payload, $schema)), not as JSON Schema, so the shape is declared here as rules.
 * The rules live in code and are written onto the row on every ensure(), so changing them here is
 * the whole migration: the next SOW written for an app brings its row up to date.
 */
final class SowMessageTypeService
{
    public const string VERB = 'sow';

    public static function ensure(AppInterface $app): MessageType
    {
        $type = MessageTypeService::getOrCreate(
            app: $app,
            verb: self::VERB,
            name: 'Statement of Work',
            isPublic: false,
        );

        // Loose on purpose: MySQL's JSON column hands the keys back in its own order.
        if (json_decode((string) $type->message_schema, true) != self::rules()) {
            $type->message_schema = (string) json_encode(self::rules());
            $type->saveOrFail();
        }

        return $type;
    }

    /**
     * @return array<string, string>
     */
    public static function rules(): array
    {
        return [
            'quote_id' => 'required|integer',
            'summary' => 'required|string',
            'scope' => 'required|array|min:1',
            'scope.*' => 'required|string',
            'out_of_scope' => 'present|array',
            'out_of_scope.*' => 'string',
            'assumptions' => 'present|array',
            'assumptions.*' => 'string',
            'deliverables' => 'required|array|min:1',
            'deliverables.*.name' => 'required|string',
            'deliverables.*.acceptance_criteria' => 'required|string',
            'deliverables.*.quote_line_ids' => 'nullable|array',
            'deliverables.*.quote_line_ids.*' => 'integer',
            'timeline' => 'required|array',
            'timeline.start' => 'required|date_format:Y-m-d',
            'timeline.end' => 'required|date_format:Y-m-d|after_or_equal:timeline.start',
            'payment_schedule' => 'required|array|min:1',
            'payment_schedule.*.label' => 'required|string',
            'payment_schedule.*.when' => 'required|in:on_signature,date',
            'payment_schedule.*.date' => 'nullable|required_if:payment_schedule.*.when,date|date_format:Y-m-d',
            'payment_schedule.*.percent' => 'nullable|numeric|gt:0|max:100|required_without:payment_schedule.*.amount',
            'payment_schedule.*.amount' => 'nullable|numeric|gt:0|required_without:payment_schedule.*.percent',
            'change_process' => 'nullable|string',
            'payment_terms_id' => 'nullable|integer',
            'signatories' => 'required|array|min:1',
            'signatories.*.name' => 'required|string',
            'signatories.*.role' => 'required|string',
        ];
    }
}
