<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Support;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Templates\Support\PlainData;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\Node\ConstantNode;
use Symfony\Component\ExpressionLanguage\Node\FunctionNode;
use Symfony\Component\ExpressionLanguage\Node\GetAttrNode;
use Symfony\Component\ExpressionLanguage\Node\Node;
use Symfony\Component\ExpressionLanguage\Parser;
use Symfony\Component\ExpressionLanguage\SyntaxError;

/**
 * ExpressionLanguage for stored expressions. No function calls anywhere.
 *
 * A condition (rule, notification logic) answers yes or no about a record, so it may read attributes,
 * relations and the getters below, through ExpressionModel. Text (an engagement message) is shown to
 * people, so it reads plain data only and calls nothing.
 */
final class SafeExpression
{
    /**
     * The read-only methods production rule conditions call (scan of 2026-10-18): custom fields and
     * settings, order metadata, message body, tag lookup. Add one only after checking it writes nothing.
     */
    public const array GETTERS = ['get', 'getMetadata', 'getMessage', 'hasTag'];

    public static function evaluate(string $expression, array $values): mixed
    {
        $data = ExpressionData::wrapValues($values);

        return self::run($expression, $data, self::GETTERS);
    }

    public static function evaluateText(string $expression, array $values): mixed
    {
        $data = ExpressionData::wrapValues(PlainData::from($values));

        return self::run($expression, $data, []);
    }

    /**
     * For write paths, where the variables the expression reads are not known yet.
     */
    public static function assertCondition(string $expression): void
    {
        try {
            $parsed = new ExpressionLanguage()->parse($expression, [], Parser::IGNORE_UNKNOWN_VARIABLES);
        } catch (SyntaxError $e) {
            throw new ValidationException('Invalid expression: ' . $e->getMessage());
        }

        self::assertCalls($parsed->getNodes(), self::GETTERS);
    }

    /**
     * @param list<string> $allowedMethods
     */
    private static function run(string $expression, array $data, array $allowedMethods): mixed
    {
        $language = new ExpressionLanguage();
        $parsed = $language->parse($expression, array_keys($data));
        self::assertCalls($parsed->getNodes(), $allowedMethods);

        return $language->evaluate($parsed, $data);
    }

    /**
     * @param list<string> $allowedMethods
     */
    private static function assertCalls(Node $node, array $allowedMethods): void
    {
        if ($node instanceof FunctionNode) {
            throw new ValidationException('Expressions cannot call functions.');
        }

        if ($node instanceof GetAttrNode && $node->attributes['type'] === GetAttrNode::METHOD_CALL) {
            $method = $node->nodes['attribute'] instanceof ConstantNode
                ? $node->nodes['attribute']->attributes['value']
                : null;

            if (! in_array($method, $allowedMethods, true)) {
                throw new ValidationException("Expressions cannot call {$method}().");
            }
        }

        foreach ($node->nodes as $child) {
            self::assertCalls($child, $allowedMethods);
        }
    }
}
