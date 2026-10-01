<?php

declare(strict_types=1);

namespace EidCloud\GitDoctor\Remediation;

class RemediationGenerator
{
    /**
     * Generate Bash cleanup script (`git-prune.sh`).
     *
     * @param array<int, string> $mergedBranches
     * @param array<int, array{hash: string, size_bytes: int, size_formatted: string, path: string}> $oversizedBlobs
     * @param int $danglingCount
     */
    public static function generateBashPruneScript(
        array $mergedBranches,
        array $oversizedBlobs,
        int $danglingCount
    ): string {
        $date = date('Y-m-d H:i:s');
        $script = <<<BASH
#!/usr/bin/env bash
# ==============================================================================
# eidcloud-git-doctor automated repository cleanup script
# Generated: {$date}
# CAUTION: Review before running in production!
# ==============================================================================

set -euo pipefail

echo "🩺 Starting EidCloud Git Doctor Repository Pruning..."

# 1. Fetch pruned remotes
echo "==> Pruning tracking references no longer on remote..."
git remote prune origin || true

BASH;

        if (count($mergedBranches) > 0) {
            $script .= "\n# 2. Delete safely merged local branches\n";
            $script .= "echo \"==> Removing merged local branches...\"\n";
            foreach ($mergedBranches as $b) {
                $script .= "git branch -d " . escapeshellarg($b) . " || true\n";
            }
        }

        if ($danglingCount > 0) {
            $script .= "\n# 3. Clean up dangling and unreachable references\n";
            $script .= "echo \"==> Expiring reflogs and pruning loose unreachable objects...\"\n";
            $script .= "git reflog expire --expire=now --all\n";
            $script .= "git prune --progress\n";
            $script .= "git gc --prune=now --aggressive\n";
        }

        if (count($oversizedBlobs) > 0) {
            $script .= "\n# 4. Large Blobs Remediation Advice\n";
            $script .= "# Found " . count($oversizedBlobs) . " large blobs. Recommended: use git-filter-repo or git-lfs:\n";
            foreach (array_slice($oversizedBlobs, 0, 5) as $blob) {
                $script .= "#   Size: {$blob['size_formatted']} | Hash: {$blob['hash']} | Path: {$blob['path']}\n";
            }
            $script .= "# Example: git lfs track \"*.bin\"\n";
        }

        $script .= "\necho \"✅ EidCloud Git Doctor Prune completed successfully!\"\n";

        return $script;
    }

    /**
     * Generate PowerShell cleanup script (`git-prune.ps1`).
     *
     * @param array<int, string> $mergedBranches
     * @param array<int, array{hash: string, size_bytes: int, size_formatted: string, path: string}> $oversizedBlobs
     * @param int $danglingCount
     */
    public static function generatePowerShellPruneScript(
        array $mergedBranches,
        array $oversizedBlobs,
        int $danglingCount
    ): string {
        $date = date('Y-m-d H:i:s');
        $script = <<<PS1
# ==============================================================================
# eidcloud-git-doctor automated repository cleanup script (PowerShell)
# Generated: {$date}
# CAUTION: Review before running in production!
# ==============================================================================

\$ErrorActionPreference = "Continue"

Write-Host "🩺 Starting EidCloud Git Doctor Repository Pruning..." -ForegroundColor Cyan

# 1. Fetch pruned remotes
Write-Host "==> Pruning tracking references no longer on remote..." -ForegroundColor Yellow
git remote prune origin

PS1;

        if (count($mergedBranches) > 0) {
            $script .= "\n# 2. Delete safely merged local branches\n";
            $script .= "Write-Host \"==> Removing merged local branches...\" -ForegroundColor Yellow\n";
            foreach ($mergedBranches as $b) {
                $script .= "git branch -d \"{$b}\"\n";
            }
        }

        if ($danglingCount > 0) {
            $script .= "\n# 3. Clean up dangling and unreachable references\n";
            $script .= "Write-Host \"==> Expiring reflogs and pruning loose unreachable objects...\" -ForegroundColor Yellow\n";
            $script .= "git reflog expire --expire=now --all\n";
            $script .= "git prune --progress\n";
            $script .= "git gc --prune=now --aggressive\n";
        }

        if (count($oversizedBlobs) > 0) {
            $script .= "\n# 4. Large Blobs Remediation Advice\n";
            $script .= "# Found " . count($oversizedBlobs) . " large blobs. Recommended: use git-filter-repo or git-lfs:\n";
            foreach (array_slice($oversizedBlobs, 0, 5) as $blob) {
                $script .= "#   Size: {$blob['size_formatted']} | Hash: {$blob['hash']} | Path: {$blob['path']}\n";
            }
            $script .= "# Example: git lfs track \"*.bin\"\n";
        }

        $script .= "\nWrite-Host \"✅ EidCloud Git Doctor Prune completed successfully!\" -ForegroundColor Green\n";

        return $script;
    }

    /**
     * Generate Git LFS migration guidance and .gitattributes template.
     *
     * @param array<int, array{hash: string, size_bytes: int, size_formatted: string, path: string}> $oversizedBlobs
     */
    public static function generateLfsAdvice(array $oversizedBlobs): string
    {
        $extensions = [];
        foreach ($oversizedBlobs as $blob) {
            $path = $blob['path'];
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            if ($ext !== '') {
                $extensions[strtolower($ext)] = true;
            }
        }

        $advice = "# Git LFS Migration Guidance\n\n";
        $advice .= "### 1. Install Git LFS\n```bash\ngit lfs install\n```\n\n";
        $advice .= "### 2. Track Identified Large Extensions in .gitattributes\n```bash\n";

        if (count($extensions) > 0) {
            foreach (array_keys($extensions) as $ext) {
                $advice .= "git lfs track \"*.{$ext}\"\n";
            }
        } else {
            $advice .= "git lfs track \"*.psd\"\ngit lfs track \"*.zip\"\ngit lfs track \"*.bin\"\n";
        }

        $advice .= "git add .gitattributes\ngit commit -m \"chore: configure git-lfs tracking\"\n```\n\n";
        $advice .= "### 3. Migrate Existing Historical Blobs (Optional)\n```bash\n";
        $advice .= "# Migrate repository history using git-lfs migrate:\n";
        if (count($extensions) > 0) {
            $extList = implode(',', array_map(fn($e) => "*.{$e}", array_keys($extensions)));
            $advice .= "git lfs migrate import --include=\"{$extList}\" --everything\n";
        } else {
            $advice .= "git lfs migrate import --include=\"*.bin,*.tar.gz,*.iso\" --everything\n";
        }
        $advice .= "```\n";

        return $advice;
    }
}
