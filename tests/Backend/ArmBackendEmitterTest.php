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

    /**
     * Runs a binary natively when the current CI/host machine already
     * matches the target architecture (e.g. an arm64 runner), falling back
     * to a user-mode QEMU interpreter, if one is available, otherwise.
     *
     * @param string[] $nativeMachineTypes `php_uname('m')` values considered a native match
     */
    private function runBinary(string $binaryPath, array $nativeMachineTypes, string $qemuInterpreter): ?string
    {
        if (in_array(php_uname('m'), $nativeMachineTypes, true)) {
            chmod($binaryPath, 0755);

            return shell_exec(escapeshellarg($binaryPath));
        }

        return $this->runUnderQemu($qemuInterpreter, $binaryPath);
    }

    public function testArm64BackendProducesBinary(): void
    {
        $filename = tempnam(sys_get_temp_dir(), 'arm64_test_') . '.bin';
        (new Arm64BackendEmitter())->emit($this->simpleProgram(), $filename);

        $this->assertFileExists($filename);

        $output = $this->runBinary($filename, ['aarch64', 'arm64'], 'qemu-aarch64');
        if ($output === null) {
            $this->markTestSkipped('Neither a native aarch64 host nor qemu-aarch64 is available to run the ARM64 binary.');
        }

        $this->assertSame("42\n", $output);
    }

    public function testArm64BackendPrintsNegativeNumbers(): void
    {
        $filename = tempnam(sys_get_temp_dir(), 'arm64_neg_test_') . '.bin';
        (new Arm64BackendEmitter())->emit($this->negativeNumberProgram(), $filename);

        $output = $this->runBinary($filename, ['aarch64', 'arm64'], 'qemu-aarch64');
        if ($output === null) {
            $this->markTestSkipped('Neither a native aarch64 host nor qemu-aarch64 is available to run the ARM64 binary.');
        }

        $this->assertSame("-123456\n", $output);
    }

    public function testArm32BackendProducesBinary(): void
    {
        $filename = tempnam(sys_get_temp_dir(), 'arm32_test_') . '.bin';
        (new Arm32BackendEmitter())->emit($this->simpleProgram(), $filename);

        $this->assertFileExists($filename);

        $output = $this->runBinary($filename, ['armv7l', 'armv8l', 'arm'], 'qemu-arm');
        if ($output === null) {
            $this->markTestSkipped('Neither a native armv7 host nor qemu-arm is available to run the ARM32 binary.');
        }

        $this->assertSame("42\n", $output);
    }

    public function testArm32BackendPrintsNegativeNumbers(): void
    {
        $filename = tempnam(sys_get_temp_dir(), 'arm32_neg_test_') . '.bin';
        (new Arm32BackendEmitter())->emit($this->negativeNumberProgram(), $filename);

        $output = $this->runBinary($filename, ['armv7l', 'armv8l', 'arm'], 'qemu-arm');
        if ($output === null) {
            $this->markTestSkipped('Neither a native armv7 host nor qemu-arm is available to run the ARM32 binary.');
        }

        $this->assertSame("-123456\n", $output);
    }
}

