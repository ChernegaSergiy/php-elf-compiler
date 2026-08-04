<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Midend;

/**
 * Ordered list of IR instructions emitted from the AST.
 *
 * Functions are stored separately from the main instruction stream.
 * Each function's instructions include `param`, body instructions,
 * and a `ret` at the end. The main stream follows after all functions.
 */
class Program
{
    /** @var Instruction[] */
    public array $instructions = [];

    /** @var array<string, Instruction[]> Function name → instruction list */
    public array $functions = [];

    public function add(Instruction $instruction): void
    {
        if ($this->currentFunction !== null) {
            $this->functions[$this->currentFunction][] = $instruction;
        } else {
            $this->instructions[] = $instruction;
        }
    }

    /**
     * Start a new function. Subsequent add() calls go into the function
     * until endFunction() is called.
     */
    public function beginFunction(string $name): void
    {
        $this->currentFunction = $name;
        $this->functions[$name] = [];
    }

    public function endFunction(): void
    {
        $this->currentFunction = null;
    }

    public function isInsideFunction(): bool
    {
        return $this->currentFunction !== null;
    }

    /**
     * @var string|null
     */
    private ?string $currentFunction = null;
}
