<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Ast;

/**
 * An integer literal.
 */
class NumberNode implements AstNode
{
    public function __construct(public int $val)
    {
    }
}
