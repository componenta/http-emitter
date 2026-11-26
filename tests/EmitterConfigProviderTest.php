<?php

declare(strict_types=1);

namespace Componenta\Http\Tests;

use Componenta\Config\ConfigKey;
use Componenta\Http\Emitter;
use Componenta\Http\EmitterConfigProvider;
use Componenta\Http\EmitterInterface;
use PHPUnit\Framework\TestCase;

final class EmitterConfigProviderTest extends TestCase
{
    public function testPublicConfigurationRegistersConcreteFactoryAndInterfaceAlias(): void
    {
        $dependencies = (new EmitterConfigProvider())()[ConfigKey::DEPENDENCIES];
        $factory = $dependencies[ConfigKey::FACTORIES][Emitter::class];
        self::assertInstanceOf(Emitter::class, $factory());
        self::assertInstanceOf(EmitterInterface::class, $factory());
        self::assertSame(Emitter::class, $dependencies[ConfigKey::ALIASES][EmitterInterface::class]);
    }
}
