<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Tests\Midend;

use ChernegaSergiy\TrypilliaCompiler\Midend\Instruction;
use ChernegaSergiy\TrypilliaCompiler\Midend\IrGenerator;
use ChernegaSergiy\TrypilliaCompiler\Midend\Operand;
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
}
