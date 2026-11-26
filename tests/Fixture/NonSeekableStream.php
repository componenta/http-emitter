<?php

declare(strict_types=1);

namespace Componenta\Http\Tests\Fixture;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class NonSeekableStream implements StreamInterface
{
    private int $reads = 0;
    public function __construct(private readonly StreamInterface $stream, private readonly bool $stall = false, private readonly bool $prohibitRead = false) {}

    public function __toString(): string { return $this->stream->getContents(); }
    public function close(): void { $this->stream->close(); }
    public function detach() { return $this->stream->detach(); }
    public function getSize(): ?int { return $this->stream->getSize(); }
    public function tell(): int { return $this->stream->tell(); }
    public function eof(): bool { return $this->prohibitRead ? false : $this->stream->eof(); }
    public function isSeekable(): bool { return false; }
    public function seek(int $offset, int $whence = SEEK_SET): void { throw new RuntimeException('Stream is not seekable.'); }
    public function rewind(): void { throw new RuntimeException('Stream is not seekable.'); }
    public function isWritable(): bool { return $this->stream->isWritable(); }
    public function write(string $string): int { return $this->stream->write($string); }
    public function isReadable(): bool { return $this->stream->isReadable(); }
    public function read(int $length): string
    {
        if ($this->prohibitRead) {
            throw new RuntimeException('HEAD must not read this stream.');
        }
        if ($this->stall && $this->reads++ === 0) {
            return '';
        }
        return $this->stream->read($length);
    }
    public function getContents(): string { return $this->stream->getContents(); }
    public function getMetadata(?string $key = null): mixed { return $this->stream->getMetadata($key); }
}
