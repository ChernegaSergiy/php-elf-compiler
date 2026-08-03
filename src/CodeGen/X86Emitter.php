<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\CodeGen;

use Exception;

/**
 * Emits raw x86_64 machine code and assembles it into a minimal static ELF executable.
 */
class X86Emitter
{
    private string $textSection = '';

    private string $dataSection = '';

    /** @var array<string, int> */
    private array $symbols = [];

    private int $stackOffset = 0;

    /** @var array<int, array{codeOffset: int, dataOffset: int}> */
    private array $relocations = [];

    public function movRaxImm(int $val): void
    {
        $this->textSection .= "\x48\xC7\xC0" . pack('V', $val);
    }

    public function pushRax(): void
    {
        $this->textSection .= "\x50";
    }

    public function popRdx(): void
    {
        $this->textSection .= "\x5A";
    }

    public function addRaxRdx(): void
    {
        $this->textSection .= "\x48\x01\xD0";
    }

    public function storeLocal(string $name): void
    {
        if (!isset($this->symbols[$name])) {
            $this->stackOffset += 8;
            $this->symbols[$name] = -$this->stackOffset;
        }
        $offset = $this->symbols[$name];
        // mov [rbp + offset], rax
        $this->textSection .= "\x48\x89\x45" . chr(256 + $offset);
    }

    public function loadLocal(string $name): void
    {
        $offset = $this->symbols[$name] ?? throw new Exception("Невідома змінна: $name");
        // mov rax, [rbp + offset]
        $this->textSection .= "\x48\x8B\x45" . chr(256 + $offset);
    }

    public function emitCmpRaxRdx(): void
    {
        $this->textSection .= "\x48\x39\xD0"; // cmp rax, rdx
    }

    public function emitSetlRax(): void
    {
        // setl al; movzx rax, al
        $this->textSection .= "\x0F\x9C\xC0\x48\x0F\xB6\xC0";
    }

    public function emitSeteRax(): void
    {
        // sete al; movzx rax, al
        $this->textSection .= "\x0F\x94\xC0\x48\x0F\xB6\xC0";
    }

    public function emitSetneRax(): void
    {
        // setne al; movzx rax, al
        $this->textSection .= "\x0F\x95\xC0\x48\x0F\xB6\xC0";
    }

    public function emitCmpRaxImm0(): void
    {
        $this->textSection .= "\x48\x83\xF8\x00"; // cmp rax, 0
    }

    public function getCurrentOffset(): int
    {
        return strlen($this->textSection);
    }

    /**
     * Emits a forward conditional jump (je rel32) with a placeholder offset
     * to be patched later via patchForwardJump().
     */
    public function emitJe_ForwardPlaceholder(): int
    {
        $this->textSection .= "\x0F\x84"; // je rel32
        $offset = strlen($this->textSection);
        $this->textSection .= "\x00\x00\x00\x00";

        return $offset;
    }

    /**
     * Emits a forward conditional jump (jne rel32) with a placeholder offset
     * to be patched later via patchForwardJump().
     */
    public function emitJne_ForwardPlaceholder(): int
    {
        $this->textSection .= "\x0F\x85"; // jne rel32
        $offset = strlen($this->textSection);
        $this->textSection .= "\x00\x00\x00\x00";

        return $offset;
    }

    /**
     * Emits an unconditional backward jump (jmp rel32) to $target.
     */
    public function emitJmp_Backward(int $target): void
    {
        $current = strlen($this->textSection);
        $rel = $target - ($current + 5);
        $this->textSection .= "\xE9" . pack('V', $rel);
    }

    /**
     * Emits a forward unconditional jump (jmp rel32) with a placeholder offset
     * to be patched later via patchForwardJump().
     */
    public function emitJmp_ForwardPlaceholder(): int
    {
        $this->textSection .= "\xE9"; // jmp rel32
        $offset = strlen($this->textSection);
        $this->textSection .= "\x00\x00\x00\x00";

        return $offset;
    }

    /**
     * Backpatches the placeholder left by emitJe_ForwardPlaceholder() with the
     * actual relative offset to the current position.
     */
    public function patchForwardJump(int $offset): void
    {
        $current = strlen($this->textSection);
        $rel = $current - ($offset + 4);
        $this->textSection = substr_replace($this->textSection, pack('V', $rel), $offset, 4);
    }

    /**
     * Converts the integer in RAX to its decimal ASCII representation on the
     * stack and writes it (plus a trailing newline) to stdout.
     */
    public function emitPrintNumberInRax(): void
    {
        $this->textSection .=
            "\x49\x89\xE2" .                 // mov r10, rsp (save stack pointer)
            "\x48\x83\xEC\x20" .             // sub rsp, 32 (stack buffer)
            "\x49\x89\xE3" .                 // mov r11, rsp
            "\x49\x83\xC3\x1F" .             // add r11, 31 (end of buffer)
            "\x41\xC6\x03\x0A" .             // mov byte [r11], 10 (newline)
            "\x4D\x89\xD8" .                 // mov r8, r11
            "\x48\xC7\xC1\x0A\x00\x00\x00" . // mov rcx, 10 (divisor)

            // loop_start:
            "\x49\xFF\xC8" .                 // dec r8
            "\x48\x31\xD2" .                 // xor rdx, rdx
            "\x48\xF7\xF1" .                 // div rcx
            "\x80\xC2\x30" .                 // add dl, 48 (digit to ASCII)
            "\x41\x88\x10" .                 // mov [r8], dl
            "\x48\x85\xC0" .                 // test rax, rax
            "\x75\xEC" .                     // jnz loop_start (-20 bytes)

            // print_sys_write:
            "\x4C\x89\xDA" .                 // mov rdx, r11
            "\x4C\x29\xC2" .                 // sub rdx, r8
            "\x48\x83\xC2\x01" .             // add rdx, 1 (string length)
            "\x48\xC7\xC0\x01\x00\x00\x00" . // mov rax, 1 (sys_write)
            "\x48\xC7\xC7\x01\x00\x00\x00" . // mov rdi, 1 (stdout)
            "\x4C\x89\xC6" .                 // mov rsi, r8 (buffer start)
            "\x0F\x05" .                     // syscall
            "\x4C\x89\xD4";                  // mov rsp, r10 (restore stack pointer)
    }

    public function emitPrintString(string $str): void
    {
        $dataLabelOffset = strlen($this->dataSection);
        $this->dataSection .= $str . "\n";
        $strLen = strlen($str) + 1;

        $this->textSection .= "\x48\xC7\xC0\x01\x00\x00\x00"; // mov rax, 1 (sys_write)
        $this->textSection .= "\x48\xC7\xC7\x01\x00\x00\x00"; // mov rdi, 1 (stdout)

        $this->textSection .= "\x48\xC7\xC6"; // mov rsi, imm32 (patched below)
        $this->relocations[] = ['codeOffset' => strlen($this->textSection), 'dataOffset' => $dataLabelOffset];
        $this->textSection .= pack('V', 0);

        $this->textSection .= "\x48\xC7\xC2" . pack('V', $strLen); // mov rdx, length
        $this->textSection .= "\x0F\x05"; // syscall
    }

    public function generateBinary(string $filename): void
    {
        $this->textSection .= "\x48\xC7\xC7\x00\x00\x00\x00"; // mov rdi, 0 (exit status)
        $this->textSection .= "\x48\xC7\xC0\x3C\x00\x00\x00\x0F\x05"; // sys_exit

        $entry_point = 0x400078;

        // push rbp; mov rbp, rsp; sub rsp, 256 (stack space for local variables)
        $prologue = "\x55\x48\x89\xE5\x48\x81\xEC\x00\x01\x00\x00";
        $prologueLen = strlen($prologue);

        $dataVirtualAddress = 0x400000 + 120 + $prologueLen + strlen($this->textSection);

        foreach ($this->relocations as $reloc) {
            $absoluteAddress = $dataVirtualAddress + $reloc['dataOffset'];
            $this->textSection = substr_replace($this->textSection, pack('V', $absoluteAddress), $reloc['codeOffset'], 4);
        }

        $codeSize = strlen($this->textSection);
        $fileSize = 120 + $prologueLen + $codeSize + strlen($this->dataSection);

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
            0x3E,
            1,
            $entry_point,
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

        file_put_contents($filename, $elfHeader . $programHeader . $prologue . $this->textSection . $this->dataSection);
        chmod($filename, 0755);
    }
}
