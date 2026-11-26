<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/tests/Fixture/NonSeekableStream.php';

use Componenta\Http\Emitter;
use Componenta\Http\Tests\Fixture\NonSeekableStream;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;

$scenario = getenv('COMPONENTA_BENCH_SCENARIO');
$response = match ($scenario) {
    'small' => new Response(200, [], str_repeat('x', 1024)),
    'large' => new Response(200, [], str_repeat('x', 1048576)),
    'non-seekable' => new Response(200, [], new NonSeekableStream(Stream::create(str_repeat('x', 65536)))),
    'partial' => new Response(206, ['Content-Range' => 'bytes 1024-66559/100000'], str_repeat('x', 65536)),
    'no-content' => new Response(204, [], 'discarded'),
    'head' => new Response(200, ['Content-Length' => '1048576'], ''),
    default => throw new LogicException('Unknown benchmark scenario.'),
};
(new Emitter())->emit($response);
