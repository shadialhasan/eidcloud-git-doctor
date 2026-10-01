<?php

declare(strict_types=1);

namespace EidCloud\GitDoctor\Auditors;

use EidCloud\GitDoctor\Git\GitCommandRunner;

class LargeFileAuditor
{
    private GitCommandRunner $git;

    public function __construct(GitCommandRunner $git)
    {
        $this->git = $git;
    }

    /**
     * Audit repository for large blobs exceeding threshold bytes.
     *
     * @param int $thresholdBytes Default 50MB (52428800)
     * @return array{
     *   threshold_bytes: int,
     *   threshold_formatted: string,
     *   oversized_blobs: array<int, array{hash: string, size_bytes: int, size_formatted: string, path: string}>,
     *   duplicates: array<int, array{hash: string, size_bytes: int, size_formatted: string, paths: array<int, string>}>,
     *   total_blob_count: int,
     *   total_blob_size_bytes: int
     * }
     */
    public function audit(int $thresholdBytes = 52428800): array
    {
        // 1. Get all objects in pack / loose objects
        // git rev-list --objects --all
        $output = $this->git->runSafe(['rev-list', '--objects', '--all']);
        $lines = explode("\n", trim($output));

        $hashToPath = [];
        $hashes = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 2);
            $hash = $parts[0];
            $path = $parts[1] ?? '';

            if ($path !== '') {
                if (!isset($hashToPath[$hash])) {
                    $hashToPath[$hash] = [];
                }
                $hashToPath[$hash][] = $path;
            }
            $hashes[] = $hash;
        }

        $hashes = array_unique($hashes);

        // 2. Query batch check for object sizes: git cat-file --batch-check="%(objectname) %(objecttype) %(objectsize)"
        $oversizedBlobs = [];
        $totalSize = 0;
        $totalBlobs = 0;

        // Process in chunks to avoid blowing argument limits or huge buffers
        $chunkSize = 1000;
        $hashChunks = array_chunk($hashes, $chunkSize);

        foreach ($hashChunks as $chunk) {
            $input = implode("\n", $chunk) . "\n";
            $batchResult = $this->runCatFileBatchCheck($input);
            $batchLines = explode("\n", trim($batchResult));

            foreach ($batchLines as $bLine) {
                $bLine = trim($bLine);
                if ($bLine === '') {
                    continue;
                }
                $parts = explode(' ', $bLine);
                if (count($parts) >= 3) {
                    $hash = $parts[0];
                    $type = $parts[1];
                    $size = (int)$parts[2];

                    if ($type === 'blob') {
                        $totalBlobs++;
                        $totalSize += $size;

                        if ($size >= $thresholdBytes) {
                            $paths = $hashToPath[$hash] ?? ['<unknown-path>'];
                            $oversizedBlobs[] = [
                                'hash' => $hash,
                                'size_bytes' => $size,
                                'size_formatted' => $this->formatBytes($size),
                                'path' => implode(', ', array_unique($paths)),
                            ];
                        }
                    }
                }
            }
        }

        // Sort oversized blobs by size descending
        usort($oversizedBlobs, function ($a, $b) {
            return $b['size_bytes'] <=> $a['size_bytes'];
        });

        // Detect duplicate files in working tree or across tree
        $duplicates = $this->auditDuplicateBlobs($hashToPath);

        return [
            'threshold_bytes' => $thresholdBytes,
            'threshold_formatted' => $this->formatBytes($thresholdBytes),
            'oversized_blobs' => $oversizedBlobs,
            'duplicates' => $duplicates,
            'total_blob_count' => $totalBlobs,
            'total_blob_size_bytes' => $totalSize,
        ];
    }

    /**
     * Detect duplicate blobs having multiple distinct paths
     *
     * @param array<string, array<int, string>> $hashToPath
     * @return array<int, array{hash: string, size_bytes: int, size_formatted: string, paths: array<int, string>}>
     */
    private function auditDuplicateBlobs(array $hashToPath): array
    {
        $duplicates = [];
        foreach ($hashToPath as $hash => $paths) {
            $uniquePaths = array_values(array_unique($paths));
            if (count($uniquePaths) > 1) {
                // Get size
                $size = (int)trim($this->git->runSafe(['cat-file', '-s', $hash]));
                $duplicates[] = [
                    'hash' => $hash,
                    'size_bytes' => $size,
                    'size_formatted' => $this->formatBytes($size),
                    'paths' => $uniquePaths,
                ];
            }
        }

        usort($duplicates, function ($a, $b) {
            return $b['size_bytes'] <=> $a['size_bytes'];
        });

        return array_slice($duplicates, 0, 50); // top 50 duplicates
    }

    private function runCatFileBatchCheck(string $input): string
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $cmd = ['git', 'cat-file', '--batch-check=%(objectname) %(objecttype) %(objectsize)'];
        $process = proc_open($cmd, $descriptorSpec, $pipes, $this->git->getRepoPath());

        if (!is_resource($process)) {
            return '';
        }

        fwrite($pipes[0], $input);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return (string)$stdout;
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }

    /**
     * Parse human-readable threshold like '10MB', '500KB', '1GB' into bytes
     */
    public static function parseThreshold(string $input): int
    {
        $input = trim(strtoupper($input));
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(GB|G|MB|M|KB|K|B)?$/', $input, $matches)) {
            $num = (float)$matches[1];
            $unit = $matches[2] ?? 'B';

            return match ($unit) {
                'GB', 'G' => (int)($num * 1024 * 1024 * 1024),
                'MB', 'M' => (int)($num * 1024 * 1024),
                'KB', 'K' => (int)($num * 1024),
                default => (int)$num,
            };
        }

        return 52428800; // 50MB fallback
    }
}
