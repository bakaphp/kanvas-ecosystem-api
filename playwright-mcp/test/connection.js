import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';

const endpoint = process.env.PLAYWRIGHT_MCP_URL ?? 'http://playwright-mcp:8931/mcp';
const client = new Client({ name: 'kanvas-playwright-mcp-smoke-test', version: '1.0.0' });
const transport = new StreamableHTTPClientTransport(new URL(endpoint));

try {
  await client.connect(transport);

  const { tools } = await client.listTools();
  const toolNames = tools.map(({ name }) => name);
  console.log(`MCP tools (${toolNames.length}): ${toolNames.join(', ')}`);

  for (const requiredTool of ['browser_navigate', 'browser_snapshot']) {
    if (!toolNames.includes(requiredTool))
      throw new Error(`Required tool is missing: ${requiredTool}`);
  }

  const navigation = await client.callTool({
    name: 'browser_navigate',
    arguments: { url: 'https://example.com' },
  });
  if (navigation.isError)
    throw new Error(`browser_navigate failed: ${JSON.stringify(navigation.content)}`);

  const snapshot = await client.callTool({ name: 'browser_snapshot', arguments: {} });
  const snapshotText = snapshot.content
    .filter(({ type }) => type === 'text')
    .map(({ text }) => text)
    .join('\n');

  if (snapshot.isError || !snapshotText.includes('Example Domain'))
    throw new Error(`Unexpected browser_snapshot result: ${snapshotText}`);

  const expectedLink = snapshotText.includes('More information')
    ? 'More information'
    : snapshotText.includes('Learn more') ? 'Learn more' : null;

  if (expectedLink === null)
    throw new Error(`Example Domain link was not present in snapshot: ${snapshotText}`);

  console.log(`Snapshot verified: Example Domain / ${expectedLink}`);
} finally {
  await client.close();
}
