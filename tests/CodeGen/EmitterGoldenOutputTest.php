<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Tests\CodeGen;

use ChernegaSergiy\TrypilliaCompiler\CodeGen\Arm32Emitter;
use ChernegaSergiy\TrypilliaCompiler\CodeGen\Arm64Emitter;
use ChernegaSergiy\TrypilliaCompiler\CodeGen\X86Emitter;
use PHPUnit\Framework\TestCase;

/**
 * Locks each backend emitter's `generateBinary()` output to a byte-for-byte
 * fixture captured *before* RFC-0001 §5.1/§6-step-1's ByteBuffer migration.
 *
 * This is the "golden-output test per architecture" the RFC calls for: it
 * exists specifically to prove the ByteBuffer refactor is a pure internal
 * change with zero effect on emitted bytes, on any host word width.
 */
class EmitterGoldenOutputTest extends TestCase
{
    private function goldenBytes(string $fixture): string
    {
        $encoded = file_get_contents(__DIR__ . "/../fixtures/codegen/{$fixture}");

        return base64_decode(trim($encoded), true);
    }

    private function generate(callable $build): string
    {
        $path = tempnam(sys_get_temp_dir(), 'golden_');
        $build($path);
        $bytes = file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /**
     * @param Arm32Emitter|Arm64Emitter $emitter
     */
    private function buildArmProgram($emitter, string $r0, string $r1, string $r2, string $r3, string $r4): void
    {
        $emitter->movImm($r0, 42);
        $emitter->storeLocal('x', $r0);
        $emitter->movImm($r1, -123456);
        $emitter->storeLocal('y', $r1);
        $emitter->loadLocal('x', $r2);
        $emitter->loadLocal('y', $r3);
        $emitter->add($r4, $r2, $r3);
        $emitter->cmp($r4, $r0);
        $emitter->label('loop');
        $emitter->branchIfZero($r4, 'end');
        $emitter->printNumber($r4);
        $emitter->branch('loop');
        $emitter->label('end');
        $emitter->printString('done');
    }

    public function testArm32GeneratesByteIdenticalOutputToTheGoldenFixture(): void
    {
        $emitter = new Arm32Emitter();
        $this->buildArmProgram($emitter, 'r0', 'r1', 'r2', 'r3', 'r4');

        $actual = $this->generate(fn (string $path) => $emitter->generateBinary($path));

        $this->assertSame($this->goldenBytes('arm32-golden.b64'), $actual);
    }

    public function testArm64GeneratesByteIdenticalOutputToTheGoldenFixture(): void
    {
        $emitter = new Arm64Emitter();
        $this->buildArmProgram($emitter, 'x0', 'x1', 'x2', 'x3', 'x4');

        $actual = $this->generate(fn (string $path) => $emitter->generateBinary($path));

        $this->assertSame($this->goldenBytes('arm64-golden.b64'), $actual);
    }

    public function testX86_64GeneratesByteIdenticalOutputToTheGoldenFixture(): void
    {
        $emitter = new X86Emitter();
        $emitter->movRaxImm(42);
        $emitter->pushRax();
        $emitter->movRaxImm(7);
        $emitter->popRdx();
        $emitter->addRaxRdx();
        $emitter->storeLocal('x');
        $emitter->loadLocal('x');
        $emitter->emitCmpRaxImm0();
        $offset = $emitter->emitJe_ForwardPlaceholder();
        $emitter->emitPrintNumberInRax();
        $emitter->patchForwardJump($offset);
        $emitter->emitPrintString('done');

        $actual = $this->generate(fn (string $path) => $emitter->generateBinary($path));

        $this->assertSame($this->goldenBytes('x86_64-golden.b64'), $actual);
    }
}
