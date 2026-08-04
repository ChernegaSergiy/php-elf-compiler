<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Ast;

/**
 * A function declaration: `fn name(param: type, ...) -> returnType { body }`.
 */
class FunctionDecl implements AstNode
{
    /**
     * @param array<int, array{name: string, type: string}> $params
     * @param AstNode[] $body
     */
    public function __construct(
        public string $name,
        public array $params,
        public string $returnType,
        public array $body,
    ) {
    }
}
