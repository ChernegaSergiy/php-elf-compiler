<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Tests\Midend;

use ChernegaSergiy\TrypilliaCompiler\Midend\Instruction;
use ChernegaSergiy\TrypilliaCompiler\Midend\IrGenerator;
use ChernegaSergiy\TrypilliaCompiler\Midend\Operand;
use ChernegaSergiy\TrypilliaCompiler\Midend\Program;
use ChernegaSergiy\TrypilliaCompiler\Lexer\Lexer;
use ChernegaSergiy\TrypilliaCompiler\Parser\Parser;
use PHPUnit\Framework\TestCase;

class IrGeneratorTest extends TestCase
{
    /**
     * @return array<int, array{opcode: string, result: ?string, operands: array<int, int|string>}>
     */
    private function generateInstructions(string $source): array
    {
        $ast = (new Parser(Lexer::run($source)))->parse();
        $program = (new IrGenerator())->generate($ast);

        return array_map(
            static fn (Instruction $instruction): array => [
                'opcode' => $instruction->opcode,
                'result' => $instruction->result,
                'operands' => array_map(
                    static fn (Operand $operand): int|string => $operand->value,
                    $instruction->operands
                ),
            ],
            $program->instructions
        );
    }

    public function testGeneratesThreeAddressInstructionsForArithmeticAndStore(): void
    {
        $instructions = $this->generateInstructions('let sum = a + b; print sum;');

        $this->assertSame(
            [
                ['opcode' => 'load_var', 'result' => '%1', 'operands' => ['a']],
                ['opcode' => 'load_var', 'result' => '%2', 'operands' => ['b']],
                ['opcode' => 'add_int', 'result' => '%3', 'operands' => ['%1', '%2']],
                ['opcode' => 'store_var', 'result' => null, 'operands' => ['sum', '%3']],
                ['opcode' => 'load_var', 'result' => '%4', 'operands' => ['sum']],
                ['opcode' => 'print_num', 'result' => null, 'operands' => ['%4']],
            ],
            $instructions
        );
    }

    public function testGeneratesLabelsAndJumpsForIfElseAndWhile(): void
    {
        $instructions = $this->generateInstructions('
            let a = 0;
            while a < 2 {
                if a == 1 {
                    print "one";
                } else {
                    print "other";
                }
                a = a + 1;
            }
        ');

        $opcodes = array_column($instructions, 'opcode');

        $this->assertContains('label', $opcodes);
        $this->assertContains('jump_if_zero', $opcodes);
        $this->assertContains('jump', $opcodes);
        $this->assertContains('print_str', $opcodes);
    }

    public function testGeneratesBitwiseAndOpcode(): void
    {
        $instructions = $this->generateInstructions('let x = a & b;');

        $opcodes = array_column($instructions, 'opcode');
        $this->assertContains('bit_and', $opcodes);
    }

    public function testGeneratesBitwiseOrOpcode(): void
    {
        $instructions = $this->generateInstructions('let x = a | b;');

        $opcodes = array_column($instructions, 'opcode');
        $this->assertContains('bit_or', $opcodes);
    }

    public function testGeneratesBitwiseXorOpcode(): void
    {
        $instructions = $this->generateInstructions('let x = a ^ b;');

        $opcodes = array_column($instructions, 'opcode');
        $this->assertContains('bit_xor', $opcodes);
    }

    public function testGeneratesBitwiseNotOpcode(): void
    {
        $instructions = $this->generateInstructions('let x = ~a;');

        $opcodes = array_column($instructions, 'opcode');
        $this->assertContains('bit_not', $opcodes);
    }

    public function testGeneratesShiftLeftOpcode(): void
    {
        $instructions = $this->generateInstructions('let x = a << b;');

        $opcodes = array_column($instructions, 'opcode');
        $this->assertContains('shl', $opcodes);
    }

    public function testGeneratesShiftRightOpcode(): void
    {
        $instructions = $this->generateInstructions('let x = a >> b;');

        $opcodes = array_column($instructions, 'opcode');
        $this->assertContains('shr', $opcodes);
    }

    public function testGeneratesUnsignedShiftRightOpcode(): void
    {
        $instructions = $this->generateInstructions('let x = a >>> b;');

        $opcodes = array_column($instructions, 'opcode');
        $this->assertContains('shr_u', $opcodes);
    }

    public function testGeneratesParamOpcodesForFunction(): void
    {
        $program = $this->generateProgram('fn add(a: i64, b: i64) -> i64 { return a + b; }');

        $this->assertArrayHasKey('add', $program->functions);
        $opcodes = array_column($program->functions['add'], 'opcode');
        $this->assertContains('param', $opcodes);
    }

    public function testGeneratesRetOpcodeForFunction(): void
    {
        $program = $this->generateProgram('fn add(a: i64, b: i64) -> i64 { return a + b; }');

        $opcodes = array_column($program->functions['add'], 'opcode');
        $this->assertContains('ret', $opcodes);
    }

    public function testGeneratesCallOpcode(): void
    {
        $instructions = $this->generateInstructions('let x = add(1, 2);');

        $opcodes = array_column($instructions, 'opcode');
        $this->assertContains('call', $opcodes);
    }

    public function testGeneratesArgOpcodes(): void
    {
        $instructions = $this->generateInstructions('let x = add(1, 2);');

        $opcodes = array_column($instructions, 'opcode');
        $argCount = count(array_filter($opcodes, static fn (string $op): bool => $op === 'arg'));
        $this->assertSame(2, $argCount);
    }

    public function testMainProgramInstructionsAreSeparateFromFunctions(): void
    {
        $program = $this->generateProgram('fn add(a: i64, b: i64) -> i64 { return a + b; } let x = add(1, 2);');

        $this->assertArrayHasKey('add', $program->functions);
        $this->assertNotEmpty($program->instructions);
        $mainOpcodes = array_column($program->instructions, 'opcode');
        $this->assertContains('call', $mainOpcodes);
    }

    private function generateProgram(string $source): Program
    {
        $ast = (new Parser(Lexer::run($source)))->parse();

        return (new IrGenerator())->generate($ast);
    }
}
