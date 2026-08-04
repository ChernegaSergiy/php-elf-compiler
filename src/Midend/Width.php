<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Midend;

/**
 * Fixed-width integer types for the IR, per RFC-0001 §1–§3.
 *
 * Every operand flowing through the IR MUST carry one of these widths,
 * so that the backend never has to guess whether a value is 32-bit or
 * 64-bit, signed or unsigned. This is the structural fix that prevents
 * the host/target confusion class of bugs.
 */
enum Width: string
{
    case U8 = 'u8';
    case U16 = 'u16';
    case U32 = 'u32';
    case U64 = 'u64';
    case I8 = 'i8';
    case I16 = 'i16';
    case I32 = 'i32';
    case I64 = 'i64';

    public function bits(): int
    {
        return match ($this) {
            self::U8, self::I8 => 8,
            self::U16, self::I16 => 16,
            self::U32, self::I32 => 32,
            self::U64, self::I64 => 64,
        };
    }

    public function isSigned(): bool
    {
        return match ($this) {
            self::U8, self::U16, self::U32, self::U64 => false,
            self::I8, self::I16, self::I32, self::I64 => true,
        };
    }

    /**
     * Returns the signed variant of this width (e.g. U32 → I32).
     * Throws if called on a width that has no signed counterpart (not possible
     * with the current set, but kept explicit).
     */
    public function toSigned(): self
    {
        return match ($this) {
            self::U8 => self::I8,
            self::U16 => self::I16,
            self::U32 => self::I32,
            self::U64 => self::I64,
            default => throw new \LogicException("Cannot convert signed width {$this->value} to signed"),
        };
    }

    /**
     * Returns the unsigned variant of this width (e.g. I32 → U32).
     */
    public function toUnsigned(): self
    {
        return match ($this) {
            self::I8 => self::U8,
            self::I16 => self::U16,
            self::I32 => self::U32,
            self::I64 => self::U64,
            default => throw new \LogicException("Cannot convert unsigned width {$this->value} to unsigned"),
        };
    }

    /**
     * Validates that $value fits within this width (unsigned range check).
     * For signed widths, the check is against the signed range.
     */
    public function fits(int $value): bool
    {
        $bits = $this->bits();

        if ($this->isSigned()) {
            $min = -(1 << ($bits - 1));
            $max = (1 << ($bits - 1)) - 1;

            return $value >= $min && $value <= $max;
        }

        return $value >= 0 && $value <= (1 << $bits) - 1;
    }
}
