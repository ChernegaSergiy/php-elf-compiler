<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Midend;

/**
 * Ordered list of IR instructions emitted from the AST.
 *
 * Functions are stored separately from the main instruction stream.
 * Each function is wrapped in a Function object containing its name,
 * parameter list, body instructions, and return type.
 * The main instruction stream follows after all function definitions.
 */
class Program
{
    /** @var Instruction[] */
    public array $instructions = [];

    /** @var array<string, Function> Function name → Function wrapper */
    public array $functions = [];

    public function add(Instruction $instruction): void
    {
        if ($this->currentFunction !== null) {
            $this->functions[$this->currentFunction]->instructions[] = $instruction;
        } else {
            $this->instructions[] = $instruction;
        }
    }

    /**
     * Start a new function. Subsequent add() calls go into the function
     * until endFunction() is called.
     *
     * @param array<int, array{name: string, type: string}> $params
     */
    public function beginFunction(string $name, array $params = [], string $returnType = 'void'): void
    {
        $this->currentFunction = $name;
        $this->functions[$name] = new Function($name, $params, [], $returnType);
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
