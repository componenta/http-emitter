<?php

declare(strict_types=1);

namespace Componenta\Http;

use Psr\Http\Message\ResponseInterface;

/** Sends a prepared PSR-7 response to the current HTTP transport. */
interface EmitterInterface
{
    /**
     * Emits status, headers and permitted content.
     *
     * The producer owns representation semantics, including range selection.
     * Implementations must not emit content for bodyless statuses.
     *
     * @throws EmitterException If emission fails. Once headers or content have
     * been sent, callers must not attempt to emit a replacement response.
     */
    public function emit(ResponseInterface $response): void;
}
