<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Tests\CodeGen;

use ChernegaSergiy\TrypilliaCompiler\CodeGen\X86Emitter;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class X86EmitterTest extends TestCase
{
    private function textSectionOf(X86Emitter $emitter): string
    {
        $property = new ReflectionProperty(X86Emitter::class, 'text');

        return $property->getValue($emitter)->bytes();
    }

    public function testMovRaxImmEmitsExpectedOpcode(): void
    {
        $emitter = new X86Emitter();
        $emitter->movRaxImm(42);

        $this->assertSame("\x48\xC7\xC0" . pack('V', 42), $this->textSectionOf($emitter));
    }

    public function testPushAndPopEmitSingleByteOpcodes(): void
    {
        $emitter = new X86Emitter();
        $emitter->pushRax();
        $emitter->popRdx();

        $this->assertSame("\x50\x5A", $this->textSectionOf($emitter));
    }

    public function testAddRaxRdxEmitsExpectedOpcode(): void
    {
        $emitter = new X86Emitter();
        $emitter->addRaxRdx();

        $this->assertSame("\x48\x01\xD0", $this->textSectionOf($emitter));
    }

    public function testStoreAndLoadLocalReuseTheSameStackSlot(): void
    {
        $emitter = new X86Emitter();
        $emitter->storeLocal('a');
        $emitter->loadLocal('a');

        // First local gets offset -8 -> chr(256 - 8) = chr(248).
        $expected = "\x48\x89\x45" . chr(248) . "\x48\x8B\x45" . chr(248);
        $this->assertSame($expected, $this->textSectionOf($emitter));
    }

    public function testStoreLocalAssignsIncreasingStackOffsetsPerVariable(): void
    {
        $emitter = new X86Emitter();
        $emitter->storeLocal('a');
        $emitter->storeLocal('b');

        $expected = "\x48\x89\x45" . chr(248) . "\x48\x89\x45" . chr(240);
        $this->assertSame($expected, $this->textSectionOf($emitter));
    }

    public function testLoadLocalThrowsForUnknownVariable(): void
    {
        $this->expectException(\Exception::class);

        (new X86Emitter())->loadLocal('missing');
    }

    public function testForwardJumpPlaceholderIsPatchedWithCorrectRelativeOffset(): void
    {
        $emitter = new X86Emitter();
        $patchOffset = $emitter->emitJe_ForwardPlaceholder();
        $emitter->addRaxRdx(); // 3 bytes of "loop body" between the jump and its target
        $emitter->patchForwardJump($patchOffset);

        $textSection = $this->textSectionOf($emitter);
        $rel = unpack('V', substr($textSection, $patchOffset, 4))[1];

        $this->assertSame(3, $rel);
    }

    public function testGenerateBinaryProducesAValidElfHeader(): void
    {
        $emitter = new X86Emitter();
        $emitter->movRaxImm(1);

        $path = tempnam(sys_get_temp_dir(), 'trypillia_test_');
        $emitter->generateBinary($path);

        $binary = file_get_contents($path);
        $permissions = fileperms($path) & 0777;
        unlink($path);

        $this->assertSame("\x7FELF", substr($binary, 0, 4));
        $this->assertSame(2, ord($binary[4])); // ELFCLASS64
        $this->assertSame(0755, $permissions);
    }
}
