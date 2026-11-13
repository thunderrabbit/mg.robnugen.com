<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\Standards\StepTree;

/**
 * Hermetic: the step files in this repo are ones mg's importer will accept.
 * A misnamed or malformed file fails here, before it is deployed and stops
 * every scan.
 */
final class StandardsRealStepFilesTest extends Unit
{
    public function testTheStepFilesInThisRepoLoad(): void
    {
        $root = dirname(__DIR__, 2) . '/tools/site-standards/steps';
        $files = [];
        $found = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($found as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'md') {
                continue;
            }
            $files[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
        }

        $steps = 0;
        foreach (StepTree::fromFiles($files)->standards as $standard) {
            foreach ($standard->tracks as $track) {
                $steps += count($track->steps);
            }
        }

        $this->assertGreaterThan(0, $steps);
    }
}
