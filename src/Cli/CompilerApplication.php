<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Cli;

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
            echo "Usage: trypillia <file.try> [-o <app>]\n";

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

        $source = file_get_contents($inputFile);
        echo "Compiling $inputFile -> ./$outputFile\n";
        $startTime = microtime(true);

        try {
            $tokens = Lexer::run($source);
            $parser = new Parser($tokens);
            $ast = $parser->parse();
            Compiler::compile($ast, $outputFile);

            $timeTaken = round((microtime(true) - $startTime) * 1000, 2);
            echo "Built in {$timeTaken} ms\n";

            return 0;
        } catch (Exception $e) {
            echo "trypillia: error: " . $e->getMessage() . "\n";

            return 1;
        }
    }
}
