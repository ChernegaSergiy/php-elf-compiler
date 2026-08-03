<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Lexer;

/**
 * A single lexical token: its kind and the raw source text it was read from.
 */
class Token
{
    public function __construct(
        public TokenType $type,
        public string $value,
    ) {
    }
}
