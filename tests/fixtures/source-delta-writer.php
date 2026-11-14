<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use WordPressR2Backup\SyntheticFileSource;

try {
    $source = unserialize(base64_decode(trim(fgets(STDIN)), true), ['allowed_classes' => [SyntheticFileSource::class]]);
    if (!$source instanceof SyntheticFileSource) { throw new RuntimeException('Invalid owned source.'); }
    $staging = $argv[1];
    $source->withWriter(function () use ($source, $staging): void {
        fwrite(STDOUT, "holding\n"); fflush(STDOUT);
        $deadline = microtime(true) + 10;
        do {
            $paths = glob($staging . '/capture-*/content/tree/old.txt');
            if (count($paths) === 1 && hash_file('sha256', $paths[0]) === hash('sha256', 'old file')) { break; }
            if (microtime(true) > $deadline) { throw new RuntimeException('Pre-copy did not finish.'); }
            usleep(10000);
        } while (true);
        $mtime = filemtime($source->root() . '/same.txt');
        $source->write('same.txt', 'next'); touch($source->root() . '/same.txt', $mtime);
        $source->remove('tree/old.txt'); $source->remove('tree');
        $source->remove('empty'); $source->write('empty', 'formerly a directory');
        $source->mkdir('added-empty'); $source->write('new.txt', 'added');
    });
    fwrite(STDOUT, "changed\n"); fflush(STDOUT); exit(0);
} catch (Throwable $ignoredError) { fwrite(STDERR, "Synthetic delta writer failed.\n"); exit(1); }
