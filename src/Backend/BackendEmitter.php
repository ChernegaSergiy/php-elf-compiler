<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Backend;

use ChernegaSergiy\TrypilliaCompiler\Ir\Program;

/**
 * Emits a native binary for a specific architecture from a portable IR program.
 */
interface BackendEmitter
{
    public function targetArchitecture(): Architecture;

    public function emit(Program $program, string $filename): void;
}
