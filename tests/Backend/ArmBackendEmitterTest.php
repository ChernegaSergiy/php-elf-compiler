<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Tests\Backend;

use ChernegaSergiy\TrypilliaCompiler\Backend\Arm32BackendEmitter;
use ChernegaSergiy\TrypilliaCompiler\Backend\Arm64BackendEmitter;
use ChernegaSergiy\TrypilliaCompiler\Midend\Instruction;
use ChernegaSergiy\TrypilliaCompiler\Midend\Program;
use PHPUnit\Framework\TestCase;

/**
 * Emits real ARM32/ARM64 ELF binaries and, where a user-mode QEMU
 * interpreter is available on PATH, actually executes them to verify the
 * generated machine code end to end.
 */
class ArmBackendEmitterTest extends TestCase
{
    private function simpleProgram(): Program
    {
        $program = new Program();
        $program->add(new Instruction('const_int', '%1', [42]));
        $program->add(new Instruction('store_var', null, ['x', '%1']));
        $program->add(new Instruction('load_var', '%2', ['x']));
        $program->add(new Instruction('print_num', null, ['%2']));

        return $program;
    }

    private function negativeNumberProgram(): Program
    {
        $program = new Program();
        $program->add(new Instruction('const_int', '%1', [-123456]));
        $program->add(new Instruction('store_var', null, ['x', '%1']));
        $program->add(new Instruction('load_var', '%2', ['x']));
        $program->add(new Instruction('print_num', null, ['%2']));

        return $program;
    }

    private function runUnderQemu(string $interpreter, string $binaryPath): ?string
    {
        $resolved = trim((string) shell_exec('command -v ' . escapeshellarg($interpreter) . ' 2>/dev/null'));
        if ($resolved === '') {
            return null;
        }

        return shell_exec($resolved . ' ' . escapeshellarg($binaryPath));
    }

    public function testArm64BackendProducesBinary(): void
    {
        $filename = tempnam(sys_get_temp_dir(), 'arm64_test_') . '.bin';
        (new Arm64BackendEmitter())->emit($this->simpleProgram(), $filename);

        $this->assertFileExists($filename);

        $output = $this->runUnderQemu('qemu-aarch64', $filename);
        if ($output === null) {
            $this->markTestSkipped('qemu-aarch64 is not available to execute the ARM64 binary.');
        }

        $this->assertSame("42\n", $output);
    }

    public function testArm64BackendPrintsNegativeNumbers(): void
    {
        $filename = tempnam(sys_get_temp_dir(), 'arm64_neg_test_') . '.bin';
        (new Arm64BackendEmitter())->emit($this->negativeNumberProgram(), $filename);

        $output = $this->runUnderQemu('qemu-aarch64', $filename);
        if ($output === null) {
            $this->markTestSkipped('qemu-aarch64 is not available to execute the ARM64 binary.');
        }

        $this->assertSame("-123456\n", $output);
    }

    public function testArm32BackendProducesBinary(): void
    {
        $filename = tempnam(sys_get_temp_dir(), 'arm32_test_') . '.bin';
        (new Arm32BackendEmitter())->emit($this->simpleProgram(), $filename);

        $this->assertFileExists($filename);

        $output = $this->runUnderQemu('qemu-arm', $filename);
        if ($output === null) {
            $this->markTestSkipped('qemu-arm is not available to execute the ARM32 binary.');
        }

        $this->assertSame("42\n", $output);
    }

    public function testArm32BackendPrintsNegativeNumbers(): void
    {
        $filename = tempnam(sys_get_temp_dir(), 'arm32_neg_test_') . '.bin';
        (new Arm32BackendEmitter())->emit($this->negativeNumberProgram(), $filename);

        $output = $this->runUnderQemu('qemu-arm', $filename);
        if ($output === null) {
            $this->markTestSkipped('qemu-arm is not available to execute the ARM32 binary.');
        }

        $this->assertSame("-123456\n", $output);
    }
}

