<?php

declare(strict_types=1);

namespace Componenta\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/** Emits a prepared PSR-7 response through PHP's native HTTP SAPI. */
final class Emitter implements EmitterInterface
{
    private const int DEFAULT_CHUNK_SIZE = 8192;

    public function __construct(
        private readonly int $chunkSize = self::DEFAULT_CHUNK_SIZE,
    ) {
        if ($chunkSize <= 0) {
            throw new \InvalidArgumentException('Chunk size must be greater than zero.');
        }
    }

    #[\Override]
    public function emit(ResponseInterface $response): void
    {
        $this->assertCanEmit();

        $status = $response->getStatusCode();
        $strategy = $this->resolveStrategy($response);
        $method = $_SERVER['REQUEST_METHOD'] ?? null;
        $head = $method === 'HEAD';
        if ($method === 'CONNECT' && $status >= 200 && $status < 300) {
            throw new EmitterException('A successful CONNECT requires a tunnel-capable HTTP transport.');
        }
        $body = null;
        $length = null;

        if ($strategy === EmitStrategy::NoBody) {
            $response = $this->prepareBodylessResponse($response);
        } else {
            if ($response->hasHeader('Transfer-Encoding') || $response->hasHeader('Trailer')) {
                throw new EmitterException('Transfer coding and trailers belong to the HTTP transport, not the response producer.');
            }

            $body = $response->getBody();
            $length = $this->contentLength($response);
            $size = $body->isSeekable() ? $body->getSize() : null;
            $offloaded = $response->hasHeader('X-Accel-Redirect') || $response->hasHeader('X-Sendfile');

            if (!$head) {
                if (!$body->isReadable()) {
                    throw new EmitterException('Response body is not readable.');
                }
                // A seekable stream represents the whole body. Rewind before
                // sending headers so an unusable stream cannot commit a response.
                if ($body->isSeekable()) {
                    try {
                        $body->rewind();
                    } catch (\Throwable $exception) {
                        throw new EmitterException('Could not rewind the response body.', previous: $exception);
                    }
                }
                if ($length !== null && $size !== null && $length !== $size) {
                    throw new EmitterException('Content-Length does not match the response body size.');
                }
            }
            if ($length === null && $size !== null && !$offloaded && (!$head || $size > 0)) {
                $length = $size;
                $response = $response->withHeader('Content-Length', (string) $size);
            } elseif ($length !== null) {
                // Canonical decimal form, including valid leading-zero inputs.
                $response = $response->withHeader('Content-Length', (string) $length);
            }
        }

        $this->validateMetadata($response);
        $this->cleanBuffers();
        $this->assertCanEmit();

        // The final PSR-7 response owns framing, CGI control fields and
        // representation metadata. Preserve unrelated native headers (e.g.
        // Set-Cookie from session handling), not stale fields for a prior body.
        foreach ([
            'Content-Length', 'Transfer-Encoding', 'Trailer', 'Status', 'Location',
            'Content-Type', 'Content-Encoding', 'Content-Language', 'Content-Location',
            'Content-Range', 'Content-Disposition', 'Content-MD5', 'Content-Digest',
            'Repr-Digest', 'Accept-Ranges', 'ETag', 'Last-Modified',
            'X-Accel-Redirect', 'X-Sendfile', 'X-Accel-Limit-Rate',
        ] as $name) {
            header_remove($name);
        }

        // An explicit final Cache-Control policy must not inherit an old
        // nginx cache lifetime, which can take precedence over Cache-Control.
        // Keep native cache defaults when the response supplies no policy.
        if ($response->hasHeader('Cache-Control')) {
            header_remove('X-Accel-Expires');
            header_remove('Expires');
        }

        $this->emitHeaders($response);
        // PHP can implicitly change the status when setting Location. Apply the
        // final response's status after the fields, before emitting any bytes.
        header(sprintf(
            'HTTP/%s %d %s',
            $response->getProtocolVersion(),
            $status,
            $response->getReasonPhrase(),
        ), true, $status);

        if ($body !== null && !$head) {
            $this->emitBody($body, $length);
        }
    }

    private function resolveStrategy(ResponseInterface $response): EmitStrategy
    {
        $status = $response->getStatusCode();
        if ($status < 200 || in_array($status, [204, 205, 304], true)) {
            return EmitStrategy::NoBody;
        }

        return $status === 206 && $response->getHeaderLine('Content-Range') !== ''
            ? EmitStrategy::Partial
            : EmitStrategy::Full;
    }

    private function prepareBodylessResponse(ResponseInterface $response): ResponseInterface
    {
        $status = $response->getStatusCode();
        $response = $response
            ->withoutHeader('Transfer-Encoding')
            ->withoutHeader('Trailer')
            ->withoutHeader('Content-Type')
            ->withoutHeader('Content-Encoding')
            ->withoutHeader('Content-Language')
            ->withoutHeader('Content-MD5')
            ->withoutHeader('Content-Range');

        if ($status !== 304) {
            $response = $response->withoutHeader('Content-Location')->withoutHeader('Content-Length');
        }
        if ($status === 205) {
            return $response->withHeader('Content-Length', '0');
        }
        if ($status === 304 && ($length = $this->contentLength($response)) !== null) {
            // In a 304, an explicit length describes the selected 200
            // representation, not the discarded body. The producer owns it.
            $response = $response->withHeader('Content-Length', (string) $length);
        }

        return $response;
    }

    private function contentLength(ResponseInterface $response): ?int
    {
        if (!$response->hasHeader('Content-Length')) {
            return null;
        }
        $values = $response->getHeader('Content-Length');
        if (count($values) !== 1 || preg_match('/\A[0-9]+\z/', $values[0]) !== 1) {
            throw new EmitterException('Content-Length must be one decimal integer.');
        }
        $digits = ltrim($values[0], '0');
        if ($digits === '') {
            return 0;
        }
        $max = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            throw new EmitterException('Content-Length exceeds the supported integer range.');
        }

        return (int) $digits;
    }

    private function validateMetadata(ResponseInterface $response): void
    {
        if ($response->getStatusCode() < 100 || $response->getStatusCode() > 599
            || preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/', $response->getProtocolVersion()) !== 1
            || preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $response->getReasonPhrase()) === 1) {
            throw new EmitterException('Invalid HTTP response status line.');
        }

        foreach ($response->getHeaders() as $name => $values) {
            $field = strtolower($name);
            if ($field === 'status') {
                throw new EmitterException('CGI Status is reserved; use the PSR-7 response status code.');
            }
            if (in_array($field, ['content-type', 'location'], true) && count($values) !== 1) {
                throw new EmitterException('CGI response fields must not be repeated: ' . $name);
            }
            if (preg_match("@^[!#$%&'*+.^_\x60|~0-9A-Za-z-]+$@D", $name) !== 1) {
                throw new EmitterException('Invalid HTTP response field name.');
            }
            foreach ($values as $value) {
                if (preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value) === 1) {
                    throw new EmitterException('Invalid HTTP response field value.');
                }
            }
        }
    }

    private function emitHeaders(ResponseInterface $response): void
    {
        foreach ($response->getHeaders() as $name => $values) {
            // Preserve cookies already staged by PHP session handling.
            $replace = strtolower($name) !== 'set-cookie';
            foreach ($values as $value) {
                header("{$name}: {$value}", $replace);
                $replace = false;
            }
        }
    }

    private function emitBody(StreamInterface $body, ?int $remaining): void
    {
        try {
            while (!$body->eof()) {
                // At most one excess byte is read when enforcing a declared
                // length. Never emit bytes outside the declared message frame.
                $limit = $remaining !== null && $remaining < $this->chunkSize
                    ? $remaining + 1
                    : $this->chunkSize;
                $chunk = $body->read($limit);
                if ($chunk === '') {
                    // EOF can change as a consequence of read(). Inspect it
                    // after leaving the loop instead of assuming progress.
                    break;
                }
                if ($remaining !== null) {
                    $length = strlen($chunk);
                    if ($length > $remaining) {
                        throw new EmitterException('Response body exceeds Content-Length.');
                    }
                    $remaining -= $length;
                }

                echo $chunk;
                if (connection_aborted()) {
                    return;
                }
                flush();
            }
            if (!$body->eof()) {
                throw new EmitterException('Response body stream made no progress.');
            }
        } catch (EmitterException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new EmitterException('Could not read the response body.', previous: $exception);
        }
        if ($remaining !== null && $remaining !== 0 && !connection_aborted()) {
            throw new EmitterException('Response body ended before Content-Length was satisfied.');
        }
    }

    private function cleanBuffers(): void
    {
        // Check the entire stack before discarding any buffer.
        foreach (ob_get_status(true) as $buffer) {
            $flags = is_array($buffer) ? ($buffer['flags'] ?? null) : null;
            if (!is_int($flags) || ($flags & PHP_OUTPUT_HANDLER_REMOVABLE) === 0) {
                throw new EmitterException('Cannot remove an active output buffer before emitting a response.');
            }
        }
        while (ob_get_level() > 0) {
            try {
                $cleaned = ob_end_clean();
            } catch (\Throwable $exception) {
                throw new EmitterException('Output buffer handler failed before response emission.', previous: $exception);
            }
            if (!$cleaned) {
                throw new EmitterException('Cannot clean the active output buffer before emitting a response.');
            }
        }
    }

    private function assertCanEmit(): void
    {
        if (headers_sent($file, $line)) {
            throw new EmitterException("Headers already sent in $file:$line");
        }
    }
}
