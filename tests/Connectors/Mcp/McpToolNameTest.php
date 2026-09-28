<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use Kanvas\Connectors\Mcp\Support\McpToolName;
use Tests\TestCase;

final class McpToolNameTest extends TestCase
{
    public function testPrefixesAndKeepsLegalNamesIntact(): void
    {
        $this->assertSame('jira__createIssue', McpToolName::format('jira', 'createIssue'));
    }

    public function testRewritesCharactersProvidersReject(): void
    {
        // Providers enforce ^[a-zA-Z0-9_-]{1,64}$ and reject the WHOLE tool list on one bad name, so a
        // server shipping dots or colons must not be passed through untouched.
        $name = McpToolName::format('atlassian', 'jira.issues:search/all');

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,64}$/', $name);
    }

    public function testTruncatesPastTheProviderLimit(): void
    {
        $name = McpToolName::format('averyverylongvendorprefixindeed', str_repeat('extremelyLongToolName', 4));

        $this->assertLessThanOrEqual(McpToolName::MAX_LENGTH, strlen($name));
    }

    public function testTwoLongNamesUnderOnePrefixDoNotCollapseIntoEachOther(): void
    {
        $prefix = 'averyverylongvendorprefixindeed';
        $shared = str_repeat('sharedLeadingSegment', 3);

        $first = McpToolName::format($prefix, $shared . 'AlphaVariant');
        $second = McpToolName::format($prefix, $shared . 'BetaVariant');

        // Plain truncation would map both onto the same identifier and silently shadow one tool.
        $this->assertNotSame($first, $second);
    }

    public function testIsStableAcrossCalls(): void
    {
        $this->assertSame(
            McpToolName::format('jira', 'createIssue'),
            McpToolName::format('jira', 'createIssue')
        );
    }
}
