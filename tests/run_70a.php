<?php

declare(strict_types=1);

/**
 * Executor isolado da Fase 7.0A (retrieval): php tests/run_70a.php
 * Os mesmos testes também rodam dentro de tests/run.php.
 */

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/support/TestIsolation.php';

TestIsolation::begin();
register_shutdown_function([TestIsolation::class, 'finish']);

$tests = [];

function test(string $name, callable $callback): void
{
    global $tests;

    try {
        $callback();
        $tests[] = ['name' => $name, 'pass' => true, 'detail' => ''];
    } catch (Throwable $exception) {
        $tests[] = ['name' => $name, 'pass' => false, 'detail' => $exception->getMessage()];
    }
}

function assertTrue(bool $condition, string $message = 'assertTrue falhou'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = 'assertSame falhou'): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' (esperado: ' . var_export($expected, true) . ', recebido: ' . var_export($actual, true) . ')');
    }
}

require __DIR__ . '/retrieval70a.php';

TestIsolation::finish();
$isolation = TestIsolation::verify();
test('ISOLAMENTO: pré-existentes preservados, sem resíduos', static function () use ($isolation): void {
    assertTrue($isolation['ok'], json_encode(array_diff_key($isolation, ['pre_counts' => 1, 'post_counts' => 1])));
});

$pass = 0;
$fail = 0;

foreach ($tests as $t) {
    printf("%-4s %s%s\n", $t['pass'] ? 'PASS' : 'FAIL', $t['name'], $t['pass'] ? '' : ' — ' . $t['detail']);
    $t['pass'] ? $pass++ : $fail++;
}

printf("TOTAL: %d | PASS: %d | FAIL: %d\n", $pass + $fail, $pass, $fail);
exit($fail > 0 ? 1 : 0);
