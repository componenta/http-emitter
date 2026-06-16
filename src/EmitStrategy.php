<?php

declare(strict_types=1);

namespace Componenta\Http;

/**
 * HTTP response emission strategies
 *
 * Determines how the emitter handles the response body.
 */
enum EmitStrategy
{
    /**
     * Full body emission with chunked streaming
     *
     * Used for standard responses where the complete body
     * should be sent to the client.
     */
    case Full;

    /**
     * Partial content emission (HTTP 206)
     *
     * Used when Content-Range header is present,
     * typically for resumable downloads and video streaming.
     */
    case Partial;

    /**
     * Headers only, no body
     *
     * Used for:
     * - 1xx Informational responses
     * - 204 No Content
     * - 304 Not Modified
     * - 416 Range Not Satisfiable
     * - HEAD requests (when body is empty)
     */
    case NoBody;
}