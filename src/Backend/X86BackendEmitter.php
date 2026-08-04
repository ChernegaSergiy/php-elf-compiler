<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Backend;

use ChernegaSergiy\TrypilliaCompiler\CodeGen\X86Emitter;
use ChernegaSergiy\TrypilliaCompiler\Midend\Instruction;
use ChernegaSergiy\TrypilliaCompiler\Midend\IrFunction;
use ChernegaSergiy\TrypilliaCompiler\Midend\Operand;
use ChernegaSergiy\TrypilliaCompiler\Midend\Program;
use Exception;

/**
 * Emits x86_64 machine code from portable IR instructions.
 *
 * Calling convention (SysV AMD64 ABI):
 *   Args: rdi, rsi, rdx, rcx, r8, r9 (first 6)
 *   Return: rax
 *   Callee-saved: rbx, rbp, r12-r15
 *   Frame pointer: rbp
 *   Prologue: push rbp; mov rbp, rsp; sub rsp, N
 *   Epilogue: leave; ret
 */
class X86BackendEmitter implements BackendEmitter
{
    private X86Emitter $emitter;

    /** @var array<string, int> */
    private array $labels = [];

    /** @var array<string, int[]> */
    private array $pendingJumps = [];

    private int $argIndex = 0;

    private const ARG_REGS = ['rdi', 'rsi', 'rdx', 'rcx', 'r8', 'r9'];

    public function targetArchitecture(): Architecture
    {
        return Architecture::X86_64;
    }

    public function emit(Program $program, string $filename): void
    {
        $this->emitter = new X86Emitter();
        $this->labels = [];
        $this->pendingJumps = [];

        foreach ($program->functions as $function) {
            $this->emitFunction($function);
        }

        foreach ($program->instructions as $instruction) {
            $this->emitInstruction($instruction);
        }

        if ($this->pendingJumps !== []) {
            $unresolved = implode(', ', array_keys($this->pendingJumps));
            throw new Exception("Unresolved IR labels: {$unresolved}");
        }

        $this->emitter->generateBinary($filename);
    }

    private function emitFunction(IrFunction $function): void
    {
        $this->argIndex = 0;

        $this->emitter->label('func_' . $function->name);

        // Prologue
        $this->emitter->pushReg('rbp');
        $this->emitter->movReg('rbp', 'rsp');

        foreach ($function->instructions as $instruction) {
            $this->emitInstruction($instruction);
        }
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
            case 'bit_and':
                $this->emitBinaryMath($instruction, static function (X86Emitter $emitter): void {
                    $emitter->andRaxRdx();
                });
                break;
            case 'bit_or':
                $this->emitBinaryMath($instruction, static function (X86Emitter $emitter): void {
                    $emitter->orRaxRdx();
                });
                break;
            case 'bit_xor':
                $this->emitBinaryMath($instruction, static function (X86Emitter $emitter): void {
                    $emitter->xorRaxRdx();
                });
                break;
            case 'bit_not':
                $result = $this->requireResult($instruction);
                $value = $this->stringOperand($instruction, 0);
                $this->emitter->loadLocal($value);
                $this->emitter->notRax();
                $this->emitter->storeLocal($result);
                break;
            case 'shl':
                $result = $this->requireResult($instruction);
                $value = $this->stringOperand($instruction, 0);
                $amount = $this->intOperand($instruction, 1);
                $this->emitter->loadLocal($value);
                $this->emitter->shlRaxImm8($amount);
                $this->emitter->storeLocal($result);
                break;
            case 'shr':
                $result = $this->requireResult($instruction);
                $value = $this->stringOperand($instruction, 0);
                $amount = $this->intOperand($instruction, 1);
                $this->emitter->loadLocal($value);
                $this->emitter->sarRaxImm8($amount);
                $this->emitter->storeLocal($result);
                break;
            case 'shr_u':
                $result = $this->requireResult($instruction);
                $value = $this->stringOperand($instruction, 0);
                $amount = $this->intOperand($instruction, 1);
                $this->emitter->loadLocal($value);
                $this->emitter->shrRaxImm8($amount);
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
            case 'func':
                break;
            case 'end':
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
                throw new Exception('Unsupported IR opcode for x86 backend: ' . $instruction->opcode);
        }
    }

    private function emitParam(Instruction $instruction): void
    {
        if ($this->argIndex < count(self::ARG_REGS)) {
            $reg = self::ARG_REGS[$this->argIndex];
            $this->emitter->movReg($reg, 'rax');
            $this->emitter->storeLocal($this->stringOperand($instruction, 0));
        }
        $this->argIndex++;
    }

    private function emitArg(Instruction $instruction): void
    {
        if ($this->argIndex < count(self::ARG_REGS)) {
            $reg = self::ARG_REGS[$this->argIndex];
            $name = $this->stringOperand($instruction, 0);
            $this->emitter->loadLocal($name);
            $this->emitter->movReg($reg, 'rax');
        }
        $this->argIndex++;
    }

    private function emitCall(Instruction $instruction): void
    {
        $name = $this->stringOperand($instruction, 0);
        $this->argIndex = 0;

        $callOffset = $this->emitter->getCurrentOffset();
        $this->emitter->callRel32(0); // placeholder
        $this->pendingJumps['call_' . $name . '_' . $callOffset][] = $callOffset;

        $result = $instruction->result;
        if ($result !== null) {
            $this->emitter->storeLocal($result);
        }
    }

    private function emitRet(Instruction $instruction): void
    {
        if (isset($instruction->operands[0])) {
            $name = $this->stringOperand($instruction, 0);
            $this->emitter->loadLocal($name);
            $this->emitter->movReg('rax', 'rax');
        }

        // Epilogue: leave; ret
        $this->emitter->movReg('rsp', 'rbp');
        $this->emitter->popReg('rbp');
        $this->emitter->ret();
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
