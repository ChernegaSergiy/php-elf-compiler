<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Backend;

use ChernegaSergiy\TrypilliaCompiler\CodeGen\Arm32Emitter;
use ChernegaSergiy\TrypilliaCompiler\Midend\Instruction;
use ChernegaSergiy\TrypilliaCompiler\Midend\Program;
use Exception;

/**
 * Translates IR instructions into ARM32 emitter operations.
 */
class Arm32BackendEmitter implements BackendEmitter
{
    private Arm32Emitter $emitter;

    public function targetArchitecture(): Architecture
    {
        return Architecture::ARM32;
    }

    public function emit(Program $program, string $filename): void
    {
        $this->emitter = new Arm32Emitter();

        foreach ($program->instructions as $instruction) {
            $this->emitInstruction($instruction);
        }

        $this->emitter->generateBinary($filename);
    }

    private function emitInstruction(Instruction $instruction): void
    {
        switch ($instruction->opcode) {
            case 'const_int':
                $result = $this->requireResult($instruction);
                $value = $this->intOperand($instruction, 0);
                $this->emitter->movImm('r0', $value);
                $this->emitter->storeLocal($result, 'r0');
                break;
            case 'load_var':
                $result = $this->requireResult($instruction);
                $name = $this->stringOperand($instruction, 0);
                $this->emitter->loadLocal($name, 'r0');
                $this->emitter->storeLocal($result, 'r0');
                break;
            case 'store_var':
                $name = $this->stringOperand($instruction, 0);
                $source = $this->stringOperand($instruction, 1);
                $this->emitter->loadLocal($source, 'r0');
                $this->emitter->storeLocal($name, 'r0');
                break;
            case 'add_int':
                $this->emitBinaryMath($instruction);
                break;
            case 'cmp_lt':
                $this->emitBinaryCompare($instruction, 'lt');
                break;
            case 'cmp_eq':
                $this->emitBinaryCompare($instruction, 'eq');
                break;
            case 'cmp_ne':
                $this->emitBinaryCompare($instruction, 'ne');
                break;
            case 'not_bool':
                $result = $this->requireResult($instruction);
                $value = $this->stringOperand($instruction, 0);
                $this->emitter->loadLocal($value, 'r0');
                $this->emitter->movImm('r1', 0);
                $this->emitter->cmp('r0', 'r1');
                $this->emitter->moveConditionFlag('r0', 'eq');
                $this->emitter->storeLocal($result, 'r0');
                break;
            case 'print_num':
                $value = $this->stringOperand($instruction, 0);
                $this->emitter->loadLocal($value, 'r0');
                $this->emitter->printNumber('r0');
                break;
            case 'print_str':
                $value = $this->stringOperand($instruction, 0);
                $this->emitter->printString($value);
                break;
            case 'jump_if_zero':
                $value = $this->stringOperand($instruction, 0);
                $label = $this->stringOperand($instruction, 1);
                $this->emitter->loadLocal($value, 'r0');
                $this->emitter->branchIfZero('r0', $label);
                break;
            case 'jump':
                $label = $this->stringOperand($instruction, 0);
                $this->emitter->branch($label);
                break;
            case 'label':
                $label = $this->stringOperand($instruction, 0);
                $this->emitter->label($label);
                break;
            default:
                throw new Exception('Unsupported IR opcode for ARM32 backend: ' . $instruction->opcode);
        }
    }

    private function emitBinaryMath(Instruction $instruction): void
    {
        $result = $this->requireResult($instruction);
        $left = $this->stringOperand($instruction, 0);
        $right = $this->stringOperand($instruction, 1);

        $this->emitter->loadLocal($left, 'r0');
        $this->emitter->loadLocal($right, 'r1');
        $this->emitter->add('r0', 'r0', 'r1');
        $this->emitter->storeLocal($result, 'r0');
    }

    private function emitBinaryCompare(Instruction $instruction, string $condition): void
    {
        $result = $this->requireResult($instruction);
        $left = $this->stringOperand($instruction, 0);
        $right = $this->stringOperand($instruction, 1);

        $this->emitter->loadLocal($left, 'r0');
        $this->emitter->loadLocal($right, 'r1');
        $this->emitter->cmp('r0', 'r1');
        $this->emitter->moveConditionFlag('r0', $condition);
        $this->emitter->storeLocal($result, 'r0');
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
