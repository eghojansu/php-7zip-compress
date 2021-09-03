<?php

if ('cli' !== PHP_SAPI) {
    exit();
}

$cwd = getcwd();
$configs = array('compress.json', 'compress.json.dist');
$defaults = array(
    'name' => null,
    'dir' => $argv[1] ?? $cwd,
    'dest' => $cwd . '/var',
    'bin' => null,
    'options' => '-t7z -mx=9 -m0=lzma2',
    'excludes' => array('vendor', 'var', 'node_modules'),
    'ignores' => array('/.*\.(?:7z|bak|db|env|gz|zip|rar)$/i', '/\.git\/.*/i'),
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

// fixing
$workingDir = rtrim(fixSlashes($options['dir']), '/');

if (!realpath($workingDir)) {
    halt('Unknown working dir: %s', $workingDir);
}

$directoriesFix = array('dest');

foreach ($directoriesFix as $key) {
    $options[$key] = str_replace('{cwd}', $workingDir, rtrim(fixSlashes($options[$key]), '/'));
}

$excludes = $options['excludes'] ? '~^' . preg_quote($workingDir, '~') . '/(?:' . array_reduce(split($options['excludes']), function ($excludes, $exclude) {
    return $excludes . ($excludes ? '|' : '') . preg_quote($exclude, '~');
}, '') . ')/~i' : '';

runCall('Listing git files', $output, 'git ls-files', $workingDir);

if ($output) {
    $files = array_map(function ($file) use ($workingDir) {
        return $workingDir . '/' . $file;
    }, explode("\n", trim($output)));
} else {
    $files = null;

    runAction('Using directory iterator', function() use ($workingDir, $excludes, &$files) {
        $flags = FilesystemIterator::CURRENT_AS_PATHNAME|FilesystemIterator::SKIP_DOTS|FilesystemIterator::UNIX_PATHS;
        $dir = new RecursiveDirectoryIterator($workingDir, $flags);
        $it = new RecursiveIteratorIterator($dir);

        $files = iterator_to_array($it, false);

        return 'done';
    });
}

if ($excludes) {
    $files = preg_grep($excludes, $files, PREG_GREP_INVERT);
}

if ($options['ignores']) {
    $files = array_reduce($options['ignores'], function ($carry, $pattern) use ($files) {
        return array_merge($carry, preg_grep($pattern, $files, PREG_GREP_INVERT));
    }, array());
}

if (!$files) {
    halt('No files');
}

$bin = resolveBinary($options['bin']);

if (!$bin) {
    halt('7Zip binary not found');
}

$name = $options['name'] ?? basename($workingDir);
$dest = $options['dest'];

if (!is_dir($dest)) {
    mkdir($dest, 0777, true);
}

$filesCount = count($files);
$target = resolveFilename($dest, $name);
$errors = null;

runAction('Processing '. $filesCount . ' files', function () use ($workingDir, $target, $bin, $files, $options, &$errors) {
    $success = 0;
    $error = 0;
    $cwd = dirname($workingDir);
    $cut = strlen($cwd) + 1;

    foreach ($files as $file) {
        $relative = substr($file, $cut);
        $cmd = sprintf('"%s" a %s "%s" "%s"', $bin, $options['options'], $target, $relative);
        $result = call($cmd, $cwd);

        if ($result['error']) {
            $error++;

            $errors[] = $result['error'];
        } else {
            $success++;
        }
    }

    return sprintf('done (%s files added, %s errors)', $success, $error);
});

if ($errors) {
    $errorsCount = count($errors);

    array_map('printf', $errors);

    if ($errorsCount === $filesCount) {
        writeln('Files/errors: %s/%s', count($files), count($errors));
    }
}

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

    printf("%s (ellapsed: %s)%s", $error ? 'failed' : 'done', $ellapsed, PHP_EOL);
}
function runAction(string $action, Closure $cb): void {
    $start = hrtime(true);

    printf('%s...', ucfirst($action));

    $result = $cb();
    $ellapsed = sprintf("%d ms", (hrtime(true) - $start) / 1e+6);

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
function resolveFilename(string $dest, string $name): string {
    $ctr = 1;
    $suffix = '';
    $ext = '.7z';

    while (is_file($path = $dest . '/' . $name . $suffix . $ext)) {
        $suffix = '-' . (++$ctr);
    }

    return $path;
}
function resolveFilesize($bytes, int $decimals = 2): string {
    $sizes = 'BKMGTP';
    $factor = floor((strlen($bytes) - 1) / 3);

    return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . ($sizes[$factor] ?? 'X');
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
function split($parts, $pattern = '/,/'): array {
    return is_array($parts) ? $parts : preg_split($pattern, $parts, 0, PREG_SPLIT_NO_EMPTY);
}
function call($command, string $cwd = null): array {
    $start = hrtime(true);
    $result = array(
        'input' => '',
        'output' => '',
        'error' => 'Unexpected error',
    );
    $blueprint = array('input', 'output', 'error');
    $spec = array(
        array('pipe', 'r'),
        array('pipe', 'w'),
        array('pipe', 'w'),
    );
    $process = proc_open($command, $spec, $pipes, $cwd);

    if (is_resource($process)) {
        foreach ($blueprint as $pos => $part) {
            $result[$part] = stream_get_contents($pipes[$pos]);
            fclose($pipes[$pos]);
        }

        proc_close($process);
    }

    $result['ellapsed'] = sprintf("%d ms", (hrtime(true) - $start) / 1e+6);

    return $result;
}
