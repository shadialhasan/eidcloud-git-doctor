<?php

declare(strict_types=1);

namespace EidCloud\GitDoctor;

use EidCloud\GitDoctor\Auditors\BranchAuditor;
use EidCloud\GitDoctor\Auditors\BusFactorAuditor;
use EidCloud\GitDoctor\Auditors\LargeFileAuditor;
use EidCloud\GitDoctor\Auditors\ReleaseReadinessAuditor;
use EidCloud\GitDoctor\Git\GitCommandRunner;
use EidCloud\GitDoctor\Remediation\RemediationGenerator;
use EidCloud\GitDoctor\Reports\HealthScorecard;
use RuntimeException;

class GitDoctor
{
    public const VERSION = '1.0.0';

    private GitCommandRunner $git;
    private LargeFileAuditor $largeFileAuditor;
    private BusFactorAuditor $busFactorAuditor;
    private BranchAuditor $branchAuditor;
    private ReleaseReadinessAuditor $releaseAuditor;

    public function __construct(string $repoPath = '.')
    {
        $this->git = new GitCommandRunner($repoPath);
        if (!$this->git->isGitRepository()) {
            throw new RuntimeException("Target path is not a valid Git repository: {$this->git->getRepoPath()}");
        }

        $this->largeFileAuditor = new LargeFileAuditor($this->git);
        $this->busFactorAuditor = new BusFactorAuditor($this->git);
        $this->branchAuditor = new BranchAuditor($this->git);
        $this->releaseAuditor = new ReleaseReadinessAuditor($this->git);
    }

    public function getGitRunner(): GitCommandRunner
    {
        return $this->git;
    }

    public function getLargeFileAuditor(): LargeFileAuditor
    {
        return $this->largeFileAuditor;
    }

    public function getBusFactorAuditor(): BusFactorAuditor
    {
        return $this->busFactorAuditor;
    }

    public function getBranchAuditor(): BranchAuditor
    {
        return $this->branchAuditor;
    }

    public function getReleaseAuditor(): ReleaseReadinessAuditor
    {
        return $this->releaseAuditor;
    }

    /**
     * Run full diagnostics and return structured comprehensive report.
     *
     * @param int $largeFileThresholdBytes (default 50MB)
     * @param int $staleBranchDays (default 90 days)
     * @return array<string, mixed>
     */
    public function diagnose(int $largeFileThresholdBytes = 52428800, int $staleBranchDays = 90): array
    {
        $largeFiles = $this->largeFileAuditor->audit($largeFileThresholdBytes);
        $busFactor = $this->busFactorAuditor->audit();
        $branches = $this->branchAuditor->audit($staleBranchDays);
        $release = $this->releaseAuditor->audit();

        $scorecard = HealthScorecard::calculate($largeFiles, $busFactor, $branches, $release);

        $bashPrune = RemediationGenerator::generateBashPruneScript(
            $branches['merged_branches'],
            $largeFiles['oversized_blobs'],
            $branches['dangling_count']
        );

        $psPrune = RemediationGenerator::generatePowerShellPruneScript(
            $branches['merged_branches'],
            $largeFiles['oversized_blobs'],
            $branches['dangling_count']
        );

        $lfsAdvice = RemediationGenerator::generateLfsAdvice($largeFiles['oversized_blobs']);

        return [
            'version' => self::VERSION,
            'timestamp' => date('c'),
            'repo_path' => $this->git->getRepoPath(),
            'scorecard' => $scorecard,
            'audits' => [
                'large_files' => $largeFiles,
                'bus_factor' => $busFactor,
                'branches' => $branches,
                'release_readiness' => $release,
            ],
            'remediation' => [
                'bash_prune_script' => $bashPrune,
                'powershell_prune_script' => $psPrune,
                'lfs_advice' => $lfsAdvice,
            ],
        ];
    }
}
