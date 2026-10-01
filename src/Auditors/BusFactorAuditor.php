<?php

declare(strict_types=1);

namespace EidCloud\GitDoctor\Auditors;

use EidCloud\GitDoctor\Git\GitCommandRunner;

class BusFactorAuditor
{
    private GitCommandRunner $git;

    public function __construct(GitCommandRunner $git)
    {
        $this->git = $git;
    }

    /**
     * Audit repository commit velocity, contributor statistics, bus-factor, churn hotspots, and late-night patterns.
     *
     * @return array{
     *   total_commits: int,
     *   author_count: int,
     *   bus_factor: int,
     *   bus_factor_risk: string,
     *   top_contributors: array<int, array{author: string, email: string, commits: int, percentage: float}>,
     *   churn_hotspots: array<int, array{file: string, modifications: int}>,
     *   late_night_commits: array{count: int, percentage: float, warning: bool},
     *   velocity: array{first_commit_date: string, latest_commit_date: string, total_days: int, commits_per_week: float}
     * }
     */
    public function audit(): array
    {
        // 1. Contributor statistics
        // git shortlog -sne --all
        $shortlog = $this->git->runSafe(['shortlog', '-sne', '--all']);
        $lines = explode("\n", trim($shortlog));

        $authors = [];
        $totalCommits = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Format: "   142  Jane Doe <jane@example.com>"
            if (preg_match('/^(\d+)\s+(.+?)\s+<([^>]+)>$/', $line, $matches)) {
                $count = (int)$matches[1];
                $name = trim($matches[2]);
                $email = trim($matches[3]);

                $totalCommits += $count;
                $authors[] = [
                    'author' => $name,
                    'email' => $email,
                    'commits' => $count,
                ];
            }
        }

        // Calculate Bus Factor: minimum number of authors who account for >= 50% of commits
        $accumulated = 0;
        $busFactor = 0;
        $topContributors = [];

        foreach ($authors as $auth) {
            $pct = $totalCommits > 0 ? round(($auth['commits'] / $totalCommits) * 100, 2) : 0.0;
            $topContributors[] = [
                'author' => $auth['author'],
                'email' => $auth['email'],
                'commits' => $auth['commits'],
                'percentage' => $pct,
            ];

            if ($accumulated < ($totalCommits * 0.5)) {
                $accumulated += $auth['commits'];
                $busFactor++;
            }
        }

        if ($busFactor === 0 && count($authors) > 0) {
            $busFactor = 1;
        }

        $busFactorRisk = match (true) {
            $busFactor <= 1 => 'CRITICAL',
            $busFactor === 2 => 'HIGH',
            $busFactor <= 4 => 'MODERATE',
            default => 'HEALTHY',
        };

        // 2. Churn hotspots (most frequently modified files in last 1000 commits)
        $churnHotspots = $this->calculateChurnHotspots();

        // 3. Late-night commit patterns (commits authored between 23:00 and 05:00)
        $lateNightStats = $this->calculateLateNightCommits($totalCommits);

        // 4. Commit velocity
        $velocity = $this->calculateVelocity($totalCommits);

        return [
            'total_commits' => $totalCommits,
            'author_count' => count($authors),
            'bus_factor' => $busFactor,
            'bus_factor_risk' => $busFactorRisk,
            'top_contributors' => array_slice($topContributors, 0, 10),
            'churn_hotspots' => $churnHotspots,
            'late_night_commits' => $lateNightStats,
            'velocity' => $velocity,
        ];
    }

    /**
     * @return array<int, array{file: string, modifications: int}>
     */
    private function calculateChurnHotspots(): array
    {
        // git log --name-only --format="" -n 1000
        $output = $this->git->runSafe(['log', '--name-only', '--format=', '-n', '1000']);
        $lines = explode("\n", trim($output));

        $fileCounts = [];
        foreach ($lines as $line) {
            $file = trim($line);
            if ($file === '' || str_starts_with($file, '.git')) {
                continue;
            }
            $fileCounts[$file] = ($fileCounts[$file] ?? 0) + 1;
        }

        arsort($fileCounts);

        $hotspots = [];
        $count = 0;
        foreach ($fileCounts as $file => $mods) {
            $hotspots[] = [
                'file' => $file,
                'modifications' => $mods,
            ];
            $count++;
            if ($count >= 10) {
                break;
            }
        }

        return $hotspots;
    }

    /**
     * @return array{count: int, percentage: float, warning: bool}
     */
    private function calculateLateNightCommits(int $totalCommits): array
    {
        // git log --format="%ad" --date=format:"%H"
        $output = $this->git->runSafe(['log', '--format=%ad', '--date=format:%H']);
        $hours = explode("\n", trim($output));

        $lateNightCount = 0;
        $analyzedCommits = 0;

        foreach ($hours as $h) {
            $h = trim($h);
            if ($h === '') {
                continue;
            }
            $analyzedCommits++;
            $hour = (int)$h;
            // 23:00 to 05:00
            if ($hour >= 23 || $hour < 5) {
                $lateNightCount++;
            }
        }

        $pct = $analyzedCommits > 0 ? round(($lateNightCount / $analyzedCommits) * 100, 2) : 0.0;
        $warning = $pct > 25.0; // Over 25% late-night commits signals burnout/crunch risk

        return [
            'count' => $lateNightCount,
            'percentage' => $pct,
            'warning' => $warning,
        ];
    }

    /**
     * @return array{first_commit_date: string, latest_commit_date: string, total_days: int, commits_per_week: float}
     */
    private function calculateVelocity(int $totalCommits): array
    {
        if ($totalCommits === 0) {
            return [
                'first_commit_date' => 'N/A',
                'latest_commit_date' => 'N/A',
                'total_days' => 0,
                'commits_per_week' => 0.0,
            ];
        }

        // Latest commit timestamp
        $latestTs = (int)trim($this->git->runSafe(['log', '-1', '--format=%ct']));
        // First commit timestamp
        $firstTs = (int)trim($this->git->runSafe(['log', '--reverse', '-1', '--format=%ct']));

        if ($firstTs === 0 || $latestTs === 0) {
            return [
                'first_commit_date' => 'N/A',
                'latest_commit_date' => 'N/A',
                'total_days' => 0,
                'commits_per_week' => 0.0,
            ];
        }

        $diffSeconds = max(1, $latestTs - $firstTs);
        $days = (int)ceil($diffSeconds / 86400);
        $weeks = max(1.0, $days / 7.0);
        $commitsPerWeek = round($totalCommits / $weeks, 2);

        return [
            'first_commit_date' => date('Y-m-d H:i:s', $firstTs),
            'latest_commit_date' => date('Y-m-d H:i:s', $latestTs),
            'total_days' => $days,
            'commits_per_week' => $commitsPerWeek,
        ];
    }
}
