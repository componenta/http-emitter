# Upgrading to 2.x

The `EmitterInterface::emit(ResponseInterface $response): void` signature and `Emitter` constructor remain unchanged. The following observable behavior changes require review before upgrading.

- PHP 8.4 and 8.5 are supported, with Componenta Config 3 or 4.
- The emitter sends the already prepared response body. It never infers range support; producers must set `Accept-Ranges` only when they implement ranges, and supply an appropriate body and `Content-Range` for 206.
- For content responses, a seekable stream is rewound and emitted from the beginning. Non-seekable streams are emitted from the current position. Explicit `Content-Length` must be a single decimal integer, must agree with a known seekable size, and is checked against emitted bytes. Invalid framing or stalled reads throw `EmitterException`.
- Transport coding and trailers are the HTTP server's responsibility. Explicit `Transfer-Encoding` and `Trailer` on content responses are rejected. Do not send pre-chunked content.
- Native SAPI HEAD responses suppress body reads. Informational, 204, 205 and 304 responses have no emitted body. 205 uses length zero; 304 preserves an explicit valid representation length. Successful CONNECT requires a tunnel-capable transport and is not supported.
- The final response controls framing, representation metadata, validators, redirect and file-offload fields. Earlier native PHP values of those fields are cleared. Unrelated native fields, including session cookies, are retained. An explicit final `Cache-Control` replaces previously staged expiration overrides.
- `Status` is reserved for CGI; set the PSR-7 response status instead. `Content-Type` and `Location` cannot contain multiple field values. File offload responses do not acquire an inferred zero length.
- Non-removable output buffers are rejected before removing any buffers. Output-buffer callback and body-read exceptions are wrapped in `EmitterException` with their causes. Never attempt a replacement response after headers or content have been sent.

Use an actual SAPI emitter only at the outer HTTP entry point. The package is not a raw-socket, tunnel, or RoadRunner transport.
