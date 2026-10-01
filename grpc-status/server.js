'use strict';

const http = require('node:http');
const path = require('node:path');
const grpc = require('@grpc/grpc-js');
const protoLoader = require('@grpc/proto-loader');
const mysql = require('mysql2/promise');

const GRPC_PORT = Number.parseInt(process.env.GRPC_PORT || '50051', 10);
const HEALTH_PORT = Number.parseInt(process.env.HEALTH_PORT || '8080', 10);
const PROTO_PATH = path.join(__dirname, 'status.proto');

const packageDefinition = protoLoader.loadSync(PROTO_PATH, {
  keepCase: false,
  longs: Number,
  enums: String,
  defaults: true,
  oneofs: true,
});
const orderV1 = grpc.loadPackageDefinition(packageDefinition).order.v1;

const pool = mysql.createPool({
  host: process.env.DB_HOST || 'order-mysql',
  port: Number.parseInt(process.env.DB_PORT || '3306', 10),
  database: process.env.DB_DATABASE,
  user: process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD,
  waitForConnections: true,
  connectionLimit: Number.parseInt(process.env.DB_POOL_SIZE || '5', 10),
  enableKeepAlive: true,
  decimalNumbers: true,
});

function grpcError(code, message) {
  const error = new Error(message);
  error.code = code;
  error.details = message;
  error.metadata = new grpc.Metadata();
  return error;
}

async function getStatus(lookup) {
  const hasId = Number.isInteger(lookup.id) && lookup.id > 0;
  const name = typeof lookup.name === 'string' ? lookup.name.trim() : '';

  if (hasId === Boolean(name)) {
    throw grpcError(grpc.status.INVALID_ARGUMENT, 'Specify exactly one positive id or non-empty name');
  }

  const [rows] = hasId
    ? await pool.execute('SELECT id, name FROM statuses WHERE id = ? LIMIT 1', [lookup.id])
    : await pool.execute('SELECT id, name FROM statuses WHERE name = ? LIMIT 1', [name]);

  if (rows.length === 0) {
    throw grpcError(grpc.status.NOT_FOUND, 'Status not found');
  }

  return { id: Number(rows[0].id), name: String(rows[0].name) };
}

async function listStatuses() {
  const [rows] = await pool.execute('SELECT id, name FROM statuses ORDER BY id');
  return rows.map((row) => ({ id: Number(row.id), name: String(row.name) }));
}

function respond(callback, work) {
  Promise.resolve()
    .then(work)
    .then((response) => callback(null, response))
    .catch((error) => {
      if (typeof error.code === 'number' && error.metadata instanceof grpc.Metadata) {
        callback(error);
        return;
      }

      console.error('Status lookup failed:', error.message);
      callback(grpcError(grpc.status.UNAVAILABLE, 'Status data is temporarily unavailable'));
    });
}

function getRequestId(call) {
  const requestId = call.metadata.get('x-request-id')[0];
  return typeof requestId === 'string' && requestId.length > 0
    ? requestId
    : 'missing';
}

const grpcServer = new grpc.Server();
grpcServer.addService(orderV1.StatusService.service, {
  getStatus(call, callback) {
    const requestId = getRequestId(call);
    console.info(JSON.stringify({
      event: 'order_status.grpc.request_received',
      request_id: requestId,
      lookup_type: call.request.name ? 'name' : 'id',
      status_name: call.request.name || undefined,
      status_id: call.request.id || undefined,
    }));
    respond(callback, () => getStatus(call.request));
  },
  listStatuses(_call, callback) {
    respond(callback, async () => ({ statuses: await listStatuses() }));
  },
});

const healthServer = http.createServer(async (_request, response) => {
  try {
    await pool.execute('SELECT id FROM statuses LIMIT 1');
    response.writeHead(200, { 'content-type': 'application/json' });
    response.end('{"status":"ok"}');
  } catch (error) {
    console.error('Status adapter health check failed:', error.message);
    response.writeHead(503, { 'content-type': 'application/json' });
    response.end('{"status":"unavailable"}');
  }
});

let shuttingDown = false;
async function shutdown(signal) {
  if (shuttingDown) return;
  shuttingDown = true;
  console.log(`Received ${signal}; shutting down status adapter`);

  healthServer.close();
  grpcServer.tryShutdown(async () => {
    try {
      await pool.end();
      process.exit(0);
    } catch (error) {
      console.error('Error closing MySQL pool:', error.message);
      process.exit(1);
    }
  });

  setTimeout(() => grpcServer.forceShutdown(), 10000).unref();
}

process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));

grpcServer.bindAsync(
  `0.0.0.0:${GRPC_PORT}`,
  grpc.ServerCredentials.createInsecure(),
  (error) => {
    if (error) {
      console.error('Failed to bind gRPC server:', error.message);
      process.exit(1);
    }
    grpcServer.start();
    console.log(`Order status gRPC service listening on ${GRPC_PORT}`);
  },
);

healthServer.listen(HEALTH_PORT, '0.0.0.0', () => {
  console.log(`Order status health endpoint listening on ${HEALTH_PORT}`);
});
