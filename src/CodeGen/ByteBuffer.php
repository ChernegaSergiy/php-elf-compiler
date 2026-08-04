<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\CodeGen;

/**
 * Appends little-endian, fixed-width encodings without ever materializing
 * a value wider than 16 bits as a single PHP number.
 *
 * This is the shared primitive described in RFC-0001 §5.1, generalizing the
 * fix originally applied ad hoc to `Arm32Emitter::w()`: on a 32-bit PHP
 * host, a hex literal or shifted/OR'd value >= 0x80000000 silently becomes
 * a float, and `pack('V', ...)` cannot losslessly cast that float back to
 * an int. Every push- and patch- method here masks its input down to the
 * width it is documented to accept before calling pack(), and combines wider
 * words out of independently-packed halves, so the same code produces
 * byte-identical output on 32-bit and 64-bit PHP hosts alike.
 */
final class ByteBuffer
{
    private string $bytes = '';

    public function pushU8(int $value): void
    {
        $this->bytes .= pack('C', $value & 0xFF);
    }

    public function pushU16LE(int $value): void
    {
        $this->bytes .= pack('v', $value & 0xFFFF);
    }

    /**
     * Pushes a 32-bit little-endian word as two independently-packed 16-bit
     * halves, so the combined 32-bit value is never formed as a single PHP
     * number (see the class docblock).
     */
    public function pushU32LE(int $value): void
    {
        $this->pushU16LE($value);
        $this->pushU16LE($value >> 16);
    }

    /**
     * Pushes a 64-bit little-endian word as two independently-packed 32-bit
     * halves, for the same reason pushU32LE() splits into 16-bit halves.
     */
    public function pushU64LE(int $value): void
    {
        $this->pushU32LE($value);
        $this->pushU32LE($value >> 32);
    }

    /**
     * Appends already-encoded raw bytes verbatim (e.g. hand-written x86
     * opcode sequences that are not themselves a numeric word).
     */
    public function pushRaw(string $bytes): void
    {
        $this->bytes .= $bytes;
    }

    /**
     * Overwrites 4 bytes at $offset with a 32-bit little-endian word, built
     * the same overflow-safe way as pushU32LE().
     */
    public function patchU32LE(int $offset, int $value): void
    {
        $low = pack('v', $value & 0xFFFF);
        $high = pack('v', ($value >> 16) & 0xFFFF);
        $this->bytes = substr_replace($this->bytes, $low . $high, $offset, 4);
    }

    /**
     * Overwrites $bytes worth of already-encoded raw bytes at $offset.
     */
    public function patchRaw(int $offset, string $bytes): void
    {
        $this->bytes = substr_replace($this->bytes, $bytes, $offset, strlen($bytes));
    }

    public function length(): int
    {
        return strlen($this->bytes);
    }

    public function bytes(): string
    {
        return $this->bytes;
    }
}
