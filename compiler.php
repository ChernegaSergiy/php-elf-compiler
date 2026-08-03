<?php

// ==========================================
// 1. ТОКЕНИ ТА ПОСИМВОЛЬНИЙ ЛЕКСЕР
// ==========================================
enum TokenType {
    case LET; case PRINT; case IDENTIFIER; case NUMBER; case STRING;
    case ASSIGN; case PLUS; case MINUS; case MULT; case SEMICOLON; case EOF;
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
            elseif ($c === ';') $this->tokens[] = new Token(TokenType::SEMICOLON, ';');
            elseif ($c === '"') {
                $start = $this->pos;
                while ($this->pos < $this->length && $this->source[$this->pos] !== '"') $this->pos++;
                $val = substr($this->source, $start, $this->pos - $start);
                $this->pos++; // пропуск лапки
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
class PrintStmt implements AstNode { public function __construct(public AstNode $expr) {} }

class Parser {
    private int $pos = 0;
    public function __construct(private array $tokens) {}

    private function peek(): Token { return $this->tokens[$this->pos]; }
    private function consume(): Token { return $this->tokens[$this->pos++]; }

    public function parse(): array {
        $stmts = [];
        while ($this->peek()->type !== TokenType::EOF) {
            $tok = $this->consume();
            if ($tok->type === TokenType::LET) {
                $name = $this->consume()->value;
                $this->consume(); // '='
                $expr = $this->parseExpr();
                $this->consume(); // ';'
                $stmts[] = new LetStmt($name, $expr);
            } elseif ($tok->type === TokenType::PRINT) {
                $expr = $this->parseExpr();
                $this->consume(); // ';'
                $stmts[] = new PrintStmt($expr);
            } else {
                throw new Exception("Неочікуваний токен: " . $tok->value);
            }
        }
        return $stmts;
    }

    private function parseExpr(): AstNode {
        $tok = $this->consume();
        $node = match($tok->type) {
            TokenType::NUMBER => new NumberNode((int)$tok->value),
            TokenType::STRING => new StringNode($tok->value),
            TokenType::IDENTIFIER => new VarNode($tok->value),
            default => throw new Exception("Помилка виразу: " . $tok->value)
        };

        if ($this->peek()->type === TokenType::PLUS || $this->peek()->type === TokenType::MINUS || $this->peek()->type === TokenType::MULT) {
            $opTok = $this->consume();
            $right = $this->parseExpr();
            $node = new BinOpNode($node, $opTok->value, $right);
        }
        return $node;
    }
}

// ==========================================
// 3. X86_64 EMMITER (З Relocations)
// ==========================================
class X86Emitter {
    private string $textSection = "";
    private string $dataSection = "";
    private array $symbols = []; 
    private int $stackOffset = 0;
    
    // Масив для збереження місць, куди треба вписати адреси пам'яті
    private array $relocations = []; 

    public function movRaxImm(int $val): void {
        $this->textSection .= "\x48\xC7\xC0" . pack('V', $val);
    }

    public function pushRax(): void {
        $this->textSection .= "\x50";
    }

    public function popRdx(): void {
        $this->textSection .= "\x5A";
    }

    public function addRaxRdx(): void {
        $this->textSection .= "\x48\x01\xD0";
    }

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

    public function emitPrintString(string $str): void {
        $dataLabelOffset = strlen($this->dataSection);
        $this->dataSection .= $str . "\n";
        $strLen = strlen($str) + 1;

        // rax = 1 (sys_write)
        $this->textSection .= "\x48\xC7\xC0\x01\x00\x00\x00";
        // rdi = 1 (stdout file descriptor)
        $this->textSection .= "\x48\xC7\xC7\x01\x00\x00\x00";
        
        // rsi = вказівник на рядок (ЗАПИСУЄМО ПУСТУШКУ)
        $this->textSection .= "\x48\xC7\xC6"; // mov rsi, imm32
        
        // Запам'ятовуємо позицію, куди пізніше впишемо адресу
        $this->relocations[] = [
            'codeOffset' => strlen($this->textSection),
            'dataOffset' => $dataLabelOffset
        ];
        $this->textSection .= pack('V', 0); // Вставляємо 4 нульових байти тимчасово
        
        // rdx = довжина рядка (4 байти)
        $this->textSection .= "\x48\xC7\xC2" . pack('V', $strLen);
        
        // Виклик ядра Linux
        $this->textSection .= "\x0F\x05";
    }

    public function emitSysExit(): void {
        $this->textSection .= "\x48\xC7\xC0\x3C\x00\x00\x00\x0F\x05";
    }

    public function generateBinary(string $filename): void {
        $this->emitSysExit();

        $entry_point = 0x400078;
        $prologue = "\x55\x48\x89\xE5"; // push rbp; mov rbp, rsp
        
        // --- ФАЗА РЕЛОКАЦІЇ ---
        // Розраховуємо абсолютну віртуальну адресу секції даних.
        // База (0x400000) + Заголовки (120) + Пролог (4) + Весь машинний код
        $dataVirtualAddress = 0x400000 + 120 + 4 + strlen($this->textSection);
        
        // Проходимо по всіх пустушках і перезаписуємо їх реальними адресами
        foreach ($this->relocations as $reloc) {
            $absoluteAddress = $dataVirtualAddress + $reloc['dataOffset'];
            // Переписуємо 4 байти за певним зміщенням
            $this->textSection = substr_replace(
                $this->textSection, 
                pack('V', $absoluteAddress), 
                $reloc['codeOffset'], 
                4
            );
        }
        // -----------------------

        $codeSize = strlen($this->textSection);
        $fileSize = 120 + 4 + $codeSize + strlen($this->dataSection);

        $elfHeader = pack("C16vvVPPPVvvvvvv",
            0x7F, 0x45, 0x4C, 0x46, 2, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            2, 0x3E, 1, $entry_point, 64, 0, 0, 64, 56, 1, 0, 0, 0
        );

        $programHeader = pack("VVPPPPPP",
            1, 5, 0, 0x400000, 0x400000, $fileSize, $fileSize, 0x1000
        );

        $binary = $elfHeader . $programHeader . $prologue . $this->textSection . $this->dataSection;

        file_put_contents($filename, $binary);
        chmod($filename, 0755);
    }
}

// ==========================================
// 4. КОМПІЛЯЦІЯ AST У БІНАРНИК
// ==========================================
class Compiler {
    public static function compile(array $ast, string $filename): void {
        $emitter = new X86Emitter();

        foreach ($ast as $node) {
            if ($node instanceof LetStmt) {
                self::compileExpr($node->expr, $emitter);
                $emitter->storeLocal($node->name);
            } elseif ($node instanceof PrintStmt) {
                if ($node->expr instanceof StringNode) {
                    $emitter->emitPrintString($node->expr->val);
                } else {
                    self::compileExpr($node->expr, $emitter);
                    // Для повноцінної мови тут потрібен код для перетворення числа на рядок
                }
            }
        }

        $emitter->generateBinary($filename);
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
            }
        }
    }
}

// ==========================================
// 5. ВИКОРИСТАННЯ
// ==========================================
$source = '
    let a = 15;
    let b = 27;
    print "Привіт з нативного бінарника Trypillia!";
';

echo "Компілюємо повноцінну програму...\n";
$tokens = Lexer::run($source);
$parser = new Parser($tokens);
$ast = $parser->parse();

Compiler::compile($ast, "trypillia_app");
echo "Готово! Створено нативний ELF бінарник: ./trypillia_app\n";
