<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Enums;

/**
 * The components the admin chat renders from a `kanvas-artifact` fenced block. The frontend owns the
 * contract; `props()` mirrors it so a block is rejected here, where the model can fix it, instead of
 * rendering as a broken card.
 *
 * Prop spec shape: name => [type, required?, ...]. Types: string, number, bool, id (string|number),
 * text_or_number, scalar (string|number|bool|null), enum (values), rows (flat objects), list (item).
 */
enum ArtifactComponentEnum: string
{
    case CHART = 'chart';
    case TABLE = 'table';
    case STATS = 'stats';
    case KEYVALUE = 'keyvalue';
    case ACTIONS = 'actions';
    case ENTITY = 'entity';
    case PROGRESS = 'progress';
    case TIMELINE = 'timeline';
    case CALLOUT = 'callout';

    private const array NUMBER_FORMATS = ['number', 'currency', 'percent', 'compact'];

    /**
     * @return array<string, array<string, mixed>>
     */
    public function props(): array
    {
        return match ($this) {
            self::CHART => [
                'kind' => ['type' => 'enum', 'required' => true, 'values' => ['bar', 'line', 'area', 'pie']],
                'data' => ['type' => 'rows', 'required' => true, 'min' => 1, 'max' => 100],
                'xKey' => ['type' => 'string', 'required' => true],
                'series' => [
                    'type' => 'list',
                    'required' => true,
                    'min' => 1,
                    'max' => 5,
                    'item' => [
                        'key' => ['type' => 'string', 'required' => true],
                        'label' => ['type' => 'string'],
                    ],
                ],
                'stacked' => ['type' => 'bool'],
                'yFormat' => ['type' => 'enum', 'values' => self::NUMBER_FORMATS],
            ],
            self::TABLE => [
                'columns' => [
                    'type' => 'list',
                    'required' => true,
                    'min' => 1,
                    'max' => 10,
                    'item' => [
                        'key' => ['type' => 'string', 'required' => true],
                        'label' => ['type' => 'string', 'required' => true],
                        'align' => ['type' => 'enum', 'values' => ['left', 'center', 'right']],
                        'format' => ['type' => 'enum', 'values' => ['text', 'number', 'currency', 'percent', 'date']],
                    ],
                ],
                'rows' => ['type' => 'rows', 'required' => true, 'min' => 0, 'max' => 50],
            ],
            self::STATS => [
                'items' => [
                    'type' => 'list',
                    'required' => true,
                    'min' => 1,
                    'max' => 6,
                    'item' => [
                        'label' => ['type' => 'string', 'required' => true],
                        'value' => ['type' => 'text_or_number', 'required' => true],
                        'format' => ['type' => 'enum', 'values' => self::NUMBER_FORMATS],
                        'trend' => ['type' => 'enum', 'values' => ['up', 'down', 'neutral']],
                        'delta' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                    ],
                ],
            ],
            self::KEYVALUE => [
                'items' => [
                    'type' => 'list',
                    'required' => true,
                    'min' => 1,
                    'max' => 30,
                    'item' => [
                        'key' => ['type' => 'string', 'required' => true],
                        'value' => ['type' => 'scalar', 'required' => true],
                    ],
                ],
            ],
            self::ACTIONS => [
                'items' => [
                    'type' => 'list',
                    'required' => true,
                    'min' => 1,
                    'max' => 6,
                    'item' => [
                        'label' => ['type' => 'string', 'required' => true],
                        'message' => ['type' => 'string', 'required' => true],
                        'variant' => ['type' => 'enum', 'values' => ['default', 'outline', 'destructive']],
                    ],
                ],
            ],
            self::ENTITY => [
                'type' => [
                    'type' => 'enum',
                    'required' => true,
                    'values' => ['lead', 'deal', 'people', 'organization', 'company', 'user', 'product', 'order'],
                ],
                'id' => ['type' => 'id', 'required' => true],
                'title' => ['type' => 'string', 'required' => true],
                'subtitle' => ['type' => 'string'],
                'fields' => [
                    'type' => 'list',
                    'min' => 0,
                    'max' => 6,
                    'item' => [
                        'key' => ['type' => 'string', 'required' => true],
                        'value' => ['type' => 'scalar', 'required' => true],
                    ],
                ],
            ],
            self::PROGRESS => [
                'items' => [
                    'type' => 'list',
                    'required' => true,
                    'min' => 1,
                    'max' => 8,
                    'item' => [
                        'label' => ['type' => 'string', 'required' => true],
                        'percent' => ['type' => 'number', 'required' => true],
                        'note' => ['type' => 'string'],
                    ],
                ],
            ],
            self::TIMELINE => [
                'items' => [
                    'type' => 'list',
                    'required' => true,
                    'min' => 1,
                    'max' => 12,
                    'item' => [
                        'title' => ['type' => 'string', 'required' => true],
                        'description' => ['type' => 'string'],
                        'date' => ['type' => 'string'],
                        'status' => ['type' => 'enum', 'values' => ['done', 'current', 'upcoming']],
                    ],
                ],
            ],
            self::CALLOUT => [
                'variant' => ['type' => 'enum', 'required' => true, 'values' => ['info', 'success', 'warning', 'error']],
                'heading' => ['type' => 'string'],
                'text' => ['type' => 'string', 'required' => true],
            ],
        };
    }

    /**
     * One line per component for the tool description — what it is for and its props, in the words the
     * model has to reproduce.
     */
    public function usage(): string
    {
        return match ($this) {
            self::CHART => 'chart — trends (line/area), comparisons (bar), composition (pie). '
                . 'props: kind bar|line|area|pie; data [flat objects, 1-100]; xKey (x-axis field, pie: slice name); '
                . 'series [{key, label?}] 1-5 (pie uses the first key as value); stacked? (bar/area); '
                . 'yFormat? number|currency|percent|compact',
            self::TABLE => 'table — record lists. props: columns [{key, label, align? left|center|right, '
                . 'format? text|number|currency|percent|date}] 1-10; rows [flat objects keyed by column key] 0-50',
            self::STATS => 'stats — headline metrics. props: items [{label, value (string|number), '
                . 'format? number|currency|percent|compact, trend? up|down|neutral, delta?, description?}] 1-6',
            self::KEYVALUE => 'keyvalue — one record\'s fields. props: items [{key, value (string|number|boolean|null)}] 1-30',
            self::ACTIONS => 'actions — next-step buttons; each message is sent back to you as the user\'s next '
                . 'message, so make it self-contained (include the email or id) and only offer what your tools can do. '
                . 'props: items [{label, message, variant? default|outline|destructive}] 1-6',
            self::ENTITY => 'entity — a record card with an Open button. props: type lead|deal|people|organization|'
                . 'company|user|product|order; id (the REAL id a tool returned, never invented); title; subtitle?; '
                . 'fields? [{key, value}] 0-6',
            self::PROGRESS => 'progress — goal completion. props: items [{label, percent 0-100, note?}] 1-8',
            self::TIMELINE => 'timeline — a sequence of events, oldest first. props: items [{title, description?, '
                . 'date? (free text), status? done|current|upcoming}] 1-12',
            self::CALLOUT => 'callout — one short note that needs attention. props: variant info|success|warning|error; '
                . 'heading?; text',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
