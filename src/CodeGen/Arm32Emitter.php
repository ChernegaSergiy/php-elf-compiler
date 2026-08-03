<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\CodeGen;

use Exception;

/**
 * Emits a minimal static ARM32 ELF32 binary from backend operations.
 */
class Arm32Emitter
{
    /** @var array<int, array{opcode: string, operands: array<int, int|string>}> */
    private array $operations = [];

    /** @var array<string, int> */
    private array $locals = [];

    private int $stackOffset = 0;

    private string $textSection = '';

    private string $dataSection = '';

    /** @var array<string, int> */
    private array $labels = [];

    /** @var array<int, array{offset: int, kind: string, label: string, condition?: string}> */
    private array $pendingJumps = [];

    /** @var array<int, array{codeOffset: int, register: string, dataOffset: int}> */
    private array $relocations = [];

    private int $internalLabelCounter = 0;

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
            $this->stackOffset += 4;
            $this->locals[$name] = $this->stackOffset;
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

    public function moveConditionFlag(string $destination, string $condition): void
    {
        $this->operations[] = ['opcode' => 'mov_cond', 'operands' => [$destination, $condition]];
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
        $this->textSection = '';
        $this->dataSection = '';
        $this->labels = [];
        $this->pendingJumps = [];
        $this->relocations = [];
        $this->internalLabelCounter = 0;

        $stackSize = $this->alignedStackSize();
        $this->emitPrologue($stackSize);

        foreach ($this->operations as $operation) {
            $this->emitOperation($operation);
        }

        $this->patchPendingJumps();
        $this->emitExitSequence();

        $entryPoint = 0x10054;
        $dataVirtualAddress = 0x10000 + 84 + strlen($this->textSection);
        $this->patchDataRelocations($dataVirtualAddress);

        $fileSize = 84 + strlen($this->textSection) + strlen($this->dataSection);
        $elfHeader = pack(
            'C16vvVVVVVvvvvvv',
            0x7F,
            0x45,
            0x4C,
            0x46,
            1,
            1,
            1,
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            2,
            0x28,
            1,
            $entryPoint,
            52,
            0,
            0,
            52,
            32,
            1,
            0,
            0,
            0
        );
        $programHeader = pack('VVVVVVVV', 1, 0, 0x10000, 0x10000, $fileSize, $fileSize, 5, 0x1000);

        file_put_contents($filename, $elfHeader . $programHeader . $this->textSection . $this->dataSection);
        chmod($filename, 0755);
    }

    /**
     * @param array{opcode: string, operands: array<int, int|string>} $operation
     */
    private function emitOperation(array $operation): void
    {
        $opcode = $operation['opcode'];
        $operands = $operation['operands'];

        switch ($opcode) {
            case 'mov_imm':
                $this->emitMovImm32($this->register((string) $operands[0]), (int) $operands[1]);
                break;
            case 'load_local':
                $this->emitLoadLocal($this->register((string) $operands[0]), (int) $operands[1]);
                break;
            case 'store_local':
                $this->emitStoreLocal($this->register((string) $operands[0]), (int) $operands[1]);
                break;
            case 'add':
                $this->emitAdd(
                    $this->register((string) $operands[0]),
                    $this->register((string) $operands[1]),
                    $this->register((string) $operands[2]),
                );
                break;
            case 'cmp':
                $this->emitCmp(
                    $this->register((string) $operands[0]),
                    $this->register((string) $operands[1]),
                );
                break;
            case 'mov_cond':
                $this->emitMovCondition(
                    $this->register((string) $operands[0]),
                    (string) $operands[1],
                );
                break;
            case 'label':
                $this->defineLabel((string) $operands[0]);
                break;
            case 'branch':
                $this->emitBranchPlaceholder((string) $operands[0], 0xE);
                break;
            case 'branch_if_zero':
                $this->emitCmpImm0($this->register((string) $operands[0]));
                $this->emitBranchPlaceholder((string) $operands[1], 0x0);
                break;
            case 'print_str':
                $this->emitPrintString((string) $operands[0]);
                break;
            case 'print_num':
                throw new Exception('ARM32 print_num is not implemented yet.');
            default:
                throw new Exception("Unsupported ARM32 operation: {$opcode}");
        }
    }

    private function emitPrologue(int $stackSize): void
    {
        $this->emitWord32(0xE92D4800); // push {r11, lr}
        $this->emitWord32(0xE1A0B00D); // mov r11, sp
        $this->emitWord32(0xE24DD000 | $stackSize); // sub sp, sp, #stackSize
        $this->emitWord32(0xE1A0A00D); // mov r10, sp
    }

    private function emitExitSequence(): void
    {
        $this->emitMovImm32(0, 0);
        $this->emitMovImm32(7, 1); // sys_exit
        $this->emitWord32(0xEF000000); // svc 0
    }

    private function emitLoadLocal(int $register, int $offset): void
    {
        $this->assertValidLocalOffset($offset);
        $this->emitWord32(0xE59A0000 | ($register << 12) | $offset); // ldr rt, [r10, #offset]
    }

    private function emitStoreLocal(int $register, int $offset): void
    {
        $this->assertValidLocalOffset($offset);
        $this->emitWord32(0xE58A0000 | ($register << 12) | $offset); // str rt, [r10, #offset]
    }

    private function emitAdd(int $destination, int $left, int $right): void
    {
        $this->emitWord32(0xE0800000 | ($left << 16) | ($destination << 12) | $right);
    }

    private function emitCmp(int $left, int $right): void
    {
        $this->emitWord32(0xE1500000 | ($left << 16) | $right);
    }

    private function emitCmpImm0(int $register): void
    {
        $this->emitWord32(0xE3500000 | ($register << 16));
    }

    private function emitMovCondition(int $destination, string $condition): void
    {
        $trueLabel = $this->nextInternalLabel('set_true');
        $endLabel = $this->nextInternalLabel('set_end');

        $this->emitBranchPlaceholder($trueLabel, $this->conditionCode($condition));
        $this->emitMovImm32($destination, 0);
        $this->emitBranchPlaceholder($endLabel, 0xE);
        $this->defineLabel($trueLabel);
        $this->emitMovImm32($destination, 1);
        $this->defineLabel($endLabel);
    }

    private function emitPrintString(string $value): void
    {
        $dataOffset = strlen($this->dataSection);
        $this->dataSection .= $value . "\n";
        $length = strlen($value) + 1;

        $this->emitMovImm32(0, 1); // fd stdout
        $relocOffset = strlen($this->textSection);
        $this->emitMovImm32(1, 0); // patched abs data address
        $this->relocations[] = ['codeOffset' => $relocOffset, 'register' => 'r1', 'dataOffset' => $dataOffset];
        $this->emitMovImm32(2, $length);
        $this->emitMovImm32(7, 4); // sys_write
        $this->emitWord32(0xEF000000); // svc 0
    }

    private function emitMovImm32(int $register, int $value): void
    {
        if ($value < 0) {
            throw new Exception('Negative immediates are not supported in ARM32 emitter.');
        }
        $low = $value & 0xFFFF;
        $high = ($value >> 16) & 0xFFFF;

        $this->emitWord32(0xE3000000 | ($register << 12) | (($low & 0xF000) << 4) | ($low & 0x0FFF)); // movw
        $this->emitWord32(0xE3400000 | ($register << 12) | (($high & 0xF000) << 4) | ($high & 0x0FFF)); // movt
    }

    private function emitBranchPlaceholder(string $label, int $condition): void
    {
        $offset = strlen($this->textSection);
        $this->emitWord32(($condition << 28) | 0x0A000000);
        $this->pendingJumps[] = ['offset' => $offset, 'kind' => 'b', 'label' => $label, 'condition' => (string) $condition];
    }

    private function defineLabel(string $label): void
    {
        if (isset($this->labels[$label])) {
            throw new Exception("Duplicate ARM32 label: {$label}");
        }

        $this->labels[$label] = strlen($this->textSection);
    }

    private function patchPendingJumps(): void
    {
        foreach ($this->pendingJumps as $jump) {
            $target = $this->labels[$jump['label']] ?? null;
            if ($target === null) {
                throw new Exception("Unknown ARM32 branch label: {$jump['label']}");
            }

            $deltaBytes = $target - ($jump['offset'] + 8);
            if ($deltaBytes % 4 !== 0) {
                throw new Exception('Unaligned ARM32 branch target');
            }

            $imm24 = intdiv($deltaBytes, 4);
            $condition = (int) $jump['condition'];
            $encoded = ($condition << 28) | 0x0A000000 | ($imm24 & 0x00FFFFFF);
            $this->patchWord32($jump['offset'], $encoded);
        }
    }

    private function patchDataRelocations(int $dataVirtualAddress): void
    {
        foreach ($this->relocations as $relocation) {
            $address = $dataVirtualAddress + $relocation['dataOffset'];
            $register = $this->register($relocation['register']);
            $low = $address & 0xFFFF;
            $high = ($address >> 16) & 0xFFFF;

            $this->patchWord32(
                $relocation['codeOffset'],
                0xE3000000 | ($register << 12) | (($low & 0xF000) << 4) | ($low & 0x0FFF)
            );
            $this->patchWord32(
                $relocation['codeOffset'] + 4,
                0xE3400000 | ($register << 12) | (($high & 0xF000) << 4) | ($high & 0x0FFF)
            );
        }
    }

    private function emitWord32(int $word): void
    {
        $this->textSection .= pack('V', $word);
    }

    private function patchWord32(int $offset, int $word): void
    {
        $this->textSection = substr_replace($this->textSection, pack('V', $word), $offset, 4);
    }

    private function register(string $register): int
    {
        if (!preg_match('/^r([0-9]|1[0-2])$/', $register, $matches)) {
            throw new Exception("Unsupported ARM32 register: {$register}");
        }

        return (int) $matches[1];
    }

    private function conditionCode(string $condition): int
    {
        return match ($condition) {
            'eq' => 0x0,
            'ne' => 0x1,
            'lt' => 0xB,
            default => throw new Exception("Unsupported ARM32 condition: {$condition}"),
        };
    }

    private function assertValidLocalOffset(int $offset): void
    {
        if ($offset <= 0 || $offset % 4 !== 0 || $offset > 4095) {
            throw new Exception("Invalid ARM32 local offset: {$offset}");
        }
    }

    private function alignedStackSize(): int
    {
        $size = max(16, $this->stackOffset);

        return (int) (ceil($size / 16) * 16);
    }

    private function nextInternalLabel(string $prefix): string
    {
        $this->internalLabelCounter++;

        return "__{$prefix}_{$this->internalLabelCounter}";
    }
}
