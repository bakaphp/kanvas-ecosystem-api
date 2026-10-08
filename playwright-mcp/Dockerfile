FROM node:22-bookworm-slim

ENV NODE_ENV=production \
    PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --omit=dev

COPY test ./test

USER node

EXPOSE 8931

HEALTHCHECK --interval=10s --timeout=3s --start-period=10s --retries=5 \
    CMD ["node", "-e", "const net=require('node:net');const p=Number(process.env.PLAYWRIGHT_MCP_PORT||8931);const s=net.connect(p,'127.0.0.1',()=>s.end());s.on('error',()=>process.exit(1));setTimeout(()=>process.exit(1),2000).unref()"]

CMD ["node", "node_modules/@playwright/mcp/cli.js", "--endpoint=ws://browser:3000/playwright", "--host=0.0.0.0", "--port=8931", "--allowed-hosts=playwright-mcp:8931,localhost:8931,127.0.0.1:8931", "--isolated"]
