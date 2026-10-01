<?php

declare(strict_types=1);

namespace EidCloud\GitDoctor\Auditors;

use EidCloud\GitDoctor\Git\GitCommandRunner;

class BranchAuditor
{
    private GitCommandRunner $git;

    public function __construct(GitCommandRunner $git)
    {
        $this->git = $git;
    }

    /**
     * Audit repository branches for stale, unmerged, and dangling references.
     *
     * @param int $staleDays Threshold in days to consider a branch stale (default: 90 days)
     * @return array{
     *   default_branch: string,
     *   total_branches: int,
     *   stale_branches: array<int, array{name: string, last_commit_date: string, author: string, days_inactive: int}>,
     *   merged_branches: array<int, string>,
     *   unmerged_branches: array<int, string>,
     *   dangling_commits: array<int, string>,
     *   dangling_count: int
     * }
     */
    public function audit(int $staleDays = 90): array
    {
        $defaultBranch = $this->detectDefaultBranch();

        // 1. Get all local branches with their last commit timestamp, author, and branch name
        // git for-each-ref --format="%(refname:short)|%(committerdate:raw)|%(authorname)" refs/heads/
        $refOutput = $this->git->runSafe([
            'for-each-ref',
            '--format=%(refname:short)|%(committerdate:raw)|%(authorname)',
            'refs/heads/',
        ]);

        $branches = [];
        $staleBranches = [];
        $now = time();

        $lines = explode("\n", trim($refOutput));
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode('|', $line, 3);
            if (count($parts) >= 3) {
                $bName = trim($parts[0]);
                $rawDate = trim($parts[1]);
                $author = trim($parts[2]);

                $tsParts = explode(' ', $rawDate);
                $commitTs = (int)($tsParts[0] ?? 0);

                $branches[] = $bName;

                if ($bName !== $defaultBranch && $commitTs > 0) {
                    $inactiveDays = (int)floor(($now - $commitTs) / 86400);
                    if ($inactiveDays >= $staleDays) {
                        $staleBranches[] = [
                            'name' => $bName,
                            'last_commit_date' => date('Y-m-d', $commitTs),
                            'author' => $author,
                            'days_inactive' => $inactiveDays,
                        ];
                    }
                }
            }
        }

        // Sort stale branches by days inactive descending
        usort($staleBranches, function ($a, $b) {
            return $b['days_inactive'] <=> $a['days_inactive'];
        });

        // 2. Merged vs Unmerged branches relative to default branch
        $mergedBranches = [];
        $unmergedBranches = [];

        if ($defaultBranch !== '') {
            $mergedOut = $this->git->runSafe(['branch', '--merged', $defaultBranch]);
            $unmergedOut = $this->git->runSafe(['branch', '--no-merged', $defaultBranch]);

            $cleanList = function (string $output) use ($defaultBranch) {
                $list = [];
                foreach (explode("\n", trim($output)) as $b) {
                    $b = trim(str_replace('*', '', $b));
                    if ($b !== '' && $b !== $defaultBranch && !str_contains($b, '->')) {
                        $list[] = $b;
                    }
                }
                return array_values(array_unique($list));
            };

            $mergedBranches = $cleanList($mergedOut);
            $unmergedBranches = $cleanList($unmergedOut);
        }

        // 3. Dangling commits via git fsck --lost-found
        $danglingCommits = $this->auditDanglingCommits();

        return [
            'default_branch' => $defaultBranch,
            'total_branches' => count($branches),
            'stale_branches' => $staleBranches,
            'merged_branches' => $mergedBranches,
            'unmerged_branches' => $unmergedBranches,
            'dangling_commits' => $danglingCommits,
            'dangling_count' => count($danglingCommits),
        ];
    }

    private function detectDefaultBranch(): string
    {
        // Try git symbolic-ref refs/remotes/origin/HEAD
        $symbolic = trim($this->git->runSafe(['symbolic-ref', '--short', 'refs/remotes/origin/HEAD']));
        if ($symbolic !== '') {
            return str_replace('origin/', '', $symbolic);
        }

        // Check local branches: main, master, trunk, development
        $localBranches = array_map('trim', explode("\n", trim($this->git->runSafe(['branch', '--format=%(refname:short)']))));

        foreach (['main', 'master', 'trunk', 'develop'] as $candidate) {
            if (in_array($candidate, $localBranches, true)) {
                return $candidate;
            }
        }

        return $localBranches[0] ?? 'main';
    }

    /**
     * @return array<int, string> List of dangling commit hashes
     */
    private function auditDanglingCommits(): array
    {
        $fsckOutput = $this->git->runSafe(['fsck', '--lost-found', '--unreachable']);
        $lines = explode("\n", $fsckOutput);
        $dangling = [];

        foreach ($lines as $line) {
            $line = trim($line);
            // Matches "dangling commit <hash>" or "unreachable commit <hash>"
            if (preg_match('/^(?:dangling|unreachable)\s+commit\s+([a-f0-9]+)/i', $line, $matches)) {
                $dangling[] = $matches[1];
            }
        }

        return array_values(array_unique($dangling));
    }
}
