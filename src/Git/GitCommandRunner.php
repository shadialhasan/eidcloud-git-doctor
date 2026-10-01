<?php

declare(strict_types=1);

namespace EidCloud\GitDoctor\Git;

use RuntimeException;

class GitCommandRunner
{
    private string $repoPath;

    public function __construct(string $repoPath)
    {
        $realPath = realpath($repoPath);
        if ($realPath === false || !is_dir($realPath)) {
            throw new RuntimeException("Directory does not exist: {$repoPath}");
        }
        $this->repoPath = $realPath;
    }

    public function getRepoPath(): string
    {
        return $this->repoPath;
    }

    public function isGitRepository(): bool
    {
        return is_dir($this->repoPath . DIRECTORY_SEPARATOR . '.git') ||
               file_exists($this->repoPath . DIRECTORY_SEPARATOR . '.git');
    }

    /**
     * Run a git command safely and return stdout string
     *
     * @param array<int, string> $args
     */
    public function run(array $args): string
    {
        $cmd = array_merge(['git'], $args);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptorSpec, $pipes, $this->repoPath);

        if (!is_resource($process)) {
            $cmdStr = implode(' ', $cmd);
            throw new RuntimeException("Failed to spawn git process: {$cmdStr}");
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $cmdStr = implode(' ', $cmd);
            throw new RuntimeException("Git command failed ({$exitCode}): {$cmdStr}\nStderr: " . trim((string)$stderr));
        }

        return (string)$stdout;
    }

    /**
     * Run a git command, returning empty string on failure instead of throwing
     *
     * @param array<int, string> $args
     */
    public function runSafe(array $args): string
    {
        try {
            return $this->run($args);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
