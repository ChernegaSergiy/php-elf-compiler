<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Backend;

use ChernegaSergiy\TrypilliaCompiler\CodeGen\X86Emitter;
use ChernegaSergiy\TrypilliaCompiler\IR\Instruction;
use ChernegaSergiy\TrypilliaCompiler\IR\Program;
use Exception;

/**
 * Emits x86_64 machine code from portable IR instructions.
 */
class X86BackendEmitter implements BackendEmitter
{
    private X86Emitter $emitter;

    /** @var array<string, int> */
    private array $labels = [];

    /** @var array<string, int[]> */
    private array $pendingJumps = [];

    public function targetArchitecture(): Architecture
    {
        return Architecture::X86_64;
    }

    public function emit(Program $program, string $filename): void
    {
        $this->emitter = new X86Emitter();
        $this->labels = [];
        $this->pendingJumps = [];

        foreach ($program->instructions as $instruction) {
            $this->emitInstruction($instruction);
        }

        if ($this->pendingJumps !== []) {
            $unresolved = implode(', ', array_keys($this->pendingJumps));
            throw new Exception("Unresolved IR labels: {$unresolved}");
        }

        $this->emitter->generateBinary($filename);
    }

    private function emitInstruction(Instruction $instruction): void
    {
        switch ($instruction->opcode) {
            case 'const_int':
                $temp = $this->requireResult($instruction);
                $value = $this->intOperand($instruction, 0);
                $this->emitter->movRaxImm($value);
                $this->emitter->storeLocal($temp);
                break;
            case 'load_var':
                $temp = $this->requireResult($instruction);
                $name = $this->stringOperand($instruction, 0);
                $this->emitter->loadLocal($name);
                $this->emitter->storeLocal($temp);
                break;
            case 'store_var':
                $name = $this->stringOperand($instruction, 0);
                $sourceTemp = $this->stringOperand($instruction, 1);
                $this->emitter->loadLocal($sourceTemp);
                $this->emitter->storeLocal($name);
                break;
            case 'add_int':
                $this->emitBinaryMath($instruction, static function (X86Emitter $emitter): void {
                    $emitter->addRaxRdx();
                });
                break;
            case 'cmp_lt':
                $this->emitBinaryCompare($instruction, static function (X86Emitter $emitter): void {
                    $emitter->emitSetlRax();
                });
                break;
            case 'cmp_eq':
                $this->emitBinaryCompare($instruction, static function (X86Emitter $emitter): void {
                    $emitter->emitSeteRax();
                });
                break;
            case 'cmp_ne':
                $this->emitBinaryCompare($instruction, static function (X86Emitter $emitter): void {
                    $emitter->emitSetneRax();
                });
                break;
            case 'not_bool':
                $result = $this->requireResult($instruction);
                $value = $this->stringOperand($instruction, 0);
                $this->emitter->loadLocal($value);
                $this->emitter->emitCmpRaxImm0();
                $this->emitter->emitSeteRax();
                $this->emitter->storeLocal($result);
                break;
            case 'print_num':
                $value = $this->stringOperand($instruction, 0);
                $this->emitter->loadLocal($value);
                $this->emitter->emitPrintNumberInRax();
                break;
            case 'print_str':
                $value = $this->stringOperand($instruction, 0);
                $this->emitter->emitPrintString($value);
                break;
            case 'jump_if_zero':
                $value = $this->stringOperand($instruction, 0);
                $label = $this->stringOperand($instruction, 1);
                $this->emitter->loadLocal($value);
                $this->emitter->emitCmpRaxImm0();
                if (isset($this->labels[$label])) {
                    throw new Exception("Backward conditional jump is not supported for label: {$label}");
                }
                $patchOffset = $this->emitter->emitJe_ForwardPlaceholder();
                $this->pendingJumps[$label][] = $patchOffset;
                break;
            case 'jump':
                $label = $this->stringOperand($instruction, 0);
                $this->emitJump($label);
                break;
            case 'label':
                $label = $this->stringOperand($instruction, 0);
                $this->defineLabel($label);
                break;
            default:
                throw new Exception('Unsupported IR opcode for x86 backend: ' . $instruction->opcode);
        }
    }

    /**
     * @param callable(X86Emitter): void $operation
     */
    private function emitBinaryMath(Instruction $instruction, callable $operation): void
    {
        $result = $this->requireResult($instruction);
        $left = $this->stringOperand($instruction, 0);
        $right = $this->stringOperand($instruction, 1);

        $this->emitter->loadLocal($right);
        $this->emitter->pushRax();
        $this->emitter->loadLocal($left);
        $this->emitter->popRdx();
        $operation($this->emitter);
        $this->emitter->storeLocal($result);
    }

    /**
     * @param callable(X86Emitter): void $setOperation
     */
    private function emitBinaryCompare(Instruction $instruction, callable $setOperation): void
    {
        $result = $this->requireResult($instruction);
        $left = $this->stringOperand($instruction, 0);
        $right = $this->stringOperand($instruction, 1);

        $this->emitter->loadLocal($right);
        $this->emitter->pushRax();
        $this->emitter->loadLocal($left);
        $this->emitter->popRdx();
        $this->emitter->emitCmpRaxRdx();
        $setOperation($this->emitter);
        $this->emitter->storeLocal($result);
    }

    private function emitJump(string $label): void
    {
        if (isset($this->labels[$label])) {
            $this->emitter->emitJmp_Backward($this->labels[$label]);

            return;
        }

        $patchOffset = $this->emitter->emitJmp_ForwardPlaceholder();
        $this->pendingJumps[$label][] = $patchOffset;
    }

    private function defineLabel(string $label): void
    {
        if (isset($this->labels[$label])) {
            throw new Exception("Duplicate IR label: {$label}");
        }

        $this->labels[$label] = $this->emitter->getCurrentOffset();

        if (!isset($this->pendingJumps[$label])) {
            return;
        }

        foreach ($this->pendingJumps[$label] as $patchOffset) {
            $this->emitter->patchForwardJump($patchOffset);
        }
        unset($this->pendingJumps[$label]);
    }

    private function requireResult(Instruction $instruction): string
    {
        if ($instruction->result === null) {
            throw new Exception('IR instruction result is required for opcode: ' . $instruction->opcode);
        }

        return $instruction->result;
    }

    private function stringOperand(Instruction $instruction, int $index): string
    {
        $value = $instruction->operands[$index] ?? null;
        if (!is_string($value)) {
            throw new Exception("Expected string operand #{$index} for opcode: {$instruction->opcode}");
        }

        return $value;
    }

    private function intOperand(Instruction $instruction, int $index): int
    {
        $value = $instruction->operands[$index] ?? null;
        if (!is_int($value)) {
            throw new Exception("Expected integer operand #{$index} for opcode: {$instruction->opcode}");
        }

        return $value;
    }
}
