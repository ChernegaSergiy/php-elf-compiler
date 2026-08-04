<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Midend;

use InvalidArgumentException;

/**
 * A typed IR operand: a width-tagged value that is either a literal integer
 * or a named temporary/label.
 *
 * This is the core of RFC-0001 §4: every value flowing through the IR carries
 * an explicit Width, so the backend never has to guess whether a constant is
 * 32-bit or 64-bit, signed or unsigned.
 */
final class Operand
{
    /**
     * @param Width     $width  The bit-width and signedness of this operand.
     * @param int|string $value An integer literal (range-checked against $width)
     *                          or a string name (temp like "%1" or label like "while_start_1").
     */
    public function __construct(
        public readonly Width $width,
        public readonly int|string $value,
    ) {
        if (is_int($value) && !$width->fits($value)) {
            throw new InvalidArgumentException(
                "Value {$value} does not fit in {$width->value}"
            );
        }
    }

    public static function constInt(Width $width, int $value): self
    {
        return new self($width, $value);
    }

    public static function temp(Width $width, string $name): self
    {
        return new self($width, $name);
    }

    public static function label(string $name): self
    {
        return new self(Width::U64, $name);
    }

    public function isLiteral(): bool
    {
        return is_int($this->value);
    }

    public function isTemp(): bool
    {
        return is_string($this->value);
    }
}
