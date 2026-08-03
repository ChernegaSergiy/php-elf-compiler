<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Backend;

/**
 * Architectures that can be targeted by compiler backends.
 */
enum Architecture: string
{
    case X86 = 'x86';
    case X86_64 = 'x86_64';
    case ARM32 = 'arm32';
    case ARM64 = 'arm64';
}
