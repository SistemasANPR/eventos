<?php

declare(strict_types=1);

/**
 * Verifica la trazabilidad entre especificaciones y pruebas (ADR-0001).
 *
 * Para cada plan de docs/plans/ cuyo estado sea «En curso» o «Terminado»:
 *  - cada criterio `- **CA-NNN-n:**` debe estar citado en una prueba
 *    (tests/**\/*.php o resources/js/**\/*.test.ts[x]) o declarar `Manual:`
 *    en las líneas siguientes;
 *  - el prefijo NNN del criterio debe coincidir con el número del plan
 *    (nombre de archivo AAAA-MM-DD-NNN-...);
 *  - un identificador no puede repetirse entre planes.
 *
 * Un plan «Terminado» con criterios sin prueba hace fallar el script (exit 1).
 * Un plan «En curso» solo emite una advertencia, salvo con STRICT=1.
 *
 * Uso: php scripts/check-criteria.php [ruta-raiz]
 */

$root = $argv[1] ?? dirname(__DIR__);
$strict = getenv('STRICT') === '1';

/** @return list<string> */
function collectFiles(string $dir, string $pattern): array
{
    if (! is_dir($dir)) {
        return [];
    }

    $found = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        $path = $file->getPathname();
        if (str_contains($path, DIRECTORY_SEPARATOR.'node_modules'.DIRECTORY_SEPARATOR)
            || str_contains($path, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
            continue;
        }
        if (preg_match($pattern, $path) === 1) {
            $found[] = $path;
        }
    }

    return $found;
}

$testFiles = array_merge(
    collectFiles($root.'/tests', '/\.php$/'),
    collectFiles($root.'/resources/js', '/\.test\.tsx?$/'),
);

$testContents = '';
foreach ($testFiles as $path) {
    $testContents .= "\n".file_get_contents($path);
}

$planFiles = glob($root.'/docs/plans/*.md') ?: [];
$errors = [];
$warnings = [];
$seen = [];
$checkedPlans = 0;
$checkedCriteria = 0;

foreach ($planFiles as $planPath) {
    $name = basename($planPath);
    if ($name === '0000-plantilla-plan.md') {
        continue;
    }

    $text = (string) file_get_contents($planPath);

    if (preg_match('/^- \*\*Estado:\*\*\s*(.+)$/mu', $text, $match) !== 1) {
        $warnings[] = "{$name}: no declara «Estado»; se omite.";
        continue;
    }

    $status = mb_strtolower(trim($match[1]));
    if (str_contains($status, '|')) {
        $warnings[] = "{$name}: el estado aún es la lista de la plantilla; se omite.";
        continue;
    }
    if (! in_array($status, ['en curso', 'terminado'], true)) {
        continue; // Borrador o Aprobado: aún sin obligación de pruebas.
    }

    $planNumber = null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}-(\d{3})-/', $name, $m) === 1) {
        $planNumber = $m[1];
    } else {
        $errors[] = "{$name}: el nombre debe empezar por AAAA-MM-DD-NNN-.";
    }

    $checkedPlans++;
    $lines = preg_split('/\R/u', $text) ?: [];
    $current = null;
    $criteria = [];

    foreach ($lines as $line) {
        if (preg_match('/^- \*\*(CA-(\d{3})-(\d+)):\*\*/u', $line, $m) === 1) {
            $current = $m[1];
            $criteria[$current] = ['prefix' => $m[2], 'manual' => false];
            continue;
        }
        if ($current !== null) {
            if (preg_match('/^#{1,6}\s/u', $line) === 1) {
                $current = null;
                continue;
            }
            if (preg_match('/^\s+.*\bManual:/u', $line) === 1 || preg_match('/^\s*Manual:/u', $line) === 1) {
                $criteria[$current]['manual'] = true;
            }
        }
    }

    if ($criteria === []) {
        $warnings[] = "{$name}: está «{$status}» pero no define criterios CA-NNN-n.";
        continue;
    }

    foreach ($criteria as $id => $info) {
        $checkedCriteria++;

        if ($planNumber !== null && $info['prefix'] !== $planNumber) {
            $errors[] = "{$name}: {$id} no coincide con el número del plan ({$planNumber}).";
        }
        if (isset($seen[$id])) {
            $errors[] = "{$id} está repetido en {$seen[$id]} y {$name}.";
        }
        $seen[$id] = $name;

        if ($info['manual']) {
            continue;
        }

        $quoted = preg_quote($id, '/');
        if (preg_match('/(?<![\w-])'.$quoted.'(?!\w)/', $testContents) === 1) {
            continue;
        }

        $message = "{$name}: {$id} no tiene una prueba que lo cite (ni línea «Manual:»).";
        if ($status === 'terminado' || $strict) {
            $errors[] = $message;
        } else {
            $warnings[] = $message.' (plan En curso: advertencia)';
        }
    }
}

foreach ($warnings as $warning) {
    fwrite(STDOUT, "AVISO  {$warning}\n");
}
foreach ($errors as $error) {
    fwrite(STDERR, "ERROR  {$error}\n");
}

fwrite(STDOUT, sprintf(
    "Criterios: %d plan(es) revisados, %d criterio(s), %d error(es), %d aviso(s).\n",
    $checkedPlans,
    $checkedCriteria,
    count($errors),
    count($warnings),
));

exit($errors === [] ? 0 : 1);
