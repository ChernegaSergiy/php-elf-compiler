<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Tests\Parser;

use ChernegaSergiy\TrypilliaCompiler\Ast\AssignStmt;
use ChernegaSergiy\TrypilliaCompiler\Ast\BinOpNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\BitNotNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\CallNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\FunctionDecl;
use ChernegaSergiy\TrypilliaCompiler\Ast\IfStmt;
use ChernegaSergiy\TrypilliaCompiler\Ast\LetStmt;
use ChernegaSergiy\TrypilliaCompiler\Ast\NotNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\NumberNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\PrintStmt;
use ChernegaSergiy\TrypilliaCompiler\Ast\ReturnStmt;
use ChernegaSergiy\TrypilliaCompiler\Ast\StringNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\VarNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\WhileStmt;
use ChernegaSergiy\TrypilliaCompiler\Lexer\Lexer;
use ChernegaSergiy\TrypilliaCompiler\Parser\Parser;
use PHPUnit\Framework\TestCase;

class ParserTest extends TestCase
{
    private function parse(string $source): array
    {
        return (new Parser(Lexer::run($source)))->parse();
    }

    public function testParsesLetStatement(): void
    {
        [$stmt] = $this->parse('let a = 15;');

        $this->assertInstanceOf(LetStmt::class, $stmt);
        $this->assertSame('a', $stmt->name);
        $this->assertInstanceOf(NumberNode::class, $stmt->expr);
        $this->assertSame(15, $stmt->expr->val);
    }

    public function testParsesPrintStatementWithStringLiteral(): void
    {
        [$stmt] = $this->parse('print "hi";');

        $this->assertInstanceOf(PrintStmt::class, $stmt);
        $this->assertInstanceOf(StringNode::class, $stmt->expr);
        $this->assertSame('hi', $stmt->expr->val);
    }

    public function testParsesAssignStatement(): void
    {
        [, $stmt] = $this->parse('let a = 1; a = 2;');

        $this->assertInstanceOf(AssignStmt::class, $stmt);
        $this->assertSame('a', $stmt->name);
        $this->assertInstanceOf(NumberNode::class, $stmt->expr);
        $this->assertSame(2, $stmt->expr->val);
    }

    public function testParsesWhileLoopWithBlockBody(): void
    {
        [$stmt] = $this->parse('while a < b { a = a + 1; }');

        $this->assertInstanceOf(WhileStmt::class, $stmt);
        $this->assertInstanceOf(BinOpNode::class, $stmt->condition);
        $this->assertSame('<', $stmt->condition->op);
        $this->assertCount(1, $stmt->body);
        $this->assertInstanceOf(AssignStmt::class, $stmt->body[0]);
    }

    public function testParsesBinaryExpressionWithVariables(): void
    {
        [$stmt] = $this->parse('let c = a + b;');

        $this->assertInstanceOf(BinOpNode::class, $stmt->expr);
        $this->assertInstanceOf(VarNode::class, $stmt->expr->left);
        $this->assertSame('+', $stmt->expr->op);
        $this->assertInstanceOf(VarNode::class, $stmt->expr->right);
    }

    public function testThrowsOnUnexpectedToken(): void
    {
        $this->expectException(\Exception::class);

        $this->parse('+ 1;');
    }

    public function testThrowsWhenAssignIsMissingAfterIdentifier(): void
    {
        $this->expectException(\Exception::class);

        $this->parse('let a 1;');
    }

    public function testParsesIfWithoutElse(): void
    {
        [$stmt] = $this->parse('if a < b { print a; }');

        $this->assertInstanceOf(IfStmt::class, $stmt);
        $this->assertInstanceOf(BinOpNode::class, $stmt->condition);
        $this->assertSame('<', $stmt->condition->op);
        $this->assertCount(1, $stmt->thenBody);
        $this->assertNull($stmt->elseBody);
    }

    public function testParsesIfWithElse(): void
    {
        [$stmt] = $this->parse('if a < b { print a; } else { print b; }');

        $this->assertInstanceOf(IfStmt::class, $stmt);
        $this->assertNotNull($stmt->elseBody);
        $this->assertCount(1, $stmt->thenBody);
        $this->assertCount(1, $stmt->elseBody);
    }

    public function testParsesNotExpression(): void
    {
        [$stmt] = $this->parse('let x = !a;');

        $this->assertInstanceOf(LetStmt::class, $stmt);
        $this->assertInstanceOf(NotNode::class, $stmt->expr);
        $this->assertInstanceOf(VarNode::class, $stmt->expr->expr);
    }

    public function testParsesBitwiseAnd(): void
    {
        [$stmt] = $this->parse('let x = a & b;');

        $this->assertInstanceOf(LetStmt::class, $stmt);
        $this->assertInstanceOf(BinOpNode::class, $stmt->expr);
        $this->assertSame('&', $stmt->expr->op);
        $this->assertInstanceOf(VarNode::class, $stmt->expr->left);
        $this->assertInstanceOf(VarNode::class, $stmt->expr->right);
    }

    public function testParsesBitwiseOr(): void
    {
        [$stmt] = $this->parse('let x = a | b;');

        $this->assertInstanceOf(BinOpNode::class, $stmt->expr);
        $this->assertSame('|', $stmt->expr->op);
    }

    public function testParsesBitwiseXor(): void
    {
        [$stmt] = $this->parse('let x = a ^ b;');

        $this->assertInstanceOf(BinOpNode::class, $stmt->expr);
        $this->assertSame('^', $stmt->expr->op);
    }

    public function testParsesBitwiseNot(): void
    {
        [$stmt] = $this->parse('let x = ~a;');

        $this->assertInstanceOf(LetStmt::class, $stmt);
        $this->assertInstanceOf(BitNotNode::class, $stmt->expr);
        $this->assertInstanceOf(VarNode::class, $stmt->expr->expr);
    }

    public function testParsesShiftLeft(): void
    {
        [$stmt] = $this->parse('let x = a << b;');

        $this->assertInstanceOf(BinOpNode::class, $stmt->expr);
        $this->assertSame('<<', $stmt->expr->op);
    }

    public function testParsesShiftRight(): void
    {
        [$stmt] = $this->parse('let x = a >> b;');

        $this->assertInstanceOf(BinOpNode::class, $stmt->expr);
        $this->assertSame('>>', $stmt->expr->op);
    }

    public function testParsesUnsignedShiftRight(): void
    {
        [$stmt] = $this->parse('let x = a >>> b;');

        $this->assertInstanceOf(BinOpNode::class, $stmt->expr);
        $this->assertSame('>>>', $stmt->expr->op);
    }

    public function testBitwiseNotHasHigherPrecedenceThanShift(): void
    {
        [$stmt] = $this->parse('let x = ~a << b;');

        $this->assertInstanceOf(BinOpNode::class, $stmt->expr);
        $this->assertSame('<<', $stmt->expr->op);
        $this->assertInstanceOf(BitNotNode::class, $stmt->expr->left);
        $this->assertInstanceOf(VarNode::class, $stmt->expr->right);
    }

    public function testShiftHasHigherPrecedenceThanBitwiseAnd(): void
    {
        [$stmt] = $this->parse('let x = a << b & c;');

        $this->assertInstanceOf(BinOpNode::class, $stmt->expr);
        $this->assertSame('&', $stmt->expr->op);
        $this->assertInstanceOf(BinOpNode::class, $stmt->expr->left);
        $this->assertSame('<<', $stmt->expr->left->op);
    }

    public function testBitwiseAndHasHigherPrecedenceThanBitwiseXor(): void
    {
        [$stmt] = $this->parse('let x = a & b ^ c;');

        $this->assertInstanceOf(BinOpNode::class, $stmt->expr);
        $this->assertSame('^', $stmt->expr->op);
        $this->assertInstanceOf(BinOpNode::class, $stmt->expr->left);
        $this->assertSame('&', $stmt->expr->left->op);
    }

    public function testBitwiseXorHasHigherPrecedenceThanBitwiseOr(): void
    {
        [$stmt] = $this->parse('let x = a ^ b | c;');

        $this->assertInstanceOf(BinOpNode::class, $stmt->expr);
        $this->assertSame('|', $stmt->expr->op);
        $this->assertInstanceOf(BinOpNode::class, $stmt->expr->left);
        $this->assertSame('^', $stmt->expr->left->op);
    }

    public function testAdditionHasHigherPrecedenceThanBitwiseOr(): void
    {
        [$stmt] = $this->parse('let x = a + b | c;');

        $this->assertInstanceOf(BinOpNode::class, $stmt->expr);
        $this->assertSame('|', $stmt->expr->op);
        $this->assertInstanceOf(BinOpNode::class, $stmt->expr->left);
        $this->assertSame('+', $stmt->expr->left->op);
    }

    public function testParsesFunctionDeclaration(): void
    {
        [$stmt] = $this->parse('fn add(a: i64, b: i64) -> i64 { return a + b; }');

        $this->assertInstanceOf(FunctionDecl::class, $stmt);
        $this->assertSame('add', $stmt->name);
        $this->assertCount(2, $stmt->params);
        $this->assertSame('a', $stmt->params[0]['name']);
        $this->assertSame('i64', $stmt->params[0]['type']);
        $this->assertSame('b', $stmt->params[1]['name']);
        $this->assertSame('i64', $stmt->params[1]['type']);
        $this->assertSame('i64', $stmt->returnType);
        $this->assertCount(1, $stmt->body);
        $this->assertInstanceOf(ReturnStmt::class, $stmt->body[0]);
    }

    public function testParsesFunctionWithNoParams(): void
    {
        [$stmt] = $this->parse('fn main() { print 42; }');

        $this->assertInstanceOf(FunctionDecl::class, $stmt);
        $this->assertSame('main', $stmt->name);
        $this->assertCount(0, $stmt->params);
        $this->assertSame('void', $stmt->returnType);
        $this->assertCount(1, $stmt->body);
    }

    public function testParsesReturnWithExpression(): void
    {
        [$stmt] = $this->parse('fn f() -> i64 { return 42; }');

        $this->assertInstanceOf(FunctionDecl::class, $stmt);
        $returnStmt = $stmt->body[0];
        $this->assertInstanceOf(ReturnStmt::class, $returnStmt);
        $this->assertInstanceOf(NumberNode::class, $returnStmt->expr);
        $this->assertSame(42, $returnStmt->expr->val);
    }

    public function testParsesReturnWithoutExpression(): void
    {
        [$stmt] = $this->parse('fn f() { return; }');

        $this->assertInstanceOf(FunctionDecl::class, $stmt);
        $returnStmt = $stmt->body[0];
        $this->assertInstanceOf(ReturnStmt::class, $returnStmt);
        $this->assertNull($returnStmt->expr);
    }

    public function testParsesFunctionCall(): void
    {
        [$stmt] = $this->parse('let x = add(1, 2);');

        $this->assertInstanceOf(LetStmt::class, $stmt);
        $this->assertInstanceOf(CallNode::class, $stmt->expr);
        $this->assertSame('add', $stmt->expr->name);
        $this->assertCount(2, $stmt->expr->args);
        $this->assertInstanceOf(NumberNode::class, $stmt->expr->args[0]);
        $this->assertInstanceOf(NumberNode::class, $stmt->expr->args[1]);
    }

    public function testParsesFunctionCallWithNoArgs(): void
    {
        [$stmt] = $this->parse('let x = main();');

        $this->assertInstanceOf(CallNode::class, $stmt->expr);
        $this->assertSame('main', $stmt->expr->name);
        $this->assertCount(0, $stmt->expr->args);
    }

    public function testParsesFunctionCallInPrint(): void
    {
        [$stmt] = $this->parse('print add(1, 2);');

        $this->assertInstanceOf(PrintStmt::class, $stmt);
        $this->assertInstanceOf(CallNode::class, $stmt->expr);
    }
}
