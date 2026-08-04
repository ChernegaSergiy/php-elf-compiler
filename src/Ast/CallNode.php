<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Ast;

/**
 * A function call expression: `name(args...)`.
 */
class CallNode implements AstNode
{
    /**
     * @param AstNode[] $args
     */
    public function __construct(
        public string $name,
        public array $args,
    ) {
    }
}
