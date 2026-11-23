<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

/**
 * Intérprete propio de las fórmulas del despiece (analizador descendente recursivo). NUNCA usa eval ni ejecuta código:
 * solo entiende números (con punto o coma decimal), las variables W H D (medidas del módulo en mm), T (tablero) y
 * B (trasera), los operadores + - * / y paréntesis.
 *
 * Gramática:  expr := term (('+'|'-') term)*   term := factor (('*'|'/') factor)*
 *             factor := ('+'|'-') factor | número | variable | '(' expr ')'
 * Límites: 200 caracteres, 10 niveles de paréntesis, números hasta 100 000, resultado finito.
 */

const EXPR_VARS = ['W' => 'ancho del módulo (mm)', 'H' => 'alto (mm)', 'D' => 'fondo (mm)', 'T' => 'espesor del tablero (mm)', 'B' => 'espesor de la trasera (mm)'];
const EXPR_MAX_LEN = 200;
const EXPR_MAX_DEPTH = 10;
const EXPR_MAX_NUMBER = 100000;

/** @return list<array{0:string,1:string|float,2:int}> [tipo, valor, posición] — tipo: num, var, op, ( , ) */
function expr_tokens(string $src): array
{
    if (trim($src) === '') throw new UcError(t('La fórmula está vacía.'));
    if (strlen($src) > EXPR_MAX_LEN) throw new UcError(t('La fórmula es demasiado larga (máximo {n} caracteres).', ['n' => EXPR_MAX_LEN]));
    $out = [];
    $n = strlen($src);
    for ($i = 0; $i < $n;) {
        $c = $src[$i];
        if ($c === ' ' || $c === "\t") { $i++; continue; }
        if (ctype_digit($c)) {
            preg_match('/\G\d+(?:[.,]\d+)?/', $src, $m, 0, $i);
            $i += strlen($m[0]);
            if ($i < $n && ($src[$i] === '.' || $src[$i] === ',')) throw new UcError(t('Número mal escrito en la posición {p}: «{v}».', ['p' => $i + 1, 'v' => mb_substr(substr($src, $i - strlen($m[0])), 0, 12)]));
            $num = (float) str_replace(',', '.', $m[0]);
            if ($num > EXPR_MAX_NUMBER) throw new UcError(t('Número demasiado grande: {v} (máximo {max}).', ['v' => mb_substr($m[0], 0, 20), 'max' => EXPR_MAX_NUMBER]));
            $out[] = ['num', $num, $i];
            continue;
        }
        if (ctype_alpha($c) || $c === '_') {
            preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $src, $m, 0, $i);
            if (!isset(EXPR_VARS[$m[0]])) {
                throw new UcError(t('Variable desconocida «{v}». Valen: W (ancho), H (alto), D (fondo), T (tablero) y B (trasera), en mayúsculas.', ['v' => mb_substr($m[0], 0, 20)]));
            }
            $out[] = ['var', $m[0], $i];
            $i += strlen($m[0]);
            continue;
        }
        if (str_contains('+-*/()', $c)) { $out[] = [$c === '(' || $c === ')' ? $c : 'op', $c, $i]; $i++; continue; }
        throw new UcError(t('Carácter no permitido en la posición {p}: «{c}». Solo números, W H D T B, + - * / y paréntesis.', ['p' => $i + 1, 'c' => preg_match('//u', $c) ? $c : '?']));
    }
    return $out;
}

/** Analiza la fórmula y devuelve su árbol. Lanza UcError con un mensaje claro si no es válida. */
function expr_parse(string $src): array
{
    return (new ExprParser(expr_tokens($src)))->parse();
}

/** Analizador descendente recursivo sobre la lista de símbolos de expr_tokens(). */
final class ExprParser
{
    private int $pos = 0;

    public function __construct(private array $tok) {}

    public function parse(): array
    {
        $ast = $this->expr(0);
        if ($this->pos < count($this->tok)) {
            $t = $this->tok[$this->pos];
            throw new UcError($t[0] === ')' ? t('Sobra un paréntesis de cierre en la posición {p}.', ['p' => $t[2] + 1]) : t('Símbolo inesperado «{s}» en la posición {p}.', ['s' => $t[1], 'p' => $t[2] + 1]));
        }
        return $ast;
    }

    private function peek(): ?array
    {
        return $this->tok[$this->pos] ?? null;
    }

    private function isOp(string ...$ops): bool
    {
        $t = $this->peek();
        return $t !== null && $t[0] === 'op' && in_array($t[1], $ops, true);
    }

    private function expr(int $depth): array
    {
        $left = $this->term($depth);
        while ($this->isOp('+', '-')) { $op = $this->tok[$this->pos++][1]; $left = ['op' => $op, 'a' => $left, 'b' => $this->term($depth)]; }
        return $left;
    }

    private function term(int $depth): array
    {
        $left = $this->factor($depth);
        while ($this->isOp('*', '/')) { $op = $this->tok[$this->pos++][1]; $left = ['op' => $op, 'a' => $left, 'b' => $this->factor($depth)]; }
        return $left;
    }

    private function factor(int $depth): array
    {
        if ($depth > EXPR_MAX_DEPTH) throw new UcError(t('Demasiados paréntesis anidados (máximo {n}).', ['n' => EXPR_MAX_DEPTH]));
        $t = $this->peek();
        if ($t === null) throw new UcError(t('La fórmula termina antes de tiempo: falta un número, una variable o «)».'));
        if ($this->isOp('+', '-')) { $this->pos++; return ['neg' => $t[1] === '-', 'x' => $this->factor($depth + 1)]; }
        if ($t[0] === 'num') { $this->pos++; return ['n' => $t[1]]; }
        if ($t[0] === 'var') { $this->pos++; return ['v' => $t[1]]; }
        if ($t[0] === '(') {
            $this->pos++;
            $e = $this->expr($depth + 1);
            if (($this->peek()[0] ?? null) !== ')') throw new UcError(t('Falta cerrar un paréntesis.'));
            $this->pos++;
            return $e;
        }
        throw new UcError(t('Símbolo inesperado «{s}» en la posición {p}.', ['s' => $t[1], 'p' => $t[2] + 1]));
    }
}

/** Evalúa un árbol con los valores de las variables. */
function expr_eval(array $ast, array $vars): float
{
    if (isset($ast['n'])) return (float) $ast['n'];
    if (isset($ast['v'])) {
        if (!isset($vars[$ast['v']])) throw new UcError(t('Falta el valor de la variable {v}.', ['v' => $ast['v']]));
        return (float) $vars[$ast['v']];
    }
    if (isset($ast['x'])) { $x = expr_eval($ast['x'], $vars); return $ast['neg'] ? -$x : $x; }
    $a = expr_eval($ast['a'], $vars);
    $b = expr_eval($ast['b'], $vars);
    $r = match ($ast['op']) {
        '+' => $a + $b, '-' => $a - $b, '*' => $a * $b,
        '/' => abs($b) < 1e-9 ? throw new UcError(t('División por cero.')) : $a / $b,
    };
    if (!is_finite($r) || abs($r) > 1e9) throw new UcError(t('El resultado es demasiado grande.'));
    return $r;
}

/** Analiza y evalúa en un paso. */
function expr_value(string $src, array $vars): float
{
    return expr_eval(expr_parse($src), $vars);
}
