<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\CodeGen;

use Exception;

/**
 * Collects AArch64 codegen operations that will later be lowered to ELF64 bytes.
 */
class Arm64Emitter
{
    /** @var array<int, array{opcode: string, operands: array<int, int|string>}> */
    private array $operations = [];

    /** @var array<string, int> */
    private array $locals = [];

    private int $stackOffset = 0;

    public function movImm(string $register, int $value): void
    {
        $this->operations[] = ['opcode' => 'mov_imm', 'operands' => [$register, $value]];
    }

    public function loadLocal(string $name, string $register): void
    {
        $offset = $this->locals[$name] ?? throw new Exception("Unknown variable: {$name}");
        $this->operations[] = ['opcode' => 'load_local', 'operands' => [$register, $offset]];
    }

    public function storeLocal(string $name, string $register): void
    {
        if (!isset($this->locals[$name])) {
            $this->stackOffset += 8;
            $this->locals[$name] = -$this->stackOffset;
        }

        $this->operations[] = ['opcode' => 'store_local', 'operands' => [$register, $this->locals[$name]]];
    }

    public function add(string $destination, string $left, string $right): void
    {
        $this->operations[] = ['opcode' => 'add', 'operands' => [$destination, $left, $right]];
    }

    public function cmp(string $left, string $right): void
    {
        $this->operations[] = ['opcode' => 'cmp', 'operands' => [$left, $right]];
    }

    public function cset(string $destination, string $condition): void
    {
        $this->operations[] = ['opcode' => 'cset', 'operands' => [$destination, $condition]];
    }

    public function label(string $name): void
    {
        $this->operations[] = ['opcode' => 'label', 'operands' => [$name]];
    }

    public function branch(string $label): void
    {
        $this->operations[] = ['opcode' => 'branch', 'operands' => [$label]];
    }

    public function branchIfZero(string $register, string $label): void
    {
        $this->operations[] = ['opcode' => 'branch_if_zero', 'operands' => [$register, $label]];
    }

    public function printNumber(string $register): void
    {
        $this->operations[] = ['opcode' => 'print_num', 'operands' => [$register]];
    }

    public function printString(string $value): void
    {
        $this->operations[] = ['opcode' => 'print_str', 'operands' => [$value]];
    }

    public function generateBinary(string $filename): void
    {
        throw new Exception('ARM64 machine code emission is not implemented yet.');
    }
}
