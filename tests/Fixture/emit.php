<?php

declare(strict_types=1);

require getenv('COMPONENTA_TEST_AUTOLOAD');
[$status, $headers, $contents, $nonSeekable, $consumed, $expectFailure, $stall, $prohibitRead] = json_decode(getenv('COMPONENTA_TEST_RESPONSE'), true, 512, JSON_THROW_ON_ERROR);
$body = \Nyholm\Psr7\Stream::create($contents);
if ($nonSeekable || $stall || $prohibitRead) {
    require __DIR__ . '/NonSeekableStream.php';
    $body = new \Componenta\Http\Tests\Fixture\NonSeekableStream($body, $stall, $prohibitRead);
}
if ($consumed > 0) {
    $body->read($consumed);
}

try {
    (new \Componenta\Http\Emitter(chunkSize: 2))->emit(new \Nyholm\Psr7\Response($status, $headers, $body));
} catch (\Componenta\Http\EmitterException $exception) {
    if (!$expectFailure || headers_sent()) {
        throw $exception;
    }
    header_remove();
    http_response_code(500);
    echo 'rejected';
}
