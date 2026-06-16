<?php

declare(strict_types=1);

namespace Componenta\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * PSR-7 HTTP Response Emitter Interface
 *
 * Implementations are responsible for sending HTTP responses to the client,
 * including status line, headers, and body content.
 */
interface EmitterInterface
{
    /**
     * Emits a PSR-7 response to the client
     *
     * The emitter automatically determines the optimal emission strategy
     * based on the response status code and headers:
     *
     * - NoBody: For 1xx, 204, 304, 416 status codes
     * - Partial: For 206 status with Content-Range header
     * - Full: For all other responses with complete body streaming
     *
     * @param ResponseInterface $response PSR-7 response to emit
     * @throws EmitterException If headers have already been sent
     */
    public function emit(ResponseInterface $response): void;
}