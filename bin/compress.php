<?php

if ('cli' !== PHP_SAPI) {
    exit();
}

$cwd = getcwd();
$configs = array('compress.json', 'compress.json.dist');
$defaults = array(
    'name' => null,
    'dir' => $argv[1] ?? null,
    'dest' => $cwd . '/dist',
    'bin' => null,
    'format' => '7z',
    'extension' => null,
    'options' => '-mx=9 -m0=lzma2',
    'excludes' => array(
        '.git',
        '.vs',
        '~$*',
        'dist',
        'node_modules',
        'var',
        'vendor',
    ),
    'exclude_extensions' => '7z,bak,db,env,gz,zip,rar',
);
$options = $defaults;

foreach ($configs as $config) {
    if (is_file($file = $cwd . '/' . $config)) {
        $customOptions = json_decode(file_get_contents($file), true);

        if (json_last_error()) {
            halt('Configuration file error: %s (%s)', $file, json_last_error_msg());
        }

        $options = array_merge($options, $customOptions ?? array());

        break;
    }
}

if (!$options['dir'] || '/' === $options['dir']) {
    halt('Please provide any directory to compress');
}

// fixing
$workingDir = fixSlashes(realpath($options['dir']) ?: '');

if (!$workingDir) {
    halt('Invalid working dir: %s', $workingDir);
}

$directoriesFix = array('dest');

foreach ($directoriesFix as $key) {
    $options[$key] = str_replace('{cwd}', $workingDir, rtrim(fixSlashes($options[$key]), '/'));
}

$dest = $options['dest'];
$name = $options['name'] ?: basename($workingDir);
$extension = '.' . ltrim($options['extension'] ?: $options['format'], '.');
$excludes = array();

foreach ($options['excludes'] as $exclude) {
    $excludes[] = sprintf('"-x!%s/%s"', $name, $exclude);
}

foreach (split($options['exclude_extensions']) as $ext) {
    $excludes[] = sprintf('"-xr!%s/*.%s"', $name, $ext);
}

runCall('Get excluded files from repository', $result, 'git ls-files -oi --exclude-standard', $workingDir);

foreach (explode("\n", trim($result)) as $exclude) {
    $base = strstr($exclude, '/', true);

    if (!$base || in_array($base, $options['excludes'])) {
        continue;
    }

    $excludes[] = sprintf('"-xr!%s/%s"', $name, $exclude);
}

$bin = resolveBinary($options['bin']);

if (!$bin) {
    halt('7Zip binary not found');
}

if (is_dir($dest)) {
    $dest = fixSlashes(realpath($dest));
} else {
    mkdir($dest, 0777, true);
}

$target = resolveFilename($dest, $name, $extension);
$cmd = sprintf('"%s" a -t%s %s "%s" "%s"', $bin, $options['format'], $options['options'], $target, $workingDir) . ' ' . implode(' ', $excludes);

runCall('Compressing', $result, $cmd);
writeln('Output file: %s (%s)', $target, resolveFilesize(filesize($target)));
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
    return is_array($parts) ? $parts : preg_split($pattern, $parts, 0, PREG_SPLIT_NO_EMPTY);
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
