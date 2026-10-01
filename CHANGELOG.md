# Changelog

All notable changes to `eidcloud-git-doctor` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-10-01

### Added
- **Core Engine (`GitDoctor`)**: Pure PHP 8.2+ Git repository intelligence, diagnostics, and forensic auditor with zero external vendor dependencies.
- **Large File & Duplicate Auditor (`LargeFileAuditor`)**: High-performance detection of oversized historical blobs (>50MB or custom threshold) and duplicate file trees using Git's batch-check plumbing.
- **Bus Factor & Contributor Analytics (`BusFactorAuditor`)**: Calculates project bus-factor risk, commit velocity (commits/week), churn hotspots, and late-night (burnout) commit patterns.
- **Branch Hygiene Auditor (`BranchAuditor`)**: Detects stale branches (inactive > 90 days), merged branches safe for cleanup, and unreachable/dangling commits via `git fsck`.
- **Release Readiness Auditor (`ReleaseReadinessAuditor`)**: Inspects SemVer 2.0 tags, CHANGELOG completeness, LICENSE presence, README specifications, and CI/CD workflow configurations.
- **Health Scorecard (`HealthScorecard`)**: 100-point scoring algorithm generating repository health grades (`A+` to `F`), category breakdowns, and prioritized actionable remediation items.
- **Remediation Script Generator (`RemediationGenerator`)**: Generates automated Bash (`git-prune.sh`) and PowerShell (`git-prune.ps1`) scripts alongside Git LFS migration advice.
- **CLI Executable (`bin/eidcloud-git-doctor`)**: Full-featured CLI supporting commands `diagnose`, `large-files`, `bus-factor`, `branches`, `release`, and `remediate` with colorized output and JSON mode.
- **Google Colab Quickstart (`notebooks/quickstart.ipynb`)**: 3-cell interactive notebook for repository auditing, bus-factor computation, and blob analysis.
- **Automated Test Suite (`tests/run_tests.php`)**: Comprehensive zero-dependency test runner achieving 100% test coverage and validation.
