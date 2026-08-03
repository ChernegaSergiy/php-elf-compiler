<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Ast;

/**
 * A string literal.
 */
class StringNode implements AstNode
{
    public function __construct(public string $val)
    {
    }
}
