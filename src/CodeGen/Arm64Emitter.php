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

    private ByteBuffer $text;

    private string $dataSection = '';

    /** @var array<string, int> */
    private array $labels = [];

    /** @var array<int, array{offset: int, kind: string, label: string, register?: string, condition?: string}> */
    private array $pendingJumps = [];

    /** @var array<int, array{codeOffset: int, register: string, dataOffset: int}> */
    private array $relocations = [];

    private int $internalLabelCounter = 0;

    public function __construct()
    {
        $this->text = new ByteBuffer();
    }

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

    public function andReg(string $destination, string $left, string $right): void
    {
        $this->operations[] = ['opcode' => 'and', 'operands' => [$destination, $left, $right]];
    }

    public function orrReg(string $destination, string $left, string $right): void
    {
        $this->operations[] = ['opcode' => 'orr', 'operands' => [$destination, $left, $right]];
    }

    public function eorReg(string $destination, string $left, string $right): void
    {
        $this->operations[] = ['opcode' => 'eor', 'operands' => [$destination, $left, $right]];
    }

    public function mvnReg(string $destination, string $source): void
    {
        $this->operations[] = ['opcode' => 'mvn', 'operands' => [$destination, $source]];
    }

    public function lslImm(string $destination, string $source, int $amount): void
    {
        $this->operations[] = ['opcode' => 'lsl', 'operands' => [$destination, $source, $amount]];
    }

    public function lsrImm(string $destination, string $source, int $amount): void
    {
        $this->operations[] = ['opcode' => 'lsr', 'operands' => [$destination, $source, $amount]];
    }

    public function asrImm(string $destination, string $source, int $amount): void
    {
        $this->operations[] = ['opcode' => 'asr', 'operands' => [$destination, $source, $amount]];
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

    public function pushReg(string $register): void
    {
        $this->operations[] = ['opcode' => 'push_reg', 'operands' => [$register]];
    }

    public function popReg(string $register): void
    {
        $this->operations[] = ['opcode' => 'pop_reg', 'operands' => [$register]];
    }

    public function branchLink(string $label): void
    {
        $this->operations[] = ['opcode' => 'bl', 'operands' => [$label]];
    }

    public function ret(): void
    {
        $this->operations[] = ['opcode' => 'ret'];
    }

    public function movReg(string $dest, string $source): void
    {
        $this->operations[] = ['opcode' => 'mov_reg', 'operands' => [$dest, $source]];
    }

    public function generateBinary(string $filename): void
    {
        $this->text = new ByteBuffer();
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
        $dataVirtualAddress = 0x400000 + 120 + $this->text->length();
        $this->patchDataRelocations($dataVirtualAddress);

        $fileSize = 120 + $this->text->length() + strlen($this->dataSection);
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

        file_put_contents($filename, $elfHeader . $programHeader . $this->text->bytes() . $this->dataSection);
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
            case 'and':
                $this->emitAnd(
                    $this->register((string) $operands[0]),
                    $this->register((string) $operands[1]),
                    $this->register((string) $operands[2]),
                );
                break;
            case 'orr':
                $this->emitOrr(
                    $this->register((string) $operands[0]),
                    $this->register((string) $operands[1]),
                    $this->register((string) $operands[2]),
                );
                break;
            case 'eor':
                $this->emitEor(
                    $this->register((string) $operands[0]),
                    $this->register((string) $operands[1]),
                    $this->register((string) $operands[2]),
                );
                break;
            case 'mvn':
                $this->emitMvn(
                    $this->register((string) $operands[0]),
                    $this->register((string) $operands[1]),
                );
                break;
            case 'lsl':
                $this->emitLslImm(
                    $this->register((string) $operands[0]),
                    $this->register((string) $operands[1]),
                    (int) $operands[2],
                );
                break;
            case 'lsr':
                $this->emitLsrImm(
                    $this->register((string) $operands[0]),
                    $this->register((string) $operands[1]),
                    (int) $operands[2],
                );
                break;
            case 'asr':
                $this->emitAsrImm(
                    $this->register((string) $operands[0]),
                    $this->register((string) $operands[1]),
                    (int) $operands[2],
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
                $this->emitPrintNumber($this->register((string) $operands[0]));
                break;
            case 'push_reg':
                $this->emitPushReg($this->register((string) $operands[0]));
                break;
            case 'pop_reg':
                $this->emitPopReg($this->register((string) $operands[0]));
                break;
            case 'bl':
                $this->emitBranchLink((string) $operands[0]);
                break;
            case 'ret':
                $this->emitRet();
                break;
            case 'mov_reg':
                $this->emitMovReg(
                    $this->register((string) $operands[0]),
                    $this->register((string) $operands[1]),
                );
                break;
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

    private function emitPushReg(int $register): void
    {
        // STP Xn, XZR, [SP, #-16]! — push single register + padding
        $this->emitWord32(0xA9BF0000 | ($register << 10) | (31 << 5) | $register);
    }

    private function emitPopReg(int $register): void
    {
        // LDP Xn, XZR, [SP], #16 — pop single register + skip padding
        $this->emitWord32(0xA8C10000 | ($register << 10) | (31 << 5) | $register);
    }

    private function emitBranchLink(string $label): void
    {
        $offset = $this->text->length();
        // BL imm26 — placeholder with imm26=0
        $this->emitWord32(0x94000000);
        $this->pendingJumps[] = ['offset' => $offset, 'kind' => 'bl', 'label' => $label];
    }

    private function emitRet(): void
    {
        // RET = BR X30 = D63F03C0
        $this->emitWord32(0xD63F03C0);
    }

    private function emitAdd(int $destination, int $left, int $right): void
    {
        $this->emitWord32(0x8B000000 | ($right << 16) | ($left << 5) | $destination);
    }

    private function emitAnd(int $destination, int $left, int $right): void
    {
        // AND Xd, Xn, Xm
        $this->emitWord32(0x8A000000 | ($right << 16) | ($left << 5) | $destination);
    }

    private function emitOrr(int $destination, int $left, int $right): void
    {
        // ORR Xd, Xn, Xm
        $this->emitWord32(0xAA000000 | ($right << 16) | ($left << 5) | $destination);
    }

    private function emitEor(int $destination, int $left, int $right): void
    {
        // EOR Xd, Xn, Xm
        $this->emitWord32(0xCA000000 | ($right << 16) | ($left << 5) | $destination);
    }

    private function emitMvn(int $destination, int $source): void
    {
        // MVN Xd, Xm = ORR Xd, XZR, Xm
        $this->emitWord32(0xAA2003E0 | ($source << 16) | $destination);
    }

    private function emitLslImm(int $destination, int $source, int $amount): void
    {
        if ($amount < 0 || $amount > 63) {
            throw new Exception("ARM64 LSL immediate out of range: {$amount}");
        }
        // UBFX Xd, Xn, #(-amount & 63), #(64 - amount)
        $immr = (-$amount) & 63;
        $imms = (64 - $amount) - 1;
        $this->emitWord32(0xD3400000 | ($immr << 16) | ($imms << 10) | ($source << 5) | $destination);
    }

    private function emitLsrImm(int $destination, int $source, int $amount): void
    {
        if ($amount < 1 || $amount > 64) {
            throw new Exception("ARM64 LSR immediate out of range: {$amount}");
        }
        // UBFX Xd, Xn, #amount, #(64 - amount)
        $immr = $amount;
        $imms = 63 - $amount;
        $this->emitWord32(0xD3400000 | ($immr << 16) | ($imms << 10) | ($source << 5) | $destination);
    }

    private function emitAsrImm(int $destination, int $source, int $amount): void
    {
        if ($amount < 1 || $amount > 64) {
            throw new Exception("ARM64 ASR immediate out of range: {$amount}");
        }
        // SBFX Xd, Xn, #amount, #(64 - amount)
        $immr = $amount;
        $imms = 63 - $amount;
        $this->emitWord32(0x93400000 | ($immr << 16) | ($imms << 10) | ($source << 5) | $destination);
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

    /**
     * Converts the integer held in the given register to a decimal ASCII
     * string on the stack and writes it (with a trailing newline) via the
     * write(2) syscall. Uses x9-x15 as scratch registers.
     */
    private function emitPrintNumber(int $valueRegister): void
    {
        $bufferSize = 32;
        $doneDigits = $this->nextInternalLabel('num_done_digits');
        $isPositive = $this->nextInternalLabel('num_is_positive');
        $skipSign = $this->nextInternalLabel('num_skip_sign');
        $loop = $this->nextInternalLabel('num_loop');

        $this->emitSubImm(31, 31, $bufferSize); // sub sp, sp, #bufferSize

        // x10 = pointer to the last buffer byte; write the trailing newline there.
        $this->emitAddImmRaw(10, 31, $bufferSize - 1); // add x10, sp, #(bufferSize - 1)
        $this->emitMovImm64(13, 10); // mov x13, #'\n'
        $this->emitStrb(13, 10);
        $this->emitSubImm(10, 10, 1); // sub x10, x10, #1

        $this->emitMovReg(9, $valueRegister); // mov x9, <value>
        $this->emitMovImm64(11, 10); // mov x11, #10 (divisor)
        $this->emitMovImm64(12, 0); // mov x12, #0 (negative flag)

        $this->emitCmpImm(9, 0);
        $this->emitBranchCondPlaceholder('ge', $isPositive);
        $this->emitNeg(9, 9); // neg x9, x9
        $this->emitMovImm64(12, 1); // mov x12, #1
        $this->defineLabel($isPositive);

        $this->defineLabel($loop);
        $this->emitUdiv(14, 9, 11); // udiv x14, x9, x11
        $this->emitMsub(13, 14, 11, 9); // msub x13, x14, x11, x9 (remainder)
        $this->emitAddImmRaw(13, 13, 48); // add x13, x13, #'0'
        $this->emitStrb(13, 10);
        $this->emitSubImm(10, 10, 1); // sub x10, x10, #1
        $this->emitMovReg(9, 14); // mov x9, x14
        $this->emitCbzPlaceholder('x14', $doneDigits);
        $this->emitBranchPlaceholder($loop);

        $this->defineLabel($doneDigits);
        $this->emitCmpImm(12, 0);
        $this->emitBranchCondPlaceholder('eq', $skipSign);
        $this->emitMovImm64(13, 45); // mov x13, #'-'
        $this->emitStrb(13, 10);
        $this->emitSubImm(10, 10, 1); // sub x10, x10, #1
        $this->defineLabel($skipSign);

        $this->emitAddImmRaw(1, 10, 1); // x1 = start of the string (x10 + 1)
        $this->emitAddImmRaw(15, 31, $bufferSize); // x15 = sp + bufferSize (one past the end)
        $this->emitSubReg(2, 15, 1); // x2 = length = x15 - x1

        $this->emitMovImm64(0, 1); // stdout fd
        $this->emitMovImm64(8, 64); // sys_write
        $this->emitWord32(0xD4000001); // svc #0

        $this->emitAddImmRaw(31, 31, $bufferSize); // add sp, sp, #bufferSize
    }

    private function emitPrintString(string $value): void
    {
        $dataOffset = strlen($this->dataSection);
        $this->dataSection .= $value . "\n";
        $length = strlen($value) + 1;

        $this->emitMovImm64(0, 1); // stdout fd
        $relocOffset = $this->text->length();
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

    private function emitAddImmRaw(int $destination, int $left, int $immediate): void
    {
        if ($immediate < 0 || $immediate > 4095) {
            throw new Exception("ARM64 add immediate out of range: {$immediate}");
        }
        $this->emitWord32(0x91000000 | ($immediate << 10) | ($left << 5) | $destination);
    }

    private function emitSubReg(int $destination, int $left, int $right): void
    {
        $this->emitWord32(0xCB000000 | ($right << 16) | ($left << 5) | $destination);
    }

    private function emitMovReg(int $destination, int $source): void
    {
        $this->emitWord32(0xAA0003E0 | ($source << 16) | $destination);
    }

    private function emitNeg(int $destination, int $source): void
    {
        $this->emitWord32(0xCB0003E0 | ($source << 16) | $destination);
    }

    private function emitCmpImm(int $register, int $immediate): void
    {
        if ($immediate < 0 || $immediate > 4095) {
            throw new Exception("ARM64 cmp immediate out of range: {$immediate}");
        }
        $this->emitWord32(0xF1000000 | ($immediate << 10) | ($register << 5) | 31);
    }

    private function emitUdiv(int $destination, int $left, int $right): void
    {
        $this->emitWord32(0x9AC00800 | ($right << 16) | ($left << 5) | $destination);
    }

    private function emitMsub(int $destination, int $left, int $right, int $addend): void
    {
        $this->emitWord32(0x9B008000 | ($right << 16) | ($addend << 10) | ($left << 5) | $destination);
    }

    private function emitStrb(int $source, int $addressRegister): void
    {
        $this->emitWord32(0x39000000 | ($addressRegister << 5) | $source);
    }

    private function emitBranchPlaceholder(string $label): void
    {
        $offset = $this->text->length();
        $this->emitWord32(0x14000000);
        $this->pendingJumps[] = ['offset' => $offset, 'kind' => 'b', 'label' => $label];
    }

    private function emitCbzPlaceholder(string $register, string $label): void
    {
        $rt = $this->register($register);
        $offset = $this->text->length();
        $this->emitWord32(0xB4000000 | $rt);
        $this->pendingJumps[] = ['offset' => $offset, 'kind' => 'cbz', 'label' => $label, 'register' => $register];
    }

    private function emitBranchCondPlaceholder(string $condition, string $label): void
    {
        $cond = $this->conditionCode($condition);
        $offset = $this->text->length();
        $this->emitWord32(0x54000000 | $cond);
        $this->pendingJumps[] = ['offset' => $offset, 'kind' => 'bcond', 'label' => $label, 'condition' => $condition];
    }

    private function defineLabel(string $label): void
    {
        if (isset($this->labels[$label])) {
            throw new Exception("Duplicate ARM64 label: {$label}");
        }

        $this->labels[$label] = $this->text->length();
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
                'bl' => 0x94000000 | ($imm & 0x03FFFFFF),
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
        $this->text->pushU32LE($word);
    }

    private function patchWord32(int $offset, int $word): void
    {
        $this->text->patchU32LE($offset, $word);
    }

    /**
     * @return int[]
     */
    private function splitImm64(int $value): array
    {
        // PHP integers are natively 64-bit two's complement, so bitwise
        // operators already read the correct bit pattern for negative
        // values without needing an explicit (overflowing) 0xFFFF...F mask.
        return [
            $value & 0xFFFF,
            ($value >> 16) & 0xFFFF,
            ($value >> 32) & 0xFFFF,
            ($value >> 48) & 0xFFFF,
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
            'ge' => 0xA,
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
