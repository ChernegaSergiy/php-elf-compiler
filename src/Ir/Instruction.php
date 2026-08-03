<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\IR;

/**
 * A single three-address style IR instruction.
 */
class Instruction
{
    /**
     * @param array<int, int|string> $operands
     */
    public function __construct(
        public string $opcode,
        public ?string $result = null,
        public array $operands = [],
    ) {
    }
}
