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

Before emitting, `Emitter` checks that headers have not already been sent and clears active output buffers. Then it chooses a strategy from the response status and headers:

| Strategy | When it is used |
|---|---|
| `NoBody` | Informational statuses, `204`, `205`, or `304`. |
| `Partial` | Status `206` with a `Content-Range` header. |
| `Full` | All other responses. |

For full responses, the emitter adds `Content-Length` when the stream is seekable, its size is known, and both `Content-Length` and `Transfer-Encoding` are absent. For non-seekable streams it sends the remaining body without inferring its length from the total stream size. For seekable streams with known size it also adds `Accept-Ranges: bytes`. Bodies are streamed in chunks and stop early if the client disconnects.

For partial responses, the emitter sends the body already prepared by the response producer and preserves `Content-Range`. Responses with status `416` retain their explanatory body. Responses with status `304` retain `Content-Location` while emitting no body.

## Configuration

The config provider registers `Emitter` and aliases `EmitterInterface` to it. Applications normally receive the emitter from the container.

## Tests

Run `composer test`. HTTP header tests use `php-cgi` beside the active PHP executable; set `PHP_CGI_BINARY` when it is installed elsewhere. These tests are skipped if CGI is unavailable, so install CGI in CI to verify response headers as well as bodies.

## Related Packages

- [`componenta/app-http`](../app-http/README.md) uses the emitter in the HTTP runtime.
- [`componenta/http-responder`](../http-responder/README.md) builds PSR-7 responses.
