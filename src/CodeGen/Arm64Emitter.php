<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\CodeGen;

use Exception;

/**
 * Emits a minimal static AArch64 ELF64 binary from backend operations.
 */
class Arm64Emitter
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

    /** @var array<int, array{offset: int, kind: string, label: string, register?: string, condition?: string}> */
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
            $this->stackOffset += 8;
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

        $entryPoint = 0x400078;
        $dataVirtualAddress = 0x400000 + 120 + strlen($this->textSection);
        $this->patchDataRelocations($dataVirtualAddress);

        $fileSize = 120 + strlen($this->textSection) + strlen($this->dataSection);
        $elfHeader = pack(
            'C16vvVPPPVvvvvvv',
            0x7F,
            0x45,
            0x4C,
            0x46,
            2,
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
            0xB7,
            1,
            $entryPoint,
            64,
            0,
            0,
            64,
            56,
            1,
            0,
            0,
            0
        );
        $programHeader = pack('VVPPPPPP', 1, 5, 0, 0x400000, 0x400000, $fileSize, $fileSize, 0x1000);

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
                $this->emitMovImm64($this->register((string) $operands[0]), (int) $operands[1]);
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
            case 'cset':
                $this->emitCset(
                    $this->register((string) $operands[0]),
                    (string) $operands[1],
                );
                break;
            case 'label':
                $this->defineLabel((string) $operands[0]);
                break;
            case 'branch':
                $this->emitBranchPlaceholder((string) $operands[0]);
                break;
            case 'branch_if_zero':
                $this->emitCbzPlaceholder((string) $operands[0], (string) $operands[1]);
                break;
            case 'print_str':
                $this->emitPrintString((string) $operands[0]);
                break;
            case 'print_num':
                throw new Exception('ARM64 print_num is not implemented yet.');
            default:
                throw new Exception("Unsupported ARM64 operation: {$opcode}");
        }
    }

    private function emitPrologue(int $stackSize): void
    {
        $this->emitWord32(0xA9BF7BFD); // stp x29, x30, [sp, #-16]!
        $this->emitWord32(0x910003FD); // mov x29, sp
        $this->emitSubImm(31, 31, $stackSize); // sub sp, sp, #stackSize
        $this->emitWord32(0x910003F3); // mov x19, sp
    }

    private function emitExitSequence(): void
    {
        $this->emitMovImm64(0, 0); // x0 = status
        $this->emitMovImm64(8, 93); // x8 = sys_exit
        $this->emitWord32(0xD4000001); // svc #0
    }

    private function emitLoadLocal(int $register, int $offset): void
    {
        $this->assertValidLocalOffset($offset);
        $imm12 = intdiv($offset, 8);
        $this->emitWord32(0xF9400000 | ($imm12 << 10) | (19 << 5) | $register);
    }

    private function emitStoreLocal(int $register, int $offset): void
    {
        $this->assertValidLocalOffset($offset);
        $imm12 = intdiv($offset, 8);
        $this->emitWord32(0xF9000000 | ($imm12 << 10) | (19 << 5) | $register);
    }

    private function emitAdd(int $destination, int $left, int $right): void
    {
        $this->emitWord32(0x8B000000 | ($right << 16) | ($left << 5) | $destination);
    }

    private function emitCmp(int $left, int $right): void
    {
        $this->emitWord32(0xEB00001F | ($right << 16) | ($left << 5));
    }

    private function emitCset(int $destination, string $condition): void
    {
        $trueLabel = $this->nextInternalLabel('cset_true');
        $endLabel = $this->nextInternalLabel('cset_end');

        $this->emitBranchCondPlaceholder($condition, $trueLabel);
        $this->emitMovImm64($destination, 0);
        $this->emitBranchPlaceholder($endLabel);
        $this->defineLabel($trueLabel);
        $this->emitMovImm64($destination, 1);
        $this->defineLabel($endLabel);
    }

    private function emitPrintString(string $value): void
    {
        $dataOffset = strlen($this->dataSection);
        $this->dataSection .= $value . "\n";
        $length = strlen($value) + 1;

        $this->emitMovImm64(0, 1); // stdout fd
        $relocOffset = strlen($this->textSection);
        $this->emitMovImm64(1, 0); // patched to absolute data address
        $this->relocations[] = ['codeOffset' => $relocOffset, 'register' => 'x1', 'dataOffset' => $dataOffset];
        $this->emitMovImm64(2, $length); // len
        $this->emitMovImm64(8, 64); // sys_write
        $this->emitWord32(0xD4000001); // svc #0
    }

    private function emitMovImm64(int $register, int $value): void
    {
        $chunks = $this->splitImm64($value);
        $this->emitWord32(0xD2800000 | (0 << 21) | ($chunks[0] << 5) | $register);
        $this->emitWord32(0xF2800000 | (1 << 21) | ($chunks[1] << 5) | $register);
        $this->emitWord32(0xF2800000 | (2 << 21) | ($chunks[2] << 5) | $register);
        $this->emitWord32(0xF2800000 | (3 << 21) | ($chunks[3] << 5) | $register);
    }

    private function emitSubImm(int $destination, int $left, int $immediate): void
    {
        if ($immediate < 0 || $immediate > 4095) {
            throw new Exception("ARM64 sub immediate out of range: {$immediate}");
        }
        $this->emitWord32(0xD1000000 | ($immediate << 10) | ($left << 5) | $destination);
    }

    private function emitBranchPlaceholder(string $label): void
    {
        $offset = strlen($this->textSection);
        $this->emitWord32(0x14000000);
        $this->pendingJumps[] = ['offset' => $offset, 'kind' => 'b', 'label' => $label];
    }

    private function emitCbzPlaceholder(string $register, string $label): void
    {
        $rt = $this->register($register);
        $offset = strlen($this->textSection);
        $this->emitWord32(0xB4000000 | $rt);
        $this->pendingJumps[] = ['offset' => $offset, 'kind' => 'cbz', 'label' => $label, 'register' => $register];
    }

    private function emitBranchCondPlaceholder(string $condition, string $label): void
    {
        $cond = $this->conditionCode($condition);
        $offset = strlen($this->textSection);
        $this->emitWord32(0x54000000 | $cond);
        $this->pendingJumps[] = ['offset' => $offset, 'kind' => 'bcond', 'label' => $label, 'condition' => $condition];
    }

    private function defineLabel(string $label): void
    {
        if (isset($this->labels[$label])) {
            throw new Exception("Duplicate ARM64 label: {$label}");
        }

        $this->labels[$label] = strlen($this->textSection);
    }

    private function patchPendingJumps(): void
    {
        foreach ($this->pendingJumps as $jump) {
            $target = $this->labels[$jump['label']] ?? null;
            if ($target === null) {
                throw new Exception("Unknown ARM64 branch label: {$jump['label']}");
            }

            $deltaBytes = $target - $jump['offset'];
            if ($deltaBytes % 4 !== 0) {
                throw new Exception('Unaligned ARM64 branch target');
            }

            $imm = intdiv($deltaBytes, 4);
            $encoded = match ($jump['kind']) {
                'b' => 0x14000000 | ($imm & 0x03FFFFFF),
                'cbz' => 0xB4000000 | (($imm & 0x7FFFF) << 5) | $this->register((string) $jump['register']),
                'bcond' => 0x54000000 | (($imm & 0x7FFFF) << 5) | $this->conditionCode((string) $jump['condition']),
                default => throw new Exception("Unsupported ARM64 jump kind: {$jump['kind']}"),
            };

            $this->patchWord32($jump['offset'], $encoded);
        }
    }

    private function patchDataRelocations(int $dataVirtualAddress): void
    {
        foreach ($this->relocations as $relocation) {
            $address = $dataVirtualAddress + $relocation['dataOffset'];
            $register = $this->register($relocation['register']);
            $chunks = $this->splitImm64($address);

            $this->patchWord32($relocation['codeOffset'], 0xD2800000 | (0 << 21) | ($chunks[0] << 5) | $register);
            $this->patchWord32($relocation['codeOffset'] + 4, 0xF2800000 | (1 << 21) | ($chunks[1] << 5) | $register);
            $this->patchWord32($relocation['codeOffset'] + 8, 0xF2800000 | (2 << 21) | ($chunks[2] << 5) | $register);
            $this->patchWord32($relocation['codeOffset'] + 12, 0xF2800000 | (3 << 21) | ($chunks[3] << 5) | $register);
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

    /**
     * @return int[]
     */
    private function splitImm64(int $value): array
    {
        $unsigned = $value;
        if ($unsigned < 0) {
            $unsigned = $value & 0xFFFFFFFFFFFFFFFF;
        }

        return [
            (int) ($unsigned & 0xFFFF),
            (int) (($unsigned >> 16) & 0xFFFF),
            (int) (($unsigned >> 32) & 0xFFFF),
            (int) (($unsigned >> 48) & 0xFFFF),
        ];
    }

    private function register(string $register): int
    {
        if (!preg_match('/^x([0-9]|[12][0-9]|30)$/', $register, $matches)) {
            throw new Exception("Unsupported ARM64 register: {$register}");
        }

        return (int) $matches[1];
    }

    private function conditionCode(string $condition): int
    {
        return match ($condition) {
            'eq' => 0x0,
            'ne' => 0x1,
            'lt' => 0xB,
            default => throw new Exception("Unsupported ARM64 condition: {$condition}"),
        };
    }

    private function assertValidLocalOffset(int $offset): void
    {
        if ($offset <= 0 || $offset % 8 !== 0) {
            throw new Exception("Invalid ARM64 local offset: {$offset}");
        }
        if ($offset > 32760) {
            throw new Exception("ARM64 local offset out of range: {$offset}");
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
