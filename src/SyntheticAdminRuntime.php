<?php
declare(strict_types=1);
namespace WordPressR2Backup;

final class SyntheticAdminRuntime
{
    private array $profile;
    private SyntheticFileSource $source;
    private AgeProcess $age;
    private SyntheticHttpRepository $reader;
    private SyntheticHttpRepository $writer;
    private MysqlClient $mysql;
    private array $binding;

    private function __construct(array $profile, SyntheticFileSource $source,
        AgeProcess $age, SyntheticHttpRepository $reader, SyntheticHttpRepository $writer,
        MysqlClient $mysql, array $binding)
    {
        $this->profile = $profile;
        $this->source = $source;
        $this->age = $age;
        $this->reader = $reader;
        $this->writer = $writer;
        $this->mysql = $mysql;
        $this->binding = $binding;
    }

    public static function open(string $profileFile, string $webRoot): self
    {
        self::privateFile($profileFile, 65536);
        $webRoot = realpath($webRoot);
        if ($webRoot === false || $profileFile === $webRoot || str_starts_with($profileFile, $webRoot . '/')) { throw new \RuntimeException('Invalid synthetic runtime.'); }
        $bytes = file_get_contents($profileFile, false, null, 0, 65537);
        $profile = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
        $fields = ['format', 'privateRoot', 'sourceDirectory', 'stagingDirectory', 'operationsDirectory', 'indexDirectory',
            'writerConnectionFile', 'readerConnectionFile', 'ageBinary', 'mysqlBinary', 'dumpBinary', 'mysqlDefaultsFile', 'mysqlWorkspace', 'syntheticSchema'];
        if (!is_array($profile) || count($profile) !== count($fields) || array_diff(array_keys($profile), $fields) !== []) { throw new \RuntimeException('Invalid synthetic runtime fields.'); }
        foreach ($fields as $field) { if (!is_string($profile[$field]) || $profile[$field] === '') { throw new \RuntimeException('Invalid synthetic runtime value.'); } }
        if ($profile['format'] !== 'wrb-synthetic-admin-runtime-v1' || !preg_match('/\Awrb_test_[a-z0-9_]{1,55}\z/D', $profile['syntheticSchema'])) { throw new \RuntimeException('Invalid synthetic runtime format.'); }
        $root = FileSafety::privateDirectory($profile['privateRoot']);
        if (dirname($root) !== realpath(sys_get_temp_dir()) || !str_starts_with(basename($root), 'wrb-')
            || $root === $webRoot || str_starts_with($root, $webRoot . '/')) { throw new \RuntimeException('Invalid synthetic private root.'); }
        $areas = [];
        foreach (['sourceDirectory', 'stagingDirectory', 'operationsDirectory', 'indexDirectory', 'mysqlWorkspace'] as $field) {
            $area = FileSafety::privateDirectory($profile[$field]);
            if (!str_starts_with($area, $root . '/')) { throw new \RuntimeException('Synthetic area escapes its root.'); }
            foreach ($areas as $other) { if ($area === $other || str_starts_with($area, $other . '/') || str_starts_with($other, $area . '/')) { throw new \RuntimeException('Synthetic areas overlap.'); } }
            $areas[] = $area;
        }
        foreach (['writerConnectionFile', 'readerConnectionFile', 'mysqlDefaultsFile'] as $field) {
            self::privateFile($profile[$field], 8192);
            if (!str_starts_with($profile[$field], $root . '/')) { throw new \RuntimeException('Synthetic connection escapes its root.'); }
            foreach (array_slice($areas, 0, 4) as $area) { if (str_starts_with($profile[$field], $area . '/')) { throw new \RuntimeException('Synthetic connection overlaps capture data.'); } }
        }
        if ($profile['writerConnectionFile'] === $profile['readerConnectionFile']) { throw new \RuntimeException('Synthetic roles require distinct connections.'); }
        $writer = new SyntheticHttpRepository($profile['writerConnectionFile']);
        $reader = new SyntheticHttpRepository($profile['readerConnectionFile']);
        $readerData = json_decode(file_get_contents($profile['readerConnectionFile']), true, 8, JSON_THROW_ON_ERROR);
        if (!$writer->managed() || $readerData['role'] !== 'reader' || $writer->unitId() !== $reader->unitId()) { throw new \RuntimeException('Synthetic roles or units differ.'); }
        $source = SyntheticFileSource::open($profile['sourceDirectory']);
        $age = new AgeProcess($profile['ageBinary']);
        $mysql = new MysqlClient($profile['mysqlBinary'], $profile['dumpBinary'], $profile['mysqlDefaultsFile'], $profile['mysqlWorkspace']);
        $sourceStat = lstat($profile['sourceDirectory'] . '/source.json');
        $binding = ['profileSha256' => hash('sha256', $bytes), 'sourceDevice' => $sourceStat['dev'], 'sourceInode' => $sourceStat['ino'],
            'unitId' => $writer->unitId(), 'writer' => $writer->sourceBinding()];
        return new self($profile, $source, $age, $reader, $writer, $mysql, $binding);
    }

    public function source(): SyntheticFileSource { return $this->source; }
    public function age(): AgeProcess { return $this->age; }
    public function reader(): SyntheticHttpRepository { return $this->reader; }
    public function unitId(): string { return $this->writer->unitId(); }
    public function binding(): array { return $this->binding; }
    public function privateRoot(): string { return $this->profile['privateRoot']; }
    public function schema(): string { return $this->profile['syntheticSchema']; }
    public function operationsDirectory(): string { return $this->profile['operationsDirectory']; }
    public function createOperation(string $operationId): void
    {
        $path = $this->operationPath($operationId);
        if (@lstat($path) === false) { FileSafety::createPrivateDirectory($path); }
        FileSafety::privateDirectory($path);
        $names = array_values(array_diff(scandir($path), ['.', '..']));
        if ($names === []) { FileSafety::createPrivateDirectory($path . '/jobs'); }
        elseif ($names !== ['jobs']) { throw new \RuntimeException('Unexpected operation contents.'); }
        FileSafety::privateDirectory($path . '/jobs');
    }
    public function jobsDirectory(string $operationId): string
    {
        $path = FileSafety::privateDirectory($this->operationPath($operationId));
        if (array_values(array_diff(scandir($path), ['.', '..'])) !== ['jobs']) { throw new \RuntimeException('Unexpected operation contents.'); }
        return FileSafety::privateDirectory($path . '/jobs');
    }
    public function managedFor(string $operationId): ManagedCapture
    {
        return ManagedCapture::open($this->profile['writerConnectionFile'], $this->profile['indexDirectory'], $this->jobsDirectory($operationId), $this->age);
    }
    public function captureFor(string $operationId): SyntheticCapture
    {
        return new SyntheticCapture($this->mysql, $this->managedFor($operationId), $this->profile['stagingDirectory']);
    }
    private function operationPath(string $id): string
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $id)) { throw new \RuntimeException('Invalid operation identifier.'); }
        return FileSafety::privateDirectory($this->profile['operationsDirectory']) . '/' . $id;
    }
    private static function privateFile(string $path, int $limit): void
    {
        FileSafety::assertRegularFile($path); $stat = lstat($path);
        if (realpath($path) !== $path || ($stat['mode'] & 0777) !== 0600 || $stat['nlink'] !== 1 || $stat['size'] > $limit
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) { throw new \RuntimeException('Invalid private runtime file.'); }
    }
}
