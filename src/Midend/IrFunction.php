<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Midend;

/**
 * Wraps per-function IR: name, parameter list, body instructions,
 * and return type. Used by Program to hold multiple functions
 * separately from the main instruction stream.
 */
class IrFunction
{
    /**
     * @param string $name
     * @param array<int, array{name: string, type: string}> $params
     * @param Instruction[] $instructions
     * @param string $returnType
     */
    public function __construct(
        public string $name,
        public array $params,
        public array $instructions,
        public string $returnType = 'void',
    ) {}
}
