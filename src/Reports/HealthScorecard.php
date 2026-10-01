<?php

declare(strict_types=1);

namespace EidCloud\GitDoctor\Reports;

class HealthScorecard
{
    /**
     * Compute an overall health score (0-100) and grade (A+, A, B, C, D, F)
     * based on sub-audit results.
     *
     * @param array<string, mixed> $largeFileAudit
     * @param array<string, mixed> $busFactorAudit
     * @param array<string, mixed> $branchAudit
     * @param array<string, mixed> $releaseAudit
     * @return array{
     *   overall_score: int,
     *   grade: string,
     *   verdict: string,
     *   breakdown: array<string, array{score: int, max: int, status: string, summary: string}>,
     *   action_items: array<int, string>
     * }
     */
    public static function calculate(
        array $largeFileAudit,
        array $busFactorAudit,
        array $branchAudit,
        array $releaseAudit
    ): array {
        $actionItems = [];

        // 1. Repo Hygiene & Large Files (Max 25 pts)
        $hygieneScore = 25;
        $oversizedCount = count($largeFileAudit['oversized_blobs'] ?? []);
        $danglingCount = (int)($branchAudit['dangling_count'] ?? 0);
        $staleCount = count($branchAudit['stale_branches'] ?? []);
        $duplicateCount = count($largeFileAudit['duplicates'] ?? []);

        if ($oversizedCount > 0) {
            $hygieneScore -= min(15, $oversizedCount * 5);
            $actionItems[] = "Found {$oversizedCount} oversized blobs (> {$largeFileAudit['threshold_formatted']}). Migrate to Git LFS or strip from history.";
        }
        if ($danglingCount > 10) {
            $hygieneScore -= 3;
            $actionItems[] = "Found {$danglingCount} dangling commits. Run `git prune` or GC cleanup.";
        }
        if ($staleCount > 5) {
            $hygieneScore -= 4;
            $actionItems[] = "Found {$staleCount} stale branches inactive > 90 days. Prune merged/stale branches.";
        }
        if ($duplicateCount > 5) {
            $hygieneScore -= 3;
            $actionItems[] = "Found {$duplicateCount} duplicate file blobs across repository.";
        }
        $hygieneScore = max(0, $hygieneScore);

        // 2. Bus Factor & Collaboration Health (Max 25 pts)
        $busScore = 25;
        $busFactor = (int)($busFactorAudit['bus_factor'] ?? 1);
        $lateNightWarning = !empty($busFactorAudit['late_night_commits']['warning']);

        if ($busFactor <= 1) {
            $busScore -= 12;
            $actionItems[] = "Bus Factor is 1: High project risk! Single contributor accounts for >50% of repository history.";
        } elseif ($busFactor === 2) {
            $busScore -= 5;
            $actionItems[] = "Bus Factor is 2: Moderate knowledge concentration across just two authors.";
        }

        if ($lateNightWarning) {
            $busScore -= 5;
            $pct = $busFactorAudit['late_night_commits']['percentage'] ?? 0.0;
            $actionItems[] = "High late-night commit ratio ({$pct}% between 23:00-05:00) indicates potential team burnout.";
        }
        $busScore = max(0, $busScore);

        // 3. Branch Hygiene & Flow (Max 25 pts)
        $branchScore = 25;
        $unmergedCount = count($branchAudit['unmerged_branches'] ?? []);
        $mergedCount = count($branchAudit['merged_branches'] ?? []);

        if ($mergedCount > 3) {
            $branchScore -= min(10, (int)($mergedCount * 2));
            $actionItems[] = "Found {$mergedCount} branches already merged into default branch that can be safely deleted.";
        }
        if ($unmergedCount > 10) {
            $branchScore -= 5;
        }
        $branchScore = max(0, $branchScore);

        // 4. Release Readiness & Standards (Max 25 pts)
        // Scaled from release audit score (0-100) -> (0-25)
        $rawReleaseScore = (int)($releaseAudit['score'] ?? 0);
        $releaseScoreScaled = (int)round(($rawReleaseScore / 100) * 25);

        foreach ($releaseAudit['recommendations'] ?? [] as $rec) {
            $actionItems[] = $rec;
        }

        $overallScore = $hygieneScore + $busScore + $branchScore + $releaseScoreScaled;
        $overallScore = max(0, min(100, $overallScore));

        $grade = match (true) {
            $overallScore >= 95 => 'A+',
            $overallScore >= 90 => 'A',
            $overallScore >= 80 => 'B',
            $overallScore >= 70 => 'C',
            $overallScore >= 60 => 'D',
            default => 'F',
        };

        $verdict = match ($grade) {
            'A+', 'A' => 'Repository is in exceptional health! Clean, well-structured, and release-ready.',
            'B' => 'Repository is healthy with minor maintenance opportunities.',
            'C' => 'Repository has noticeable technical debt in branch management, assets, or contributor distribution.',
            'D' => 'Repository requires immediate remediation of blobs, stale branches, or project standards.',
            default => 'Critical forensic attention needed. High risk of data bloat, knowledge silo, or unmaintained status.',
        };

        return [
            'overall_score' => $overallScore,
            'grade' => $grade,
            'verdict' => $verdict,
            'breakdown' => [
                'repo_hygiene' => [
                    'score' => $hygieneScore,
                    'max' => 25,
                    'status' => $hygieneScore >= 20 ? 'PASSED' : ($hygieneScore >= 12 ? 'WARNING' : 'FAILED'),
                    'summary' => "{$oversizedCount} oversized blobs, {$danglingCount} dangling refs, {$duplicateCount} duplicates",
                ],
                'bus_factor' => [
                    'score' => $busScore,
                    'max' => 25,
                    'status' => $busScore >= 20 ? 'PASSED' : ($busScore >= 12 ? 'WARNING' : 'FAILED'),
                    'summary' => "Bus Factor: {$busFactor} ({$busFactorAudit['bus_factor_risk']}), {$busFactorAudit['author_count']} total authors",
                ],
                'branch_hygiene' => [
                    'score' => $branchScore,
                    'max' => 25,
                    'status' => $branchScore >= 20 ? 'PASSED' : ($branchScore >= 12 ? 'WARNING' : 'FAILED'),
                    'summary' => "{$mergedCount} merged branches to prune, {$staleCount} stale branches",
                ],
                'release_readiness' => [
                    'score' => $releaseScoreScaled,
                    'max' => 25,
                    'status' => $releaseScoreScaled >= 20 ? 'PASSED' : ($releaseScoreScaled >= 14 ? 'WARNING' : 'FAILED'),
                    'summary' => "Score: {$rawReleaseScore}/100. License: " . ($releaseAudit['license']['detected_type'] ?? 'NONE'),
                ],
            ],
            'action_items' => array_values(array_unique($actionItems)),
        ];
    }
}
