<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Enums;

/**
 * The components the admin chat renders from a `kanvas-artifact` fenced block. The frontend owns the
 * contract; `props()` mirrors it so a block is rejected here, where the model can fix it, instead of
 * rendering as a broken card.
 *
 * Prop spec shape: name => [type, required?, ...]. Types: string (nonEmpty?, maxLength?), number,
 * integer (min/max bound the value), bool, id (string|number), record_id (a numeric id or a uuid),
 * record_id_list, text_or_number, scalar (string|number|bool|null), enum (values), rows (flat objects),
 * list (item), filter (an object whose keys depend on a sibling prop — checked by the service).
 * On the list types min/max bound the entry count.
 *
 * `entity`, `records`, `metric` and `approvals` are LIVE: the model sends a reference or filters and the
 * admin reads the data itself. Their vocabulary — record types, filters, metric ids — is exported by the
 * frontend into `tests/fixtures/admin-artifact-contract.json`, and `ArtifactContractTest` fails when the
 * mirror here drifts from it.
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
    case APPROVALS = 'approvals';
    case RECORDS = 'records';
    case METRIC = 'metric';

    public const int MAX_RECORDS_ROWS = 25;

    private const array NUMBER_FORMATS = ['number', 'currency', 'percent', 'compact'];

    private const array APPROVAL_STATUSES = ['pending', 'approved', 'rejected', 'cancelled', 'expired', 'all'];

    private const array CHART_KINDS = ['bar', 'line', 'area', 'pie'];

    private const array METRIC_WINDOWS = ['last_7d', 'last_30d', 'last_90d', 'last_12m'];

    private const array METRIC_DISPLAYS = ['stats', 'chart', 'table', 'progress', 'keyvalue'];

    /**
     * The command-center metric catalog, by family. The admin resolves each id against its own
     * analytics queries; an id it does not have draws an empty tile, so an unknown one is refused here.
     */
    private const array METRICS = [
        'leads' => [
            'total',
            'open_count',
            'won_count',
            'lost_count',
            'win_rate',
            'over_time',
            'by_status',
            'by_source',
            'by_pipeline',
            'by_user',
        ],
        'deals' => [
            'total',
            'open_count',
            'won_count',
            'lost_count',
            'win_rate',
            'over_time',
            'by_stage',
            'by_status',
            'by_pipeline',
            'by_user',
        ],
        'orders' => [
            'total',
            'revenue',
            'aov',
            'count_over_time',
            'revenue_over_time',
            'by_status',
            'by_payment_status',
            'by_fulfillment_status',
        ],
        'messages' => ['total', 'over_time', 'by_type'],
        'events' => ['total', 'over_time', 'by_status', 'by_type', 'by_category', 'by_class'],
        'agents' => ['total', 'over_time', 'by_type', 'by_model', 'by_deployment_status', 'by_user'],
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public function props(): array
    {
        return match ($this) {
            self::CHART => [
                'kind' => ['type' => 'enum', 'required' => true, 'values' => self::CHART_KINDS],
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
                        // The client refuses to draw a label over 40 characters, so the block is
                        // validated against the same ceiling here rather than at render time.
                        'label' => ['type' => 'string', 'required' => true, 'maxLength' => 40],
                        'message' => ['type' => 'string', 'required' => true],
                        'variant' => ['type' => 'enum', 'values' => ['default', 'outline', 'destructive']],
                    ],
                ],
            ],
            self::ENTITY => [
                'type' => ['type' => 'enum', 'required' => true, 'values' => ArtifactEntityTypeEnum::values()],
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
            // Filters, not rows: the admin card queries the project's approval requests itself, so every
            // prop is optional and no filter at all means "what is pending".
            self::APPROVALS => [
                'status' => ['type' => 'enum', 'values' => self::APPROVAL_STATUSES],
                'type' => ['type' => 'string', 'nonEmpty' => true, 'maxLength' => 80],
                'ids' => ['type' => 'record_id_list', 'min' => 1, 'max' => 25],
                'peopleId' => ['type' => 'record_id'],
                'limit' => ['type' => 'integer', 'min' => 1, 'max' => 25],
            ],
            // What to list, not the rows. Which keys `filter` takes depends on `type`, so the service
            // checks them against ArtifactEntityTypeEnum::filters() once the type is known.
            self::RECORDS => [
                'type' => [
                    'type' => 'enum',
                    'required' => true,
                    'values' => ArtifactEntityTypeEnum::listable(),
                ],
                'filter' => ['type' => 'filter'],
                'search' => ['type' => 'string', 'nonEmpty' => true, 'maxLength' => 80],
                'limit' => ['type' => 'integer', 'min' => 1, 'max' => self::MAX_RECORDS_ROWS],
            ],
            self::METRIC => [
                'metric' => ['type' => 'enum', 'required' => true, 'values' => self::metrics()],
                'window' => ['type' => 'enum', 'values' => self::METRIC_WINDOWS],
                'as' => ['type' => 'enum', 'values' => self::METRIC_DISPLAYS],
                'chartKind' => ['type' => 'enum', 'values' => self::CHART_KINDS],
                'limit' => ['type' => 'integer', 'min' => 1, 'max' => 50],
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
                . 'props: items [{label (max 40 chars — it is a button, keep it to 3-5 words), message, '
                . 'variant? default|outline|destructive}] 1-6',
            self::ENTITY => 'entity — a LIVE record card: it shows your title and fields at once, then reads the '
                . 'record itself and shows it as it is now, with a link to it. props: type '
                . implode('|', ArtifactEntityTypeEnum::values()) . '; id (the REAL id or uuid a tool returned, '
                . 'never invented and never a name — it is checked against the company\'s records; category and '
                . 'channel take the slug); title; subtitle?; fields? [{key, value}] 0-6 (facts a tool gave you; '
                . 'never an email, a phone or an address — the card shows those itself, hidden until asked)',
            self::PROGRESS => 'progress — goal completion. props: items [{label, percent 0-100, note?}] 1-8',
            self::TIMELINE => 'timeline — a sequence of events, oldest first. props: items [{title, description?, '
                . 'date? (free text), status? done|current|upcoming}] 1-12',
            self::CALLOUT => 'callout — one short note that needs attention. props: variant info|success|warning|error; '
                . 'heading?; text',
            self::APPROVALS => 'approvals — LIVE approval requests across the whole project (not only the user\'s): '
                . 'send filters, never rows. The card reads the requests itself and the user approves, rejects, '
                . 'delegates or cancels from it, so do not also offer those as actions. props: status? '
                . 'pending|approved|rejected|cancelled|expired|all (default pending; all when ids or peopleId is set); '
                . 'type? (an exact approval_type such as approve_bill — omit it unless a tool gave you the type); '
                . 'ids? [approval request id or uuid, NOT the id of the bill or person it is about] 1-25; peopleId? '
                . '(the person\'s id or uuid, for everything about them); limit? 1-25 (default 5, or one per id)',
            self::RECORDS => 'records — a LIVE list of records of one type: send what to list, never the rows. The '
                . 'admin reads them and the user opens any row into its record card, so prefer it to a table '
                . 'whenever the rows are real records. props: type ' . implode('|', ArtifactEntityTypeEnum::listable())
                . '; filter? {key: value} with the exact keys of that type — ' . self::describeFilters()
                . ' ([] also takes a list; ids are real ids a tool returned); search? (free text, never together '
                . 'with filter); limit? 1-' . self::MAX_RECORDS_ROWS . ' (default 5). Give it a title that says '
                . 'what the list is',
            self::METRIC => 'metric — one LIVE figure, trend or breakdown the analytics already compute: name it '
                . 'and the admin runs the query, so never send the numbers. Prefer it to chart or stats when one of '
                . 'these answers the question. props: metric ' . self::describeMetrics() . '; window? '
                . implode('|', self::METRIC_WINDOWS) . ' (default last_90d); as? ' . implode('|', self::METRIC_DISPLAYS)
                . ' (default: stats for a total, chart for *_over_time and by_*); chartKind? '
                . implode('|', self::CHART_KINDS) . '; limit? 1-50 (top rows of a by_* breakdown)',
        };
    }

    /**
     * @return list<string>
     */
    public static function metrics(): array
    {
        $ids = [];

        foreach (self::METRICS as $family => $names) {
            foreach ($names as $name) {
                $ids[] = $family . '.' . $name;
            }
        }

        return $ids;
    }

    /**
     * "leads.{total, by_status}; deals.{…}" — a third of the length of listing every id, and the model
     * reads the family prefix as part of the id either way.
     */
    private static function describeMetrics(): string
    {
        $families = [];

        foreach (self::METRICS as $family => $names) {
            $families[] = $family . '.{' . implode(', ', $names) . '}';
        }

        return implode('; ', $families);
    }

    /**
     * "lead: statusId[], pipelineId; order: status[] (pending|completed)" — only the types that take
     * a filter at all.
     */
    private static function describeFilters(): string
    {
        $types = [];

        foreach (ArtifactEntityTypeEnum::cases() as $type) {
            $filters = $type->filters() ?? [];

            if ($filters === []) {
                continue;
            }

            $keys = [];
            foreach ($filters as $key => $rule) {
                $keys[] = $key
                    . (($rule['many'] ?? false) === true ? '[]' : '')
                    . ($rule['type'] === 'enum' ? ' (' . implode('|', $rule['values']) . ')' : '');
            }

            $types[] = $type->value . ': ' . implode(', ', $keys);
        }

        return implode('; ', $types);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
