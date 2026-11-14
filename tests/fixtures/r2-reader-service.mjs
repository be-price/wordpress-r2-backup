import { createRequire } from 'node:module';
import { readFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import { createInterface } from 'node:readline';

const require = createRequire(new URL('../support/r2-connector/package.json', import.meta.url));
const { Miniflare, Log, LogLevel } = require('miniflare');
const config = JSON.parse(await readFile(process.argv[2], 'utf8'));
const mf = new Miniflare({
  host: '127.0.0.1', port: 0, inspectorPort: 0,
  compatibilityDate: '2026-07-22', modules: true,
  script: 'export default { fetch() { return new Response("No connector or DO", {status:404}); } };',
  r2Buckets: { BUCKET: { id: config.bucket, s3Credentials: config.credentials } },
  r2Persist: false, log: new Log(LogLevel.ERROR),
  outboundService: () => new Response('Disabled', { status: 403 }),
});
let fault = {};
let requests = 0;
let urls = [];
const server = createServer((request, response) => {
  requests++;
  urls.push(request.url);
  if (fault.mode === 'redirect') {
    response.writeHead(302, { Location: `${config.endpoint ?? 'http://127.0.0.1:1'}/must-not-follow` });
    response.end();
  } else if (fault.mode === 'cut') {
    if (request.method === 'HEAD') { response.writeHead(200); response.end(); return; }
    if (request.url.includes('/descriptors/')) { response.writeHead(404); response.end(); return; }
    response.writeHead(200, { 'Content-Length': 100 });
    response.write('cut');
    setImmediate(() => response.destroy());
  } else {
    response.writeHead(200, { 'Content-Type': 'application/xml' });
    response.end(fault.pages?.[requests - 1] ?? fault.body ?? '');
  }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const origin = (await mf.ready).origin;
const bucket = await mf.getR2Bucket('BUCKET');
const reply = data => process.stdout.write(`${JSON.stringify(data)}\n`);
reply({ endpoint: origin, faultEndpoint: `http://127.0.0.1:${server.address().port}` });
const lines = createInterface({ input: process.stdin });
try {
  for await (const line of lines) {
    const command = JSON.parse(line);
    if (command.action === 'put') {
      const body = command.repeat ? Buffer.alloc(command.repeat, command.byte ?? 120) : Buffer.from(command.body, 'base64');
      await bucket.put(command.key, body);
      reply({ ok: true });
    } else if (command.action === 'delete') {
      await bucket.delete(command.key);
      reply({ ok: true });
    } else if (command.action === 'fault') {
      fault = command;
      requests = 0;
      urls = [];
      reply({ ok: true });
    } else if (command.action === 'requests') {
      reply({ requests, urls });
    } else if (command.action === 'stop') {
      break;
    } else {
      throw new Error('Unknown fixture command');
    }
  }
} finally {
  server.closeAllConnections();
  await new Promise(resolve => server.close(resolve));
  await mf.dispose();
}
