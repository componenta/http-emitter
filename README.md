# Componenta HTTP Emitter

PSR-7 response emitter for Componenta HTTP applications. It sends status, headers, and body content to PHP output.

Use this package at the outer HTTP entry point after the PSR-15 pipeline has produced a `ResponseInterface`.

## Boundary

This package only emits an already built response. It does not build responses, create server requests, or run middleware. Use `componenta/http-responder` for response creation and `componenta/app-http` for the HTTP runtime pipeline.

## Installation

```bash
composer require componenta/http-emitter
```

`Componenta\Http\EmitterConfigProvider` is exposed through Composer metadata.

## Public API

`EmitterInterface::emit(ResponseInterface $response): void` emits a response. `Emitter` is the default implementation.

```php
use Componenta\Http\EmitterInterface;

/** @var EmitterInterface $emitter */
$emitter->emit($response);
```

`Emitter` accepts an optional chunk size:

```php
$emitter = new Componenta\Http\Emitter(chunkSize: 16_384);
```

`EmitStrategy` controls body emission:

| Case | Meaning |
|---|---|
| `Full` | Emit the full response body. |
| `Partial` | Emit a range response body. |
| `NoBody` | Emit headers without a body. |

`EmitterException` is thrown for emitter-level failures.

## Runtime Behavior

Before emitting, `Emitter` checks that headers have not already been sent, validates metadata/framing, verifies the output-buffer stack is removable, and clears it. Then it chooses a strategy from the response status and headers:

| Strategy | When it is used |
|---|---|
| `NoBody` | Informational statuses, `204`, `205`, or `304`. |
| `Partial` | Status `206` with a `Content-Range` header. |
| `Full` | All other responses. |

For content responses, the emitter rewinds seekable streams before committing headers and infers `Content-Length` from a known size. It validates an explicit single decimal length and checks the actual bytes emitted, including non-seekable streams. A non-seekable body is emitted from its current position without inferring its remaining length. Transfer coding and trailers belong to PHP/the HTTP server and are rejected on content responses. No `Accept-Ranges` is inferred: range selection is the producer's responsibility. `X-Accel-Redirect`/`X-Sendfile` responses do not acquire an inferred length. Bodies are streamed in chunks, stop on disconnection, and throw if reading fails or makes no progress.

For partial responses, the emitter sends the body already prepared by the response producer and preserves `Content-Range`. Responses with status `416` retain their explanatory body. Responses with status `304` retain `Content-Location` and a valid explicit representation length while emitting no body. Informational and 204 responses omit framing headers, and 205 responses explicitly carry `Content-Length: 0`. The native PHP SAPI `REQUEST_METHOD=HEAD` suppresses body reads and preserves explicit representation length. This package is not a raw-socket or RoadRunner emitter.

## Configuration

The config provider registers `Emitter` and aliases `EmitterInterface` to it. Applications normally receive the emitter from the container.

## Tests

Run `composer test`. HTTP header tests use `php-cgi` beside the active PHP executable; set `PHP_CGI_BINARY` when it is installed elsewhere. These tests are skipped if CGI is unavailable, so install CGI in CI to verify response headers as well as bodies.

## Related Packages

- [`componenta/app-http`](../app-http/README.md) uses the emitter in the HTTP runtime.
- [`componenta/http-responder`](../http-responder/README.md) builds PSR-7 responses.

On a failure after output has started, the transport must terminate the incomplete response; never emit an error page as a second response. Explicit `Accept-Ranges` and `Content-Range` are the producer's responsibility. Automatic range advertisement and caller-supplied transfer coding are removed. Configuration supports Config 3 and 4.

`composer check` runs the strict PHPUnit suite and PHPStan. `composer benchmark` measures actual CGI response emission for small/large bodies, non-seekable streams, prepared ranges, no-content and HEAD responses. It includes PHP process startup and IPC overhead, so it is an end-to-end SAPI measurement rather than a microbenchmark of the emitter alone. Install a matching `php-cgi` or set `PHP_CGI_BINARY`.

The final PSR-7 response owns CGI control fields and representation headers. Previously staged values for these fields are removed before emission; unrelated native headers such as session cookies are retained. Do not set a separate `Status` field, or repeat `Location` or `Content-Type`. Successful `CONNECT` responses require a tunnel-capable transport and are rejected by this SAPI emitter.
