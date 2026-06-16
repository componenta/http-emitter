<?php

declare(strict_types=1);

namespace Componenta\Http;

class EmitterConfigProvider extends \Componenta\Config\ConfigProvider
{
    protected function getFactories(): array
    {
        return [
            Emitter::class => static fn(): EmitterInterface => new Emitter(),
        ];
    }

    protected function getAliases(): array
    {
        return [
            EmitterInterface::class => Emitter::class,
        ];
    }
}
