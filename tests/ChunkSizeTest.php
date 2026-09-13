<?php

declare(strict_types=1);

namespace Componenta\Http\Tests;

use Componenta\Http\Emitter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChunkSizeTest extends TestCase
{
    public static function invalidSizes(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[DataProvider('invalidSizes')]
    public function testRejectsNonPositiveChunkSizes(int $size): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Emitter(chunkSize: $size);
    }
}
