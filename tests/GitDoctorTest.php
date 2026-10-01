<?php

declare(strict_types=1);

namespace EidCloud\GitDoctor\Tests;

use EidCloud\GitDoctor\Auditors\BranchAuditor;
use EidCloud\GitDoctor\Auditors\BusFactorAuditor;
use EidCloud\GitDoctor\Auditors\LargeFileAuditor;
use EidCloud\GitDoctor\Auditors\ReleaseReadinessAuditor;
use EidCloud\GitDoctor\Git\GitCommandRunner;
use EidCloud\GitDoctor\GitDoctor;
use EidCloud\GitDoctor\Remediation\RemediationGenerator;
use EidCloud\GitDoctor\Reports\HealthScorecard;

class GitDoctorTest
{
    private string $tempRepoPath;

    public function setUp(): void
    {
        $this->tempRepoPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'git_doctor_test_' . uniqid();
        mkdir($this->tempRepoPath, 0777, true);

        // Initialize a test git repo
        $this->execGit(['init', '-b', 'main']);
        $this->execGit(['config', 'user.name', 'Test Author']);
        $this->execGit(['config', 'user.email', 'author@eidcloud.com']);

        // Create standard files
        file_put_contents($this->tempRepoPath . DIRECTORY_SEPARATOR . 'README.md', "# Test Repo\nThis is a sample test repository for git doctor testing with sufficient length.\n");
        file_put_contents($this->tempRepoPath . DIRECTORY_SEPARATOR . 'LICENSE', "MIT License\nCopyright (c) 2026 EidCloud\n");
        file_put_contents($this->tempRepoPath . DIRECTORY_SEPARATOR . 'CHANGELOG.md', "# Changelog\nAll notable changes documented here.\n## [1.0.0] - 2026-10-01\n- Initial release.\n");

        $workflows = $this->tempRepoPath . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'workflows';
        mkdir($workflows, 0777, true);
        file_put_contents($workflows . DIRECTORY_SEPARATOR . 'ci.yml', "name: CI\non: push\njobs:\n  test:\n    runs-on: ubuntu-latest\n");

        $this->execGit(['add', '.']);
        $this->execGit(['commit', '-m', 'feat: initial commit for test repo']);
        $this->execGit(['tag', 'v1.0.0']);
    }

    public function tearDown(): void
    {
        $this->deleteDirectory($this->tempRepoPath);
    }

    private function execGit(array $args): string
    {
        $escaped = array_map('escapeshellarg', $args);
        $cmd = 'git ' . implode(' ', $escaped);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptorSpec, $pipes, $this->tempRepoPath);
        if (!is_resource($process)) {
            return '';
        }
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return (string)$out;
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @chmod($path, 0777);
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function testGitCommandRunner(): void
    {
        $runner = new GitCommandRunner($this->tempRepoPath);
        $this->assertTrue($runner->isGitRepository(), "Runner recognizes git repo");
        $out = trim($runner->run(['rev-parse', '--abbrev-ref', 'HEAD']));
        $this->assertEquals('main', $out, "Current branch is main");
    }

    public function testReleaseReadinessAuditor(): void
    {
        $runner = new GitCommandRunner($this->tempRepoPath);
        $auditor = new ReleaseReadinessAuditor($runner);
        $result = $auditor->audit();

        $this->assertTrue($result['ready'], "Release readiness is ready");
        $this->assertTrue($result['semver']['has_tags'], "Has tags");
        $this->assertEquals('v1.0.0', $result['semver']['latest_tag'], "Latest tag is v1.0.0");
        $this->assertTrue($result['semver']['valid_semver'], "Valid SemVer format");
        $this->assertTrue($result['changelog']['exists'], "Changelog exists");
        $this->assertEquals('MIT', $result['license']['detected_type'], "License detected as MIT");
        $this->assertTrue($result['cicd']['configured'], "CI/CD configured");
        $this->assertGreaterThanOrEqual(80, $result['score'], "Score >= 80");
    }

    public function testBusFactorAuditor(): void
    {
        // Add another commit from a second contributor
        file_put_contents($this->tempRepoPath . DIRECTORY_SEPARATOR . 'test2.txt', "Hello world 2\n");
        $this->execGit(['config', 'user.name', 'Second Contributor']);
        $this->execGit(['config', 'user.email', 'second@eidcloud.com']);
        $this->execGit(['add', '.']);
        $this->execGit(['commit', '-m', 'feat: second commit']);

        $runner = new GitCommandRunner($this->tempRepoPath);
        $auditor = new BusFactorAuditor($runner);
        $result = $auditor->audit();

        $this->assertEquals(2, $result['total_commits'], "Total commits = 2");
        $this->assertEquals(2, $result['author_count'], "Total authors = 2");
        $this->assertGreaterThanOrEqual(1, $result['bus_factor'], "Bus factor >= 1");
        $this->assertNotEmpty($result['top_contributors'], "Top contributors populated");
        $this->assertArrayHasKey('late_night_commits', $result, "Late night stats present");
        $this->assertArrayHasKey('velocity', $result, "Velocity stats present");
    }

    public function testLargeFileAuditor(): void
    {
        // Create an intentionally oversized blob (> 100KB for test)
        $dummyPath = $this->tempRepoPath . DIRECTORY_SEPARATOR . 'large_file.bin';
        file_put_contents($dummyPath, str_repeat('A', 150 * 1024)); // 150KB
        $this->execGit(['add', 'large_file.bin']);
        $this->execGit(['commit', '-m', 'add large file']);

        $runner = new GitCommandRunner($this->tempRepoPath);
        $auditor = new LargeFileAuditor($runner);

        // Audit with 100KB threshold
        $result = $auditor->audit(100 * 1024);
        $this->assertGreaterThanOrEqual(1, count($result['oversized_blobs']), "Detected oversized blob");
        $this->assertEquals('large_file.bin', $result['oversized_blobs'][0]['path'], "Blob path matches");

        // Test parser
        $this->assertEquals(10485760, LargeFileAuditor::parseThreshold('10MB'), "Parsed 10MB to bytes");
        $this->assertEquals(1073741824, LargeFileAuditor::parseThreshold('1GB'), "Parsed 1GB to bytes");
    }

    public function testBranchAuditor(): void
    {
        // Create a merged feature branch
        $this->execGit(['checkout', '-b', 'feature-clean']);
        file_put_contents($this->tempRepoPath . DIRECTORY_SEPARATOR . 'clean.txt', "Clean test");
        $this->execGit(['add', '.']);
        $this->execGit(['commit', '-m', 'clean feature']);
        $this->execGit(['checkout', 'main']);
        $this->execGit(['merge', 'feature-clean']);

        // Create an unmerged branch
        $this->execGit(['checkout', '-b', 'feature-wip']);
        file_put_contents($this->tempRepoPath . DIRECTORY_SEPARATOR . 'wip.txt', "WIP test");
        $this->execGit(['add', '.']);
        $this->execGit(['commit', '-m', 'wip commit']);
        $this->execGit(['checkout', 'main']);

        $runner = new GitCommandRunner($this->tempRepoPath);
        $auditor = new BranchAuditor($runner);
        $result = $auditor->audit(0); // 0 days to flag inactive if any

        $this->assertEquals('main', $result['default_branch'], "Default branch is main");
        $this->assertContains('feature-clean', $result['merged_branches'], "feature-clean in merged branches");
        $this->assertContains('feature-wip', $result['unmerged_branches'], "feature-wip in unmerged branches");
    }

    public function testHealthScorecardAndRemediation(): void
    {
        $doctor = new GitDoctor($this->tempRepoPath);
        $diag = $doctor->diagnose();

        $this->assertArrayHasKey('scorecard', $diag, "Diagnose returns scorecard");
        $this->assertArrayHasKey('overall_score', $diag['scorecard'], "Scorecard has overall_score");
        $this->assertArrayHasKey('grade', $diag['scorecard'], "Scorecard has grade");
        $this->assertNotEmpty($diag['remediation']['bash_prune_script'], "Bash prune script generated");
        $this->assertNotEmpty($diag['remediation']['powershell_prune_script'], "PowerShell prune script generated");
    }

    // Helper assertions
    private function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException("FAILED Assertion: {$message}");
        }
    }

    private function assertEquals(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException("FAILED Assertion: {$message}. Expected: " . var_export($expected, true) . ", Got: " . var_export($actual, true));
        }
    }

    private function assertGreaterThanOrEqual(int|float $min, int|float $actual, string $message): void
    {
        if ($actual < $min) {
            throw new \RuntimeException("FAILED Assertion: {$message}. {$actual} is not >= {$min}");
        }
    }

    private function assertNotEmpty(mixed $val, string $message): void
    {
        if (empty($val)) {
            throw new \RuntimeException("FAILED Assertion: {$message}. Value is empty");
        }
    }

    private function assertArrayHasKey(string|int $key, array $arr, string $message): void
    {
        if (!array_key_exists($key, $arr)) {
            throw new \RuntimeException("FAILED Assertion: {$message}. Key '{$key}' does not exist in array");
        }
    }

    private function assertContains(mixed $needle, array $haystack, string $message): void
    {
        if (!in_array($needle, $haystack, true)) {
            throw new \RuntimeException("FAILED Assertion: {$message}. Item not found in array");
        }
    }
}
