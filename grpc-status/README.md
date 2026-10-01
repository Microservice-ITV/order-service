# Order status gRPC adapter

This internal adapter exposes the order-service status lookup over gRPC while
keeping Laravel/PHP responsible for HTTP APIs. It is deployed as a separate
container because the official PHP gRPC runtime supports clients but not
servers.

## RPCs

- `order.v1.StatusService/GetStatus`: look up by positive integer `id` or
  canonical `name`.
- `order.v1.StatusService/ListStatuses`: list all rows ordered by `id`.

The adapter reads only the `id` and `name` columns in order-service's
`statuses` table. It exposes gRPC on port `50051` and an internal readiness
endpoint on `8080/health`; Compose does not publish either port to the host.
Incoming lookups log the `x-request-id` metadata value when supplied by the
caller.

The image is built from this directory and receives the order-service database
connection variables from its Compose stack. Keep `status.proto` synchronized
with `promotion-service/src/grpc/status.proto` when changing the contract.
