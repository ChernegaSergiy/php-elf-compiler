<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler;

use ChernegaSergiy\TrypilliaCompiler\Ast\AstNode;
use ChernegaSergiy\TrypilliaCompiler\Backend\Arm32BackendEmitter;
use ChernegaSergiy\TrypilliaCompiler\Backend\Arm64BackendEmitter;
use ChernegaSergiy\TrypilliaCompiler\Backend\Architecture;
use ChernegaSergiy\TrypilliaCompiler\Backend\BackendEmitter;
use ChernegaSergiy\TrypilliaCompiler\Backend\X86BackendEmitter;
use ChernegaSergiy\TrypilliaCompiler\Midend\IrGenerator;
use Exception;

/**
 * Compiles a Trypillia AST into an architecture-agnostic IR and passes it to a backend.
 */
class Compiler
{
    /**
     * @param AstNode[] $ast
     */
    public static function compile(
        array $ast,
        string $filename,
        Architecture $architecture = Architecture::X86_64,
    ): void {
        $program = (new IrGenerator())->generate($ast);
        $backend = self::resolveBackend($architecture);

        $backend->emit($program, $filename);
    }

    private static function resolveBackend(Architecture $architecture): BackendEmitter
    {
        return match ($architecture) {
            Architecture::X86_64 => new X86BackendEmitter(),
            Architecture::ARM64 => new Arm64BackendEmitter(),
            Architecture::ARM32 => new Arm32BackendEmitter(),
            default => throw new Exception("Unsupported architecture backend: {$architecture->value}"),
        };
    }
}
