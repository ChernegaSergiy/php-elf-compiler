<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Cli;

use ChernegaSergiy\TrypilliaCompiler\Backend\Architecture;
use ChernegaSergiy\TrypilliaCompiler\Compiler;
use ChernegaSergiy\TrypilliaCompiler\Lexer\Lexer;
use ChernegaSergiy\TrypilliaCompiler\Parser\Parser;
use Exception;

/**
 * Command-line front end for the Trypillia compiler.
 */
class CompilerApplication
{
    /**
     * @param string[] $argv
     */
    public function run(array $argv): int
    {
        if (php_sapi_name() !== 'cli') {
            echo "Must be run from the terminal.\n";

            return 1;
        }

        $args = array_slice($argv, 1);
        if (empty($args) || in_array('--help', $args, true) || in_array('-h', $args, true)) {
            echo "Trypillia AOT Compiler v0.3\n";
            echo "Usage: trypillia <file.try> [-o <app>] [-arch <x86|x86_64|arm32|arm64>]\n";

            return 0;
        }

        $inputFile = $args[0];
        if (!file_exists($inputFile)) {
            echo "trypillia: $inputFile: No such file or directory\n";

            return 1;
        }

        $oIndex = array_search('-o', $args, true);
        if ($oIndex !== false && isset($args[$oIndex + 1])) {
            $outputFile = $args[$oIndex + 1];
        } else {
            $outputFile = pathinfo($inputFile, PATHINFO_FILENAME) ?: 'app';
        }

        $archIndex = array_search('-arch', $args, true);
        if ($archIndex === false) {
            $archIndex = array_search('-a', $args, true);
        }

        if ($archIndex !== false && isset($args[$archIndex + 1])) {
            $architecture = Architecture::tryFrom($args[$archIndex + 1]);
            if ($architecture === null) {
                echo "trypillia: unknown architecture '{$args[$archIndex + 1]}'\n";
                echo "trypillia: supported: x86, x86_64, arm32, arm64\n";

                return 1;
            }
        } else {
            $architecture = $this->detectHostArchitecture();
        }

        $source = file_get_contents($inputFile);
        echo "Compiling $inputFile -> ./$outputFile\n";
        $startTime = microtime(true);

        try {
            $tokens = Lexer::run($source);
            $parser = new Parser($tokens);
            $ast = $parser->parse();
            Compiler::compile($ast, $outputFile, $architecture);

            $timeTaken = round((microtime(true) - $startTime) * 1000, 2);
            echo "Built in {$timeTaken} ms ({$architecture->value})\n";

            return 0;
        } catch (Exception $e) {
            echo "trypillia: error: " . $e->getMessage() . "\n";

            return 1;
        }
    }

    /**
     * Maps the output of php_uname('m') to a compiler Architecture, so that
     * running the compiler without -arch produces a binary native to the
     * current host instead of always defaulting to x86_64.
     */
    private function detectHostArchitecture(): Architecture
    {
        $machine = strtolower(php_uname('m'));

        return match (true) {
            in_array($machine, ['x86_64', 'amd64'], true) => Architecture::X86_64,
            in_array($machine, ['i386', 'i486', 'i586', 'i686', 'x86'], true) => Architecture::X86,
            in_array($machine, ['aarch64', 'arm64'], true) => Architecture::ARM64,
            str_starts_with($machine, 'armv7') || str_starts_with($machine, 'arm') => Architecture::ARM32,
            default => Architecture::X86_64,
        };
    }
}
