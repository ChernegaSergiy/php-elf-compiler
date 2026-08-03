<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Tests;

use ChernegaSergiy\TrypilliaCompiler\Compiler;
use ChernegaSergiy\TrypilliaCompiler\Lexer\Lexer;
use ChernegaSergiy\TrypilliaCompiler\Parser\Parser;
use PHPUnit\Framework\TestCase;

/**
 * Compiles a Trypillia source file into a real ELF binary and runs it,
 * verifying the full lexer -> parser -> code generator pipeline end to end.
 */
class CompilerIntegrationTest extends TestCase
{
    private string $binaryPath;

    protected function setUp(): void
    {
        if (php_uname('m') !== 'x86_64') {
            $this->markTestSkipped('Compiler integration tests currently execute x86_64 binaries only.');
        }

        $this->binaryPath = tempnam(sys_get_temp_dir(), 'trypillia_bin_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->binaryPath)) {
            unlink($this->binaryPath);
        }
    }

    private function compile(string $source): void
    {
        $tokens = Lexer::run($source);
        $ast = (new Parser($tokens))->parse();
        Compiler::compile($ast, $this->binaryPath);
    }

    public function testCompilesAndRunsAStringPrintProgram(): void
    {
        $this->compile('print "hello, trypillia";');

        $output = shell_exec(escapeshellarg($this->binaryPath));

        $this->assertSame("hello, trypillia\n", $output);
    }

    public function testCompilesAndRunsAWhileLoopPrintingNumbers(): void
    {
        $this->compile('
            let a = 0;
            let max = 3;
            while a < max {
                print a;
                a = a + 1;
            }
        ');

        $output = shell_exec(escapeshellarg($this->binaryPath));

        $this->assertSame("0\n1\n2\n", $output);
    }

    public function testCompilesAndRunsTheFibonacciExample(): void
    {
        $source = file_get_contents(__DIR__ . '/../examples/fibonacci.try');
        $this->compile($source);

        $output = shell_exec(escapeshellarg($this->binaryPath));

        $expected = "Обчислення Фібоначчі:\n1\n1\n2\n3\n5\n8\n13\n21\n34\n55\n89\nЗавершено!\n";
        $this->assertSame($expected, $output);
    }

    public function testCompilesAndRunsIfElseBranching(): void
    {
        $this->compile('
            let x = 10;
            if x < 20 {
                print "less";
            } else {
                print "greater";
            }
        ');

        $output = shell_exec(escapeshellarg($this->binaryPath));

        $this->assertSame("less\n", $output);
    }
}
