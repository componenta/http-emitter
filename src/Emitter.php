<?php

declare(strict_types=1);

namespace Componenta\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * PSR-7 HTTP Response Emitter
 *
 * Automatically selects the optimal emission strategy based on response:
 * - NoBody: For 1xx, 204, 304, 416 status codes
 * - Partial: For 206 status with Content-Range header
 * - Full: For all other responses
 *
 * Features:
 * - Chunked streaming for large responses
 * - Proper handling of Content-Range for partial content
 * - Automatic Content-Length and Accept-Ranges headers
 * - Connection abort detection
 * - Output buffer cleanup
 *
 * @example
 * ```php
 * $emitter = new Emitter();
 * $emitter->emit($response);
 *
 * // With custom chunk size for streaming
 * $emitter = new Emitter(chunkSize: 16384);
 * ```
 */
final class Emitter implements EmitterInterface
{
    private const int DEFAULT_CHUNK_SIZE = 8192;

    /** @var list<int> HTTP status codes that must not include a message body */
    private const array BODYLESS_STATUSES = [100, 101, 102, 103, 204, 304, 416];

    /**
     * @param int $chunkSize Chunk size in bytes for streaming large responses
     */
    public function __construct(
        private readonly int $chunkSize = self::DEFAULT_CHUNK_SIZE,
    ) {}

    public function emit(ResponseInterface $response): void
    {
        $this->assertCanEmit();
        $this->cleanBuffers();

        match ($this->resolveStrategy($response)) {
            EmitStrategy::NoBody => $this->emitNoBody($response),
            EmitStrategy::Partial => $this->emitPartial($response),
            EmitStrategy::Full => $this->emitFull($response),
        };
    }

    /**
     * Determines the emission strategy based on response status and headers
     */
    private function resolveStrategy(ResponseInterface $response): EmitStrategy
    {
        $status = $response->getStatusCode();

        // Informational and special status codes without body
        if (in_array($status, self::BODYLESS_STATUSES, true) || $status < 200) {
            return EmitStrategy::NoBody;
        }

        // Unsatisfiable range: "bytes */1000"
        $contentRange = $response->getHeaderLine('Content-Range');
        if ($contentRange !== '' && str_starts_with($contentRange, 'bytes */')) {
            return EmitStrategy::NoBody;
        }

        // Partial content with valid Content-Range
        if ($status === 206 && $contentRange !== '') {
            return EmitStrategy::Partial;
        }

        return EmitStrategy::Full;
    }

    /**
     * Emits response without body (1xx, 204, 304, etc.)
     */
    private function emitNoBody(ResponseInterface $response): void
    {
        // 416 keeps Content-Range header per RFC 7233
        if ($response->getStatusCode() !== 416) {
            $response = $this->stripContentHeaders($response);
        } else {
            $response = $response
                ->withoutHeader('Content-Type')
                ->withoutHeader('Content-Encoding')
                ->withoutHeader('Content-Language')
                ->withoutHeader('Content-Location')
                ->withoutHeader('Content-MD5')
                ->withHeader('Content-Length', '0');
        }

        $this->emitStatusLine($response);
        $this->emitHeaders($response);
    }

    /**
     * Emits complete response body
     */
    private function emitFull(ResponseInterface $response): void
    {
        $body = $response->getBody();
        $size = $body->getSize();

        // Add Content-Length if size is known and header is missing
        if ($size !== null && !$response->hasHeader('Content-Length')) {
            $response = $response->withHeader('Content-Length', (string) $size);
        }

        // Advertise range support for seekable streams with known size
        if (
            !$response->hasHeader('Accept-Ranges')
            && $size !== null
            && $body->isSeekable()
        ) {
            $response = $response->withHeader('Accept-Ranges', 'bytes');
        }

        $this->emitStatusLine($response);
        $this->emitHeaders($response);
        $this->emitBody($body);
    }

    /**
     * Emits partial content (206) with range support
     */
    private function emitPartial(ResponseInterface $response): void
    {
        $body = $response->getBody();
        $contentRange = $response->getHeaderLine('Content-Range');
        $range = $this->parseContentRange($contentRange);

        // Add Content-Length for the range per RFC 7233
        if ($range !== null && !$response->hasHeader('Content-Length')) {
            $response = $response->withHeader('Content-Length', (string) $range['length']);
        }

        if (!$response->hasHeader('Accept-Ranges')) {
            $response = $response->withHeader('Accept-Ranges', 'bytes');
        }

        $this->emitStatusLine($response);
        $this->emitHeaders($response);

        // Emit only the specified range if stream is seekable
        if ($range !== null && $body->isSeekable()) {
            $this->emitBodyRange($body, $range['start'], $range['length']);
        } else {
            $this->emitBody($body);
        }
    }

    /**
     * Parses Content-Range header value
     *
     * @return array{start: int, length: int}|null Parsed range or null if invalid
     */
    private function parseContentRange(string $header): ?array
    {
        // Format: "bytes 0-499/1000" or "bytes */1000" (unsatisfiable)
        if (!preg_match('/^bytes (\d+)-(\d+)\/(\d+|\*)$/', $header, $matches)) {
            return null;
        }

        $start = (int) $matches[1];
        $end = (int) $matches[2];

        // Validate range logic
        if ($end < $start) {
            return null;
        }

        return [
            'start' => $start,
            'length' => $end - $start + 1,
        ];
    }

    /**
     * Emits HTTP status line
     */
    private function emitStatusLine(ResponseInterface $response): void
    {
        header(sprintf(
            'HTTP/%s %d %s',
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            $response->getReasonPhrase(),
        ), true, $response->getStatusCode());
    }

    /**
     * Emits all response headers
     *
     * Handles multiple values per header and special Set-Cookie behavior
     */
    private function emitHeaders(ResponseInterface $response): void
    {
        foreach ($response->getHeaders() as $name => $values) {
            // Set-Cookie headers must never replace each other
            $replace = strtolower($name) !== 'set-cookie';

            foreach ($values as $value) {
                header("{$name}: {$value}", $replace);
                $replace = false;
            }
        }
    }

    /**
     * Emits complete body in chunks
     */
    private function emitBody(StreamInterface $body): void
    {
        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (!$body->eof()) {
            echo $body->read($this->chunkSize);

            if (connection_aborted()) {
                break;
            }

            $this->flush();
        }
    }

    /**
     * Emits a specific byte range from the body
     *
     * @param int $start Starting byte position
     * @param int $length Number of bytes to emit
     */
    private function emitBodyRange(StreamInterface $body, int $start, int $length): void
    {
        $body->seek($start);
        $remaining = $length;

        while ($remaining > 0 && !$body->eof()) {
            $chunk = $body->read(min($this->chunkSize, $remaining));
            $remaining -= strlen($chunk);

            echo $chunk;

            if (connection_aborted()) {
                break;
            }

            $this->flush();
        }
    }

    /**
     * Removes all content-related headers
     */
    private function stripContentHeaders(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withoutHeader('Content-Type')
            ->withoutHeader('Content-Length')
            ->withoutHeader('Content-Encoding')
            ->withoutHeader('Content-Language')
            ->withoutHeader('Content-Location')
            ->withoutHeader('Content-MD5')
            ->withoutHeader('Content-Range');
    }

    /**
     * Flushes output buffers to send data immediately
     */
    private function flush(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }

    /**
     * Cleans all output buffers before emitting
     */
    private function cleanBuffers(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    /**
     * Ensures headers have not been sent yet
     *
     * @throws EmitterException If headers were already sent
     */
    private function assertCanEmit(): void
    {
        if (headers_sent($file, $line)) {
            throw new EmitterException("Headers already sent in $file:$line");
        }
    }
}