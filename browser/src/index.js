import { chromium } from 'playwright';

const host = process.env.PLAYWRIGHT_HOST ?? '0.0.0.0';
const port = Number.parseInt(process.env.PLAYWRIGHT_PORT ?? '3000', 10);
const wsPath = process.env.PLAYWRIGHT_WS_PATH ?? '/playwright';

let server;
let shuttingDown = false;

async function shutdown(signal) {
  if (shuttingDown) {
    return;
  }

  shuttingDown = true;
  console.log(`Received ${signal}; closing Chromium headless`);

  try {
    await server?.close();
    console.log('Chromium headless stopped');
    process.exitCode = 0;
  } catch (error) {
    console.error('Failed to close Chromium headless cleanly', error);
    process.exitCode = 1;
  }
}

process.once('SIGTERM', () => void shutdown('SIGTERM'));
process.once('SIGINT', () => void shutdown('SIGINT'));

try {
  server = await chromium.launchServer({
    headless: true,
    host,
    port,
    wsPath,
  });

  console.log('Chromium headless started');
  console.log(`Playwright WebSocket endpoint: ${server.wsEndpoint()}`);
} catch (error) {
  console.error('Failed to start Chromium headless', error);
  process.exitCode = 1;
}
