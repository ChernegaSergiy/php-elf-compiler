<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Tests\CodeGen;

use ChernegaSergiy\TrypilliaCompiler\CodeGen\ByteBuffer;
use PHPUnit\Framework\TestCase;

class ByteBufferTest extends TestCase
{
    public function testPushU8MasksToByteRange(): void
    {
        $buffer = new ByteBuffer();
        $buffer->pushU8(0x1FF);

        $this->assertSame("\xFF", $buffer->bytes());
    }

    public function testPushU16LEIsLittleEndian(): void
    {
        $buffer = new ByteBuffer();
        $buffer->pushU16LE(0xABCD);

        $this->assertSame("\xCD\xAB", $buffer->bytes());
    }

    public function testPushU32LEMatchesPackVForAnOrdinaryValue(): void
    {
        $buffer = new ByteBuffer();
        $buffer->pushU32LE(0x12345678);

        $this->assertSame(pack('V', 0x12345678), $buffer->bytes());
    }

    public function testPushU32LEHandlesTheTopBitSetWithoutOverflow(): void
    {
        // The exact class of value that overflows PHP_INT_MAX on a 32-bit
        // host if combined into one number before packing (see class docs).
        $buffer = new ByteBuffer();
        $buffer->pushU32LE(0xFFFFFFFF);

        $this->assertSame("\xFF\xFF\xFF\xFF", $buffer->bytes());
    }

    public function testPushU32LEEncodesNegativeValuesAsTwosComplement(): void
    {
        $buffer = new ByteBuffer();
        $buffer->pushU32LE(-123456);

        $this->assertSame(pack('V', 0xFFFFFFFF - 123456 + 1), $buffer->bytes());
    }

    public function testPushU64LEMatchesPackPForAnOrdinaryValue(): void
    {
        $buffer = new ByteBuffer();
        $buffer->pushU64LE(0x0123456789ABCDEF);

        $this->assertSame(pack('P', 0x0123456789ABCDEF), $buffer->bytes());
    }

    public function testPushRawAppendsBytesVerbatim(): void
    {
        $buffer = new ByteBuffer();
        $buffer->pushRaw("\x0F\x05");

        $this->assertSame("\x0F\x05", $buffer->bytes());
    }

    public function testPatchU32LEOverwritesInPlaceWithoutChangingLength(): void
    {
        $buffer = new ByteBuffer();
        $buffer->pushU32LE(0);
        $buffer->pushU8(0xAA);
        $buffer->patchU32LE(0, 0xFFFFFFFF);

        $this->assertSame("\xFF\xFF\xFF\xFF\xAA", $buffer->bytes());
        $this->assertSame(5, $buffer->length());
    }

    public function testPatchRawOverwritesInPlace(): void
    {
        $buffer = new ByteBuffer();
        $buffer->pushRaw("\x00\x00");
        $buffer->patchRaw(0, "\xE9\x10");

        $this->assertSame("\xE9\x10", $buffer->bytes());
    }

    public function testLengthTracksAppendedBytes(): void
    {
        $buffer = new ByteBuffer();
        $buffer->pushU8(1);
        $buffer->pushU16LE(2);
        $buffer->pushU32LE(3);

        $this->assertSame(7, $buffer->length());
    }
}
