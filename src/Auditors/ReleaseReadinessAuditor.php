<?php

declare(strict_types=1);

namespace EidCloud\GitDoctor\Auditors;

use EidCloud\GitDoctor\Git\GitCommandRunner;

class ReleaseReadinessAuditor
{
    private GitCommandRunner $git;

    public function __construct(GitCommandRunner $git)
    {
        $this->git = $git;
    }

    /**
     * Audit repository release readiness:
     * - Semantic version tags
     * - CHANGELOG existence and completeness
     * - LICENSE presence and valid format
     * - README presence and minimum structure
     * - CI/CD configuration integrity (.github/workflows, .gitlab-ci.yml, etc.)
     *
     * @return array{
     *   ready: bool,
     *   score: int,
     *   semver: array{has_tags: bool, latest_tag: string, valid_semver: bool, tags: array<int, string>},
     *   changelog: array{exists: bool, file: ?string, has_unreleased_or_latest: bool},
     *   license: array{exists: bool, file: ?string, detected_type: string},
     *   readme: array{exists: bool, file: ?string, size_bytes: int},
     *   cicd: array{configured: bool, files: array<int, string>},
     *   issues: array<int, string>,
     *   recommendations: array<int, string>
     * }
     */
    public function audit(): array
    {
        $repoPath = $this->git->getRepoPath();
        $issues = [];
        $recommendations = [];
        $score = 100;

        // 1. Semantic Version Tag Check
        $tagsOutput = $this->git->runSafe(['tag', '--sort=-version:refname']);
        $rawTags = array_filter(array_map('trim', explode("\n", trim($tagsOutput))));
        $latestTag = $rawTags[0] ?? '';
        $hasTags = count($rawTags) > 0;
        $validSemver = false;

        // SemVer 2.0 regex (optional leading v)
        $semverRegex = '/^v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';

        if ($hasTags) {
            $validSemver = (bool)preg_match($semverRegex, $latestTag);
            if (!$validSemver) {
                $issues[] = "Latest tag '{$latestTag}' does not strictly follow Semantic Versioning (SemVer 2.0).";
                $score -= 10;
            }
        } else {
            $issues[] = 'No Git release tags found in the repository.';
            $recommendations[] = 'Tag your release commits with semantic versions (e.g. `git tag -a v1.0.0 -m "Release v1.0.0"`).';
            $score -= 20;
        }

        // 2. CHANGELOG Check
        $changelogFiles = ['CHANGELOG.md', 'CHANGELOG', 'HISTORY.md', 'RELEASES.md', 'changelog.md'];
        $foundChangelog = null;
        foreach ($changelogFiles as $cf) {
            if (file_exists($repoPath . DIRECTORY_SEPARATOR . $cf)) {
                $foundChangelog = $cf;
                break;
            }
        }

        $changelogComplete = false;
        if ($foundChangelog !== null) {
            $content = (string)file_get_contents($repoPath . DIRECTORY_SEPARATOR . $foundChangelog);
            if (strlen(trim($content)) > 30) {
                $changelogComplete = true;
            } else {
                $issues[] = "Changelog file '{$foundChangelog}' is suspiciously short or empty.";
                $score -= 10;
            }
        } else {
            $issues[] = 'No CHANGELOG.md or release notes file found.';
            $recommendations[] = 'Create a CHANGELOG.md adhering to Keep a Changelog standards.';
            $score -= 15;
        }

        // 3. LICENSE Check
        $licenseFiles = ['LICENSE', 'LICENSE.md', 'LICENSE.txt', 'COPYING'];
        $foundLicense = null;
        $licenseType = 'UNKNOWN';
        foreach ($licenseFiles as $lf) {
            if (file_exists($repoPath . DIRECTORY_SEPARATOR . $lf)) {
                $foundLicense = $lf;
                $licenseContent = (string)file_get_contents($repoPath . DIRECTORY_SEPARATOR . $lf);
                if (stripos($licenseContent, 'MIT') !== false) {
                    $licenseType = 'MIT';
                } elseif (stripos($licenseContent, 'Apache') !== false) {
                    $licenseType = 'Apache-2.0';
                } elseif (stripos($licenseContent, 'GNU GENERAL PUBLIC') !== false) {
                    $licenseType = 'GPL';
                } elseif (stripos($licenseContent, 'BSD') !== false) {
                    $licenseType = 'BSD';
                } else {
                    $licenseType = 'CUSTOM';
                }
                break;
            }
        }

        if ($foundLicense === null) {
            $issues[] = 'No LICENSE file found in repository root.';
            $recommendations[] = 'Add an open-source or proprietary LICENSE file.';
            $score -= 25;
        }

        // 4. README Check
        $readmeFiles = ['README.md', 'README', 'README.txt', 'readme.md'];
        $foundReadme = null;
        $readmeSize = 0;
        foreach ($readmeFiles as $rf) {
            if (file_exists($repoPath . DIRECTORY_SEPARATOR . $rf)) {
                $foundReadme = $rf;
                $readmeSize = (int)filesize($repoPath . DIRECTORY_SEPARATOR . $rf);
                break;
            }
        }

        if ($foundReadme === null) {
            $issues[] = 'No README.md found in repository root.';
            $recommendations[] = 'Create a comprehensive README.md with overview, badges, installation, and usage instructions.';
            $score -= 25;
        } elseif ($readmeSize < 200) {
            $issues[] = "README.md is very brief ({$readmeSize} bytes). More context is recommended.";
            $score -= 5;
        }

        // 5. CI/CD Configuration Check
        $cicdFiles = [];
        $ghWorkflows = $repoPath . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'workflows';
        if (is_dir($ghWorkflows)) {
            $wfFiles = glob($ghWorkflows . DIRECTORY_SEPARATOR . '*.{yml,yaml}', GLOB_BRACE) ?: [];
            foreach ($wfFiles as $wff) {
                $cicdFiles[] = '.github/workflows/' . basename($wff);
            }
        }
        foreach (['.gitlab-ci.yml', '.circleci/config.yml', 'Jenkinsfile', 'azure-pipelines.yml'] as $cf) {
            if (file_exists($repoPath . DIRECTORY_SEPARATOR . $cf)) {
                $cicdFiles[] = $cf;
            }
        }

        $cicdConfigured = count($cicdFiles) > 0;
        if (!$cicdConfigured) {
            $issues[] = 'No automated CI/CD pipeline configuration detected (.github/workflows, etc.).';
            $recommendations[] = 'Set up a continuous integration workflow for automated testing and linting.';
            $score -= 15;
        }

        $finalScore = max(0, min(100, $score));
        $ready = $finalScore >= 80;

        return [
            'ready' => $ready,
            'score' => $finalScore,
            'semver' => [
                'has_tags' => $hasTags,
                'latest_tag' => $latestTag,
                'valid_semver' => $validSemver,
                'tags' => array_slice($rawTags, 0, 10),
            ],
            'changelog' => [
                'exists' => $foundChangelog !== null,
                'file' => $foundChangelog,
                'has_unreleased_or_latest' => $changelogComplete,
            ],
            'license' => [
                'exists' => $foundLicense !== null,
                'file' => $foundLicense,
                'detected_type' => $licenseType,
            ],
            'readme' => [
                'exists' => $foundReadme !== null,
                'file' => $foundReadme,
                'size_bytes' => $readmeSize,
            ],
            'cicd' => [
                'configured' => $cicdConfigured,
                'files' => $cicdFiles,
            ],
            'issues' => $issues,
            'recommendations' => $recommendations,
        ];
    }
}
