<?php

if ('cli' !== PHP_SAPI) {
    exit();
}

$cwd = getcwd();
$options = array(
    'bin' => null,
    'dest' => $cwd . '/dist',
    'dir' => $argv[1] ?? $cwd,
    'exclude_extensions' => array('7z', 'bak', 'db', 'env', 'gz', 'zip', 'rar'),
    'exclude_recursives' => array('~$*'),
    'excludes' => array('.git', '.vs', 'dist', 'node_modules', 'var', 'vendor'),
    'extension' => null,
    'format' => '7z',
    'merge_recursive' => true,
    'name' => null,
    'options' => '-mx=9 -m0=lzma2',
);

if (is_readable($file = $cwd . '/compress.json')) {
    loadConfig($file, $options);
} elseif (is_readable($file = $cwd . '/compress.json.dist')) {
    loadConfig($file, $options);
}

if (!$options['dir'] || '/' === $options['dir']) {
    halt('Please provide any directory to compress');
}

if (!realpath($options['dir'])) {
    halt('Invalid working dir: %s', $workingDir);
}

$workingDir = fixSlashes(realpath($options['dir']));
$bin = resolveBinary($options['bin']);
$dest = str_replace('{cwd}', $workingDir, rtrim(fixSlashes($options['dest']), '/'));
$name = $options['name'] ?: basename($workingDir);
$extension = '.' . ltrim($options['extension'] ?: $options['format'], '.');
$excludes = getExcludes($options['excludes'], $name . '/');
$excludesRecursive = array_merge(
    getExcludes($options['exclude_recursives'], $name . '/'),
    getExcludes($options['exclude_extensions'], $name . '/*.'),
);

if (!$bin) {
    halt('7Zip binary not found');
}

runCall('Get excluded files from repository', $result, 'git ls-files -oi --exclude-standard', $workingDir);

foreach (explode("\n", trim($result)) as $exclude) {
    $base = strstr($exclude, '/', true);

    if (!$base || preg_grep('~^' . preg_quote($name, '~') . '/' . preg_quote($base, '~') . '~', $excludes)) {
        continue;
    }

    $excludes[] = $name . '/' . $exclude;
}

if (is_dir($dest)) {
    $dest = fixSlashes(realpath($dest));
} else {
    mkdir($dest, 0777, true);
}

$target = resolveFilename($dest, $name, $extension);
$excludeFile = tempnam(sys_get_temp_dir(), 'excr');
$excludeRecursiveFile = tempnam(sys_get_temp_dir(), 'excr');

file_put_contents($excludeFile, implode("\n", $excludes));
file_put_contents($excludeRecursiveFile, implode("\n", $excludesRecursive));

$cmd = sprintf('"%s" a -t%s %s "%s" "%s" "-x@%s" "-xr@%s"', $bin, $options['format'], $options['options'], $target, $workingDir, $excludeFile, $excludeRecursiveFile);

runCall('Compressing', $result, $cmd);

if (file_exists($target)) {
    writeln('Output file: %s (%s)', $target, resolveFilesize(filesize($target)));
    writeln('Excluded file paths:');
    writeln('  - %s', $excludeFile);
    writeln('  - %s', $excludeRecursiveFile);
}

writeln();

/* ==== Definitions ==== */
function runCall(string $action, &$result = null, ...$calls): void {
    printf('%s...', ucfirst($action));

    list(
        'output' => $result,
        'error' => $error,
        'ellapsed' => $ellapsed,
    ) = call(...$calls);

    if ($error) {
        printf('failed (%s) [%s] %s', $ellapsed, trim($error), PHP_EOL);
    } else {
        printf('done (%s)%s', $ellapsed, PHP_EOL);
    }
}
function runAction(string $action, Closure $cb): void {
    $start = hrtime(true);

    printf('%s...', ucfirst($action));

    $result = $cb();
    $ellapsed = resolveEllapsed(hrtime(true) - $start);

    printf("%s (ellapsed: %s)%s", $result, $ellapsed, PHP_EOL);
}
function halt(string $format = null, ...$values): void {
    if ($format) {
        writeln($format, ...$values);
    }

    exit(1);
}
function writeln(string $format = null, ...$values): void {
    if ($format) {
        printf($format, ...$values);
    }

    echo PHP_EOL;
}
function loadConfig(string $file, array &$options): void {
    $customOptions = json_decode(file_get_contents($file), true);

    if (json_last_error()) {
        halt('Configuration file error: %s (%s)', $file, json_last_error_msg());
    }

    if ($options['merge_recursive'] ?? false) {
        $options = array_merge_recursive($options, $customOptions ?? array());
    } else {
        $options = array_merge($options, $customOptions ?? array());
    }
}

/** No side effect */
function grabFileno(string $file, string $extension): int {
    $base = basename($file, $extension);

    if (
        false === ($hypenPos = strrpos($base, '-'))
        || !is_numeric($no = substr($base, $hypenPos + 1))
    ) {
        return 1;
    }

    return intval($no);
}
function resolveFilename(string $dest, string $name, string $ext): string {
    $existing = array_map(function ($file) use ($ext) {
        return grabFileno($file, $ext);
    }, glob($dest . '/*' . $ext));

    sort($existing);

    return $dest . '/' . $name . ($existing ? '-' . (end($existing) + 1) : '') . $ext;
}
function resolveFilesize(float $bytes, int $decimals = 2): string {
    $sizes = 'BKMGTP';
    $factor = floor((strlen($bytes) - 1) / 3);

    return sprintf("%.{$decimals}f %s", $bytes / pow(1024, $factor), $sizes[$factor] ?? 'X');
}
function resolveEllapsed(float $nano, int $decimals = 2): string {
    if ($nano > 6e10) {
        return sprintf("%.{$decimals}f minutes", $nano / 6e10);
    }

    if ($nano > 1e9) {
        return sprintf("%.{$decimals}f seconds", $nano / 1e9);
    }

    return sprintf("%.{$decimals}f milliseconds", $nano / 1e6);
}
function resolveBinary(string $path = null): ?string {
    if ($path) {
        return ($bin = realpath($path)) && is_executable($bin) ? $bin : null;
    }

    $paths = 'Windows' === PHP_OS_FAMILY ? array('C:\Program Files\7-Zip\7z.exe') : array('/usr/bin/7z', '/usr/bin/7za', '/usr/local/bin/7z', '/usr/local/bin/7za');

    return array_reduce($paths, function ($found, $path) {
        return $found ?? (is_file($path) ? $path : null);
    }, null);
}
function fixSlashes(string $path): string {
    return strtr($path, '\\', '/');
}
function split($parts, $pattern = '/[,;|]/'): array {
    return $parts ? (is_array($parts) ? $parts : preg_split($pattern, $parts, 0, PREG_SPLIT_NO_EMPTY)) : array();
}
function getExcludes($entry, string $prefix): array {
    return array_map(function (string $line) use ($prefix) {
        return $prefix . $line;
    }, split($entry));
}
function call($command, string $cwd = null): array {
    $start = hrtime(true);
    $result = array(
        'output' => '',
        'error' => '',
    );
    $spec = array(
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );
    $process = proc_open($command, $spec, $pipes, $cwd);

    if (is_resource($process)) {
        $result['output'] = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $result['error'] = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        proc_close($process);
    } else {
        $result['error'] = 'Unexpected error';
    }

    $result['ellapsed'] = resolveEllapsed(hrtime(true) - $start);

    return $result;
}
