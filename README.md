# Trypillia Compiler

[![CI](https://github.com/ChernegaSergiy/php-elf-compiler/actions/workflows/ci.yml/badge.svg)](https://github.com/ChernegaSergiy/php-elf-compiler/actions/workflows/ci.yml)

An ahead-of-time (AOT) compiler for **Trypillia**, a minimal imperative language. It compiles `.try` source files directly into native, statically linked **x86_64 ELF** executables for Linux — no external assembler, linker, or runtime is involved. The compiler itself is a pure PHP application.

## Requirements

- PHP >= 8.1
- Linux on x86_64 (current backend emits code for this platform)

## Installation

```bash
composer install
```

## Usage

```bash
./bin/trypillia examples/fibonacci.try -o fibonacci
./fibonacci
```

```
trypillia <file.try> [-o <output>]
```

If `-o` is omitted, the output binary is named after the input file.

## Language

Trypillia supports:

- Variable declaration and reassignment: `let x = 1; x = 2;`
- Integer and string literals
- Binary operators: `+`, `-`, `*`, `<`, `>`
- `print` for numbers and strings
- `while condition { ... }` loops

Example (`examples/fibonacci.try`):

```
let max = 100;
let a = 0;
let b = 1;
let temp = 0;

print "Обчислення Фібоначчі:";

while b < max {
    print b;
    temp = a + b;
    a = b;
    b = temp;
}

print "Завершено!";
```

## Architecture

The compiler uses a portable IR pipeline:

| Stage | Namespace | Responsibility |
|---|---|---|
| Lexer | `ChernegaSergiy\TrypilliaCompiler\Lexer` | Source text → token stream |
| Parser | `ChernegaSergiy\TrypilliaCompiler\Parser` | Token stream → AST |
| AST | `ChernegaSergiy\TrypilliaCompiler\Ast` | AST node definitions |
| IR generator | `ChernegaSergiy\TrypilliaCompiler\IR\IRGenerator` | AST → three-address style IR |
| Backend | `ChernegaSergiy\TrypilliaCompiler\Backend\*` | IR → machine code for a target architecture |
| x86_64 emitter | `ChernegaSergiy\TrypilliaCompiler\CodeGen\X86Emitter` | Hand-written x86_64 machine code and ELF assembly |
| Compiler | `ChernegaSergiy\TrypilliaCompiler\Compiler` | Orchestrates AST → IR → backend pipeline |
| CLI | `ChernegaSergiy\TrypilliaCompiler\Cli\CompilerApplication` | Command-line front end |

Current backend support:

- ✅ x86_64
- 🚧 x86
- 🚧 ARM32
- 🚧 ARM64

`X86Emitter` builds the `.text` and `.data` sections byte by byte and hand assembles a minimal ELF64 executable header and a single `PT_LOAD` program header — there is no relocation table, symbol table, or dynamic linking, so the resulting binaries currently run only on Linux/x86_64.

## Testing

```bash
composer test
```

The suite covers the lexer, parser, and code generator in isolation, plus integration tests that compile small Trypillia programs into real binaries and assert on their actual runtime output.

## Contributing

Contributions are welcome and appreciated! Here's how you can contribute:

1. Fork the project
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

Please make sure to update tests as appropriate and adhere to the existing coding style.

## License

This project is licensed under the CSSM Unlimited License v2.0 (CSSM-ULv2). See the [LICENSE](LICENSE) file for details.
