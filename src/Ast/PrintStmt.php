<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Ast;

/**
 * A `print expr;` statement.
 */
class PrintStmt implements AstNode
{
    public function __construct(public AstNode $expr)
    {
    }
}
