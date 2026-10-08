FROM mcr.microsoft.com/playwright:v1.63.0-noble

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --omit=dev

COPY --chown=pwuser:pwuser src ./src
COPY --chown=pwuser:pwuser test ./test

ENV NODE_ENV=production \
    PLAYWRIGHT_HOST=0.0.0.0 \
    PLAYWRIGHT_PORT=3000 \
    PLAYWRIGHT_WS_PATH=/playwright

EXPOSE 3000

USER pwuser

HEALTHCHECK --interval=10s --timeout=3s --start-period=10s --retries=3 \
  CMD ["node", "-e", "const net=require('node:net');const socket=net.connect(3000,'127.0.0.1',()=>{socket.end();process.exit(0)});socket.setTimeout(2000);socket.on('timeout',()=>socket.destroy());socket.on('error',()=>process.exit(1));socket.on('close',hadError=>process.exit(hadError?1:0))"]

CMD ["node", "src/index.js"]
