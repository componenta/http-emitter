<?php

declare(strict_types=1);

namespace Componenta\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * PSR-7 HTTP Response Emitter
 *
 * Automatically selects the optimal emission strategy based on response:
 * - NoBody: For 1xx, 204, 205, 304 status codes
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
    private const array BODYLESS_STATUSES = [100, 101, 102, 103, 204, 205, 304];

    /**
     * @param positive-int $chunkSize Chunk size in bytes for streaming large responses
     */
    public function __construct(
        private readonly int $chunkSize = self::DEFAULT_CHUNK_SIZE,
    ) {
        if ($chunkSize <= 0) {
            throw new \InvalidArgumentException('Chunk size must be greater than zero.');
        }
    }

    public function emit(ResponseInterface $response): void
    {
        $this->assertCanEmit();
        $this->cleanBuffers();

        match ($this->resolveStrategy($response)) {
            EmitStrategy::NoBody => $this->emitNoBody($response),
            EmitStrategy::Partial, EmitStrategy::Full => $this->emitFull($response),
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

        $contentRange = $response->getHeaderLine('Content-Range');

        // Partial content with valid Content-Range
        if ($status === 206 && $contentRange !== '') {
            return EmitStrategy::Partial;
        }

        return EmitStrategy::Full;
    }

    /**
     * Emits response without body (1xx, 204, 205, 304, etc.)
     */
    private function emitNoBody(ResponseInterface $response): void
    {
        $response = $this->stripContentHeaders($response);

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

        // Only a seekable body is emitted from the beginning, so its full size is reliable.
        if (
            $size !== null
            && $body->isSeekable()
            && !$response->hasHeader('Content-Length')
            && !$response->hasHeader('Transfer-Encoding')
        ) {
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
     * Removes body headers while retaining the representation location required for 304
     */
    private function stripContentHeaders(ResponseInterface $response): ResponseInterface
    {
        if ($response->getStatusCode() !== 304) {
            $response = $response->withoutHeader('Content-Location');
        }

        return $response
            ->withoutHeader('Content-Type')
            ->withoutHeader('Content-Length')
            ->withoutHeader('Content-Encoding')
            ->withoutHeader('Content-Language')
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
            $buffer = ob_get_status();
            $flags = $buffer['flags'] ?? null;
            if (!is_int($flags) || ($flags & PHP_OUTPUT_HANDLER_REMOVABLE) === 0) {
                throw new EmitterException('Cannot remove the active output buffer before emitting a response.');
            }
            if (!ob_end_clean()) {
                throw new EmitterException('Cannot clean the active output buffer before emitting a response.');
            }
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
