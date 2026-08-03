<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Midend;

/**
 * Ordered list of IR instructions emitted from the AST.
 */
class Program
{
    /** @var Instruction[] */
    public array $instructions = [];

    public function add(Instruction $instruction): void
    {
        $this->instructions[] = $instruction;
    }
}
