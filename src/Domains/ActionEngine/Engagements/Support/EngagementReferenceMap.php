<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Support;

use Baka\Contracts\EngageableInterface;
use InvalidArgumentException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Quotes\Models\Quote;

/**
 * The aliases `engagements.entity_type` may store. Deliberately not Laravel's global morph map:
 * nothing else in the app enforces one, and putting Lead in it would change Lead::getMorphClass()
 * for every other polymorphic column that already stores the class name (tags, approvals, ...).
 */
final class EngagementReferenceMap
{
    public const string LEAD = 'lead';
    public const string SCRIBE_INVOICE = 'scribe_invoice';
    public const string SCRIBE_QUOTE = 'scribe_quote';

    /**
     * Add an alias here when a new model starts implementing EngageableInterface.
     *
     * @var array<string, class-string<EngageableInterface>>
     */
    private const array MAP = [
        self::LEAD => Lead::class,
        self::SCRIBE_INVOICE => Invoice::class,
        self::SCRIBE_QUOTE => Quote::class,
    ];

    /**
     * Test-only additions; production aliases belong in MAP.
     *
     * @var array<string, class-string<EngageableInterface>>
     */
    private static array $extra = [];

    /**
     * @return array<string, class-string<EngageableInterface>>
     */
    public static function all(): array
    {
        return self::MAP + self::$extra;
    }

    public static function isRegistered(string $alias): bool
    {
        $class = self::all()[$alias] ?? null;

        return $class !== null && is_subclass_of($class, EngageableInterface::class);
    }

    /**
     * @return class-string<EngageableInterface>
     */
    public static function classFor(string $alias): string
    {
        if (! self::isRegistered($alias)) {
            throw new InvalidArgumentException("'{$alias}' is not a registered engagement reference type.");
        }

        return self::all()[$alias];
    }

    public static function aliasFor(EngageableInterface $reference): string
    {
        foreach (self::all() as $alias => $class) {
            if ($reference::class === $class) {
                return $alias;
            }
        }

        throw new InvalidArgumentException($reference::class . ' has no registered engagement reference alias.');
    }

    /**
     * @param class-string<EngageableInterface> $class
     */
    public static function register(string $alias, string $class): void
    {
        self::$extra[$alias] = $class;
    }

    public static function forget(string $alias): void
    {
        unset(self::$extra[$alias]);
    }
}
