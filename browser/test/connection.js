import { chromium } from 'playwright';

const wsEndpoint = process.env.PLAYWRIGHT_WS_ENDPOINT ?? 'ws://browser:3000/playwright';

let browser;
let context;

try {
  browser = await chromium.connect(wsEndpoint);
  context = await browser.newContext();

  const page = await context.newPage();
  await page.goto('https://example.com');

  const title = await page.title();
  console.log(title);

  if (title !== 'Example Domain') {
    throw new Error(`Unexpected page title: ${title}`);
  }
} finally {
  await context?.close();
  await browser?.close();
}
