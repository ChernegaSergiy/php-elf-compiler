<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Backend;

use ChernegaSergiy\TrypilliaCompiler\CodeGen\Arm32Emitter;
use ChernegaSergiy\TrypilliaCompiler\Midend\Instruction;
use ChernegaSergiy\TrypilliaCompiler\Midend\IrFunction;
use ChernegaSergiy\TrypilliaCompiler\Midend\Operand;
use ChernegaSergiy\TrypilliaCompiler\Midend\Program;
use Exception;

/**
 * Translates IR instructions into ARM32 emitter operations.
 *
 * Calling convention (AAPCS-lite):
 *   Args: r0-r3 (first 4), return: r0
 *   Callee-saved: r4-r11, Caller-saved: r0-r3, r12
 *   Prologue: push {r4-r11, lr}; mov r11, sp; sub sp, sp, #localsSize; mov r10, sp
 *   Epilogue: mov sp, r11; pop {r4-r11, pc}
 */
class Arm32BackendEmitter implements BackendEmitter
{
    private Arm32Emitter $emitter;

    /** @var string[] Parameter names in order for the current function */
    private array $currentParams = [];

    private int $argIndex = 0;

    public function targetArchitecture(): Architecture
    {
        return Architecture::ARM32;
    }

    public function emit(Program $program, string $filename): void
    {
        $this->emitter = new Arm32Emitter();

        foreach ($program->functions as $function) {
            $this->emitFunction($function);
        }

        foreach ($program->instructions as $instruction) {
            $this->emitInstruction($instruction);
        }

        $this->emitter->generateBinary($filename);
    }

    private function emitFunction(IrFunction $function): void
    {
        $this->currentParams = array_column($function->params, 'name');
        $this->argIndex = 0;

        $this->emitter->label('func_' . $function->name);

        // Prologue
        $this->emitter->pushReg('r4');
        $this->emitter->pushReg('r5');
        $this->emitter->pushReg('r6');
        $this->emitter->pushReg('r7');
        $this->emitter->pushReg('r8');
        $this->emitter->pushReg('r9');
        $this->emitter->pushReg('r10');
        $this->emitter->pushReg('r11');
        $this->emitter->pushReg('r14'); // lr
        $this->emitter->movReg('r11', 'sp');
        $this->emitter->movImm('r10', 256); // locals area size
        $this->emitter->movReg('r10', 'sp'); // r10 = sp (locals base)

        foreach ($function->instructions as $instruction) {
            $this->emitInstruction($instruction);
        }

        $this->currentParams = [];
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
            case 'bit_and':
                $this->emitBinaryBitwise($instruction, 'andReg');
                break;
            case 'bit_or':
                $this->emitBinaryBitwise($instruction, 'orrReg');
                break;
            case 'bit_xor':
                $this->emitBinaryBitwise($instruction, 'eorReg');
                break;
            case 'bit_not':
                $result = $this->requireResult($instruction);
                $value = $this->stringOperand($instruction, 0);
                $this->emitter->loadLocal($value, 'r0');
                $this->emitter->mvnReg('r0', 'r0');
                $this->emitter->storeLocal($result, 'r0');
                break;
            case 'shl':
                $this->emitShift($instruction, 'lslImm');
                break;
            case 'shr':
                $this->emitShift($instruction, 'asrImm');
                break;
            case 'shr_u':
                $this->emitShift($instruction, 'lsrImm');
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
            case 'func':
                break;
            case 'end':
                $this->emitEpilogue();
                break;
            case 'param':
                $this->emitParam($instruction);
                break;
            case 'call':
                $this->emitCall($instruction);
                break;
            case 'arg':
                $this->emitArg($instruction);
                break;
            case 'ret':
                $this->emitRet($instruction);
                break;
            default:
                throw new Exception('Unsupported IR opcode for ARM32 backend: ' . $instruction->opcode);
        }
    }

    private function emitParam(Instruction $instruction): void
    {
        if ($this->argIndex < 4) {
            $reg = "r{$this->argIndex}";
            $this->emitter->storeLocal($this->stringOperand($instruction, 0), $reg);
        }
        $this->argIndex++;
    }

    private function emitArg(Instruction $instruction): void
    {
        if ($this->argIndex < 4) {
            $reg = "r{$this->argIndex}";
            $name = $this->stringOperand($instruction, 0);
            $this->emitter->loadLocal($name, $reg);
        }
        $this->argIndex++;
    }

    private function emitCall(Instruction $instruction): void
    {
        $name = $this->stringOperand($instruction, 0);
        $this->argIndex = 0;
        $this->emitter->branchLink('func_' . $name);

        $result = $instruction->result;
        if ($result !== null) {
            $this->emitter->storeLocal($result, 'r0');
        }
    }

    private function emitRet(Instruction $instruction): void
    {
        if (isset($instruction->operands[0])) {
            $name = $this->stringOperand($instruction, 0);
            $this->emitter->loadLocal($name, 'r0');
        }
        $this->emitEpilogue();
    }

    private function emitEpilogue(): void
    {
        // Epilogue: mov sp, r11; pop {r4-r11, pc}
        $this->emitter->movReg('sp', 'r11');
        $this->emitter->popReg('r14'); // lr
        $this->emitter->popReg('r11');
        $this->emitter->popReg('r10');
        $this->emitter->popReg('r9');
        $this->emitter->popReg('r8');
        $this->emitter->popReg('r7');
        $this->emitter->popReg('r6');
        $this->emitter->popReg('r5');
        $this->emitter->popReg('r4');
        $this->emitter->ret();
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

    private function emitBinaryBitwise(Instruction $instruction, string $method): void
    {
        $result = $this->requireResult($instruction);
        $left = $this->stringOperand($instruction, 0);
        $right = $this->stringOperand($instruction, 1);

        $this->emitter->loadLocal($left, 'r0');
        $this->emitter->loadLocal($right, 'r1');
        $this->emitter->$method('r0', 'r0', 'r1');
        $this->emitter->storeLocal($result, 'r0');
    }

    private function emitShift(Instruction $instruction, string $method): void
    {
        $result = $this->requireResult($instruction);
        $source = $this->stringOperand($instruction, 0);
        $amount = $this->intOperand($instruction, 1);

        $this->emitter->loadLocal($source, 'r0');
        $this->emitter->$method('r0', 'r0', $amount);
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
        $operand = $instruction->operands[$index] ?? null;
        if (!$operand instanceof Operand || !$operand->isTemp()) {
            throw new Exception("Expected string operand #{$index} for opcode: {$instruction->opcode}");
        }

        return (string) $operand->value;
    }

    private function intOperand(Instruction $instruction, int $index): int
    {
        $operand = $instruction->operands[$index] ?? null;
        if (!$operand instanceof Operand || !$operand->isLiteral()) {
            throw new Exception("Expected integer operand #{$index} for opcode: {$instruction->opcode}");
        }

        return $operand->value;
    }
}
