<?php

declare(strict_types=1);

namespace Componenta\Http;

use RuntimeException;

/**
 * Exception thrown when response emission fails
 *
 * Common causes:
 * - Headers already sent (output started before emit)
 * - Output buffer conflicts
 */
final class EmitterException extends RuntimeException {}