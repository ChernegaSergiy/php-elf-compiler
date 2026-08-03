<?php

// ==========================================
// 1. ТОКЕНИ ТА ПОСИМВОЛЬНИЙ ЛЕКСЕР
// ==========================================
enum TokenType {
    case LET; case PRINT; case WHILE; case IDENTIFIER; case NUMBER; case STRING;
    case ASSIGN; case PLUS; case MINUS; case MULT; case LESS; case GREATER;
    case SEMICOLON; case LBRACE; case RBRACE; case EOF;
}

class Token {
    public function __construct(public TokenType $type, public string $value) {}
}

class Lexer {
    private int $pos = 0;
    private array $tokens = [];
    private int $length;

    public function __construct(private string $source) {
        $this->length = strlen($source);
    }

    public static function run(string $source): array {
        $lexer = new self($source);
        return $lexer->tokenize();
    }

    private function tokenize(): array {
        while ($this->pos < $this->length) {
            $c = $this->source[$this->pos++];
            if (ctype_space($c)) continue;

            if ($c === '=') $this->tokens[] = new Token(TokenType::ASSIGN, '=');
            elseif ($c === '+') $this->tokens[] = new Token(TokenType::PLUS, '+');
            elseif ($c === '-') $this->tokens[] = new Token(TokenType::MINUS, '-');
            elseif ($c === '*') $this->tokens[] = new Token(TokenType::MULT, '*');
            elseif ($c === '<') $this->tokens[] = new Token(TokenType::LESS, '<');
            elseif ($c === '>') $this->tokens[] = new Token(TokenType::GREATER, '>');
            elseif ($c === '{') $this->tokens[] = new Token(TokenType::LBRACE, '{');
            elseif ($c === '}') $this->tokens[] = new Token(TokenType::RBRACE, '}');
            elseif ($c === ';') $this->tokens[] = new Token(TokenType::SEMICOLON, ';');
            elseif ($c === '"') {
                $start = $this->pos;
                while ($this->pos < $this->length && $this->source[$this->pos] !== '"') $this->pos++;
                $val = substr($this->source, $start, $this->pos - $start);
                $this->pos++;
                $this->tokens[] = new Token(TokenType::STRING, $val);
            } elseif (ctype_digit($c)) {
                $val = $c;
                while ($this->pos < $this->length && ctype_digit($this->source[$this->pos])) {
                    $val .= $this->source[$this->pos++];
                }
                $this->tokens[] = new Token(TokenType::NUMBER, $val);
            } elseif (ctype_alpha($c) || $c === '_') {
                $val = $c;
                while ($this->pos < $this->length && (ctype_alnum($this->source[$this->pos]) || $this->source[$this->pos] === '_')) {
                    $val .= $this->source[$this->pos++];
                }
                $type = match($val) {
                    'let' => TokenType::LET,
                    'print' => TokenType::PRINT,
                    'while' => TokenType::WHILE,
                    default => TokenType::IDENTIFIER
                };
                $this->tokens[] = new Token($type, $val);
            } else {
                throw new Exception("Невідомий символ: $c");
            }
        }
        $this->tokens[] = new Token(TokenType::EOF, "");
        return $this->tokens;
    }
}

// ==========================================
// 2. AST ТА ПАРСЕР
// ==========================================
interface AstNode {}
class NumberNode implements AstNode { public function __construct(public int $val) {} }
class StringNode implements AstNode { public function __construct(public string $val) {} }
class VarNode implements AstNode { public function __construct(public string $name) {} }
class BinOpNode implements AstNode { public function __construct(public AstNode $left, public string $op, public AstNode $right) {} }
class LetStmt implements AstNode { public function __construct(public string $name, public AstNode $expr) {} }
class AssignStmt implements AstNode { public function __construct(public string $name, public AstNode $expr) {} }
class PrintStmt implements AstNode { public function __construct(public AstNode $expr) {} }
class WhileStmt implements AstNode { public function __construct(public AstNode $condition, public array $body) {} }

class Parser {
    private int $pos = 0;
    public function __construct(private array $tokens) {}

    private function peek(): Token { return $this->tokens[$this->pos]; }
    private function consume(): Token { return $this->tokens[$this->pos++]; }
    private function expect(TokenType $type): Token {
        $tok = $this->consume();
        if ($tok->type !== $type) throw new Exception("Очікувався токен {$type->name}, отримано {$tok->type->name}");
        return $tok;
    }

    public function parse(): array {
        $stmts = [];
        while ($this->peek()->type !== TokenType::EOF) {
            $stmts[] = $this->parseStmt();
        }
        return $stmts;
    }

    private function parseStmt(): AstNode {
        $tok = $this->peek();
        
        if ($tok->type === TokenType::LET) {
            $this->consume();
            $name = $this->expect(TokenType::IDENTIFIER)->value;
            $this->expect(TokenType::ASSIGN);
            $expr = $this->parseExpr();
            $this->expect(TokenType::SEMICOLON);
            return new LetStmt($name, $expr);
            
        } elseif ($tok->type === TokenType::PRINT) {
            $this->consume();
            $expr = $this->parseExpr();
            $this->expect(TokenType::SEMICOLON);
            return new PrintStmt($expr);
            
        } elseif ($tok->type === TokenType::WHILE) {
            $this->consume();
            $condition = $this->parseExpr();
            $this->expect(TokenType::LBRACE);
            $body = [];
            while ($this->peek()->type !== TokenType::RBRACE) {
                $body[] = $this->parseStmt();
            }
            $this->consume(); // }
            return new WhileStmt($condition, $body);
            
        } elseif ($tok->type === TokenType::IDENTIFIER) {
            $name = $this->consume()->value;
            $this->expect(TokenType::ASSIGN);
            $expr = $this->parseExpr();
            $this->expect(TokenType::SEMICOLON);
            return new AssignStmt($name, $expr);
            
        } else {
            throw new Exception("Неочікуваний токен: " . $tok->value);
        }
    }

    private function parseExpr(): AstNode {
        $tok = $this->consume();
        $node = match($tok->type) {
            TokenType::NUMBER => new NumberNode((int)$tok->value),
            TokenType::STRING => new StringNode($tok->value),
            TokenType::IDENTIFIER => new VarNode($tok->value),
            default => throw new Exception("Помилка виразу: " . $tok->value)
        };

        if (in_array($this->peek()->type, [TokenType::PLUS, TokenType::MINUS, TokenType::MULT, TokenType::LESS, TokenType::GREATER])) {
            $opTok = $this->consume();
            $right = $this->parseExpr();
            $node = new BinOpNode($node, $opTok->value, $right);
        }
        return $node;
    }
}

// ==========================================
// 3. X86_64 EMMITER (Асемблер)
// ==========================================
class X86Emitter {
    private string $textSection = "";
    private string $dataSection = "";
    private array $symbols = []; 
    private int $stackOffset = 0;
    private array $relocations = []; 

    public function movRaxImm(int $val): void { $this->textSection .= "\x48\xC7\xC0" . pack('V', $val); }
    public function pushRax(): void { $this->textSection .= "\x50"; }
    public function popRdx(): void { $this->textSection .= "\x5A"; }
    public function addRaxRdx(): void { $this->textSection .= "\x48\x01\xD0"; }

    public function storeLocal(string $name): void {
        if (!isset($this->symbols[$name])) {
            $this->stackOffset += 8;
            $this->symbols[$name] = -$this->stackOffset;
        }
        $offset = $this->symbols[$name];
        $this->textSection .= "\x48\x89\x45" . chr(256 + $offset);
    }

    public function loadLocal(string $name): void {
        $offset = $this->symbols[$name] ?? throw new Exception("Невідома змінна: $name");
        $this->textSection .= "\x48\x8B\x45" . chr(256 + $offset);
    }

    public function emitCmpRaxRdx(): void {
        $this->textSection .= "\x48\x39\xD0";
    }
    
    public function emitSetlRax(): void {
        $this->textSection .= "\x0F\x9C\xC0\x48\x0F\xB6\xC0";
    }
    
    public function emitCmpRaxImm0(): void {
        $this->textSection .= "\x48\x83\xF8\x00";
    }

    public function getCurrentOffset(): int {
        return strlen($this->textSection);
    }

    public function emitJe_ForwardPlaceholder(): int {
        $this->textSection .= "\x0F\x84";
        $offset = strlen($this->textSection);
        $this->textSection .= "\x00\x00\x00\x00";
        return $offset;
    }

    public function emitJmp_Backward(int $target): void {
        $current = strlen($this->textSection);
        $rel = $target - ($current + 5);
        $this->textSection .= "\xE9" . pack('V', $rel);
    }

    public function patchForwardJump(int $offset): void {
        $current = strlen($this->textSection);
        $rel = $current - ($offset + 4);
        $this->textSection = substr_replace($this->textSection, pack('V', $rel), $offset, 4);
    }

    // ВАЖЛИВО: Алгоритм itoa (з числа в рядок)
    public function emitPrintNumberInRax(): void {
        $this->textSection .= 
            "\x49\x89\xE2" .                 // mov r10, rsp (зберігаємо стек)
            "\x48\x83\xEC\x20" .             // sub rsp, 32 (буфер на стеку)
            "\x49\x89\xE3" .                 // mov r11, rsp
            "\x49\x83\xC3\x1F" .             // add r11, 31 (кінець буфера)
            "\x41\xC6\x03\x0A" .             // mov byte [r11], 10 (символ нового рядка '\n')
            "\x4D\x89\xD8" .                 // mov r8, r11
            "\x48\xC7\xC1\x0A\x00\x00\x00" . // mov rcx, 10 (дільник)
            
            // loop_start:
            "\x49\xFF\xC8" .                 // dec r8
            "\x48\x31\xD2" .                 // xor rdx, rdx
            "\x48\xF7\xF1" .                 // div rcx
            "\x80\xC2\x30" .                 // add dl, 48 (конвертація в ASCII)
            "\x41\x88\x10" .                 // mov [r8], dl
            "\x48\x85\xC0" .                 // test rax, rax
            "\x75\xEC" .                     // jnz loop_start (-20 байт)
            
            // print_sys_write:
            "\x4C\x89\xDA" .                 // mov rdx, r11
            "\x4C\x29\xC2" .                 // sub rdx, r8
            "\x48\x83\xC2\x01" .             // add rdx, 1 (довжина рядка)
            "\x48\xC7\xC0\x01\x00\x00\x00" . // mov rax, 1 (sys_write)
            "\x48\xC7\xC7\x01\x00\x00\x00" . // mov rdi, 1 (stdout)
            "\x4C\x89\xC6" .                 // mov rsi, r8 (вказівник на початок)
            "\x0F\x05" .                     // syscall
            "\x4C\x89\xD4";                  // mov rsp, r10 (відновлюємо стек)
    }

    public function emitPrintString(string $str): void {
        $dataLabelOffset = strlen($this->dataSection);
        $this->dataSection .= $str . "\n";
        $strLen = strlen($str) + 1;

        $this->textSection .= "\x48\xC7\xC0\x01\x00\x00\x00"; 
        $this->textSection .= "\x48\xC7\xC7\x01\x00\x00\x00"; 
        
        $this->textSection .= "\x48\xC7\xC6"; 
        $this->relocations[] = ['codeOffset' => strlen($this->textSection), 'dataOffset' => $dataLabelOffset];
        $this->textSection .= pack('V', 0); 
        
        $this->textSection .= "\x48\xC7\xC2" . pack('V', $strLen); 
        $this->textSection .= "\x0F\x05"; 
    }

    public function generateBinary(string $filename): void {
        // Очищаємо статус завершення (mov rdi, 0)
        $this->textSection .= "\x48\xC7\xC7\x00\x00\x00\x00";
        $this->textSection .= "\x48\xC7\xC0\x3C\x00\x00\x00\x0F\x05"; // sys_exit
        
        $entry_point = 0x400078;
        
        // ВАЖЛИВО: Резервуємо 256 байтів на стеку для локальних змінних!
        // push rbp; mov rbp, rsp; sub rsp, 256
        $prologue = "\x55\x48\x89\xE5\x48\x81\xEC\x00\x01\x00\x00"; 
        $prologueLen = strlen($prologue); // Довжина прологу тепер більша
        
        // Перераховуємо адреси з урахуванням нового прологу
        $dataVirtualAddress = 0x400000 + 120 + $prologueLen + strlen($this->textSection);
        
        foreach ($this->relocations as $reloc) {
            $absoluteAddress = $dataVirtualAddress + $reloc['dataOffset'];
            $this->textSection = substr_replace($this->textSection, pack('V', $absoluteAddress), $reloc['codeOffset'], 4);
        }

        $codeSize = strlen($this->textSection);
        $fileSize = 120 + $prologueLen + $codeSize + strlen($this->dataSection);

        $elfHeader = pack("C16vvVPPPVvvvvvv", 0x7F, 0x45, 0x4C, 0x46, 2, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 2, 0x3E, 1, $entry_point, 64, 0, 0, 64, 56, 1, 0, 0, 0);
        $programHeader = pack("VVPPPPPP", 1, 5, 0, 0x400000, 0x400000, $fileSize, $fileSize, 0x1000);

        file_put_contents($filename, $elfHeader . $programHeader . $prologue . $this->textSection . $this->dataSection);
        chmod($filename, 0755);
    }
}

// ==========================================
// 4. КОМПІЛЯТОР 
// ==========================================
class Compiler {
    public static function compile(array $ast, string $filename): void {
        $emitter = new X86Emitter();
        foreach ($ast as $stmt) { self::compileStmt($stmt, $emitter); }
        $emitter->generateBinary($filename);
    }

    private static function compileStmt(AstNode $node, X86Emitter $emitter): void {
        if ($node instanceof LetStmt || $node instanceof AssignStmt) {
            self::compileExpr($node->expr, $emitter);
            $emitter->storeLocal($node->name);
        } elseif ($node instanceof PrintStmt) {
            if ($node->expr instanceof StringNode) {
                $emitter->emitPrintString($node->expr->val);
            } else {
                // ВАЖЛИВО: Виводимо числа, які лежать у змінних або виразах!
                self::compileExpr($node->expr, $emitter);
                $emitter->emitPrintNumberInRax();
            }
        } elseif ($node instanceof WhileStmt) {
            $loopStart = $emitter->getCurrentOffset();
            self::compileExpr($node->condition, $emitter);
            $emitter->emitCmpRaxImm0();
            $patchOffset = $emitter->emitJe_ForwardPlaceholder();

            foreach ($node->body as $stmt) {
                self::compileStmt($stmt, $emitter);
            }

            $emitter->emitJmp_Backward($loopStart);
            $emitter->patchForwardJump($patchOffset);
        }
    }

    private static function compileExpr(AstNode $node, X86Emitter $emitter): void {
        if ($node instanceof NumberNode) {
            $emitter->movRaxImm($node->val);
        } elseif ($node instanceof VarNode) {
            $emitter->loadLocal($node->name);
        } elseif ($node instanceof BinOpNode) {
            self::compileExpr($node->right, $emitter);
            $emitter->pushRax();
            self::compileExpr($node->left, $emitter);
            $emitter->popRdx();
            if ($node->op === '+') {
                $emitter->addRaxRdx();
            } elseif ($node->op === '<') {
                $emitter->emitCmpRaxRdx();
                $emitter->emitSetlRax();
            }
        }
    }
}

// ==========================================
// 5. CLI ІНТЕРФЕЙС КОМПІЛЯТОРА
// ==========================================
if (php_sapi_name() !== 'cli') die("Запускати лише з термінала.\n");

$args = array_slice($argv, 1);
if (empty($args) || in_array('--help', $args) || in_array('-h', $args)) {
    echo "Trypillia AOT Compiler v0.3\n";
    echo "Використання: php compiler.php <файл.try> [-o <app>]\n";
    exit(0);
}

$inputFile = $args[0];
if (!file_exists($inputFile)) die("❌ Файл '$inputFile' не знайдено.\n");

$outputFile = 'app';
$oIndex = array_search('-o', $args);
if ($oIndex !== false && isset($args[$oIndex + 1])) $outputFile = $args[$oIndex + 1];
else $outputFile = pathinfo($inputFile, PATHINFO_FILENAME) ?: 'app';

$source = file_get_contents($inputFile);
echo "⚙️  Компіляція: $inputFile -> ./$outputFile\n";
$startTime = microtime(true);

try {
    $tokens = Lexer::run($source);
    $parser = new Parser($tokens);
    $ast = $parser->parse();
    Compiler::compile($ast, $outputFile);
    
    $timeTaken = round((microtime(true) - $startTime) * 1000, 2);
    echo "✅ Зібрано за {$timeTaken} мс.\n🚀 Запуск: ./$outputFile\n";
} catch (Exception $e) {
    echo "\n❌ ПОМИЛКА: " . $e->getMessage() . "\n";
    exit(1);
}
