<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/GitDoctorTest.php';

use EidCloud\GitDoctor\Tests\GitDoctorTest;

echo "\033[1;36m============================================================\033[0m\n";
echo "\033[1;36m       🧪 EidCloud Git Doctor Automated Test Suite          \033[0m\n";
echo "\033[1;36m============================================================\033[0m\n\n";

$testCase = new GitDoctorTest();
$refClass = new ReflectionClass($testCase);
$methods = array_filter($refClass->getMethods(), function ($m) {
    return str_starts_with($m->getName(), 'test');
});

$passed = 0;
$failed = 0;
$total = count($methods);

foreach ($methods as $method) {
    $methodName = $method->getName();
    echo "Running {$methodName}... ";

    try {
        $testCase->setUp();
        $testCase->{$methodName}();
        $testCase->tearDown();
        echo "\033[1;32mPASSED\033[0m\n";
        $passed++;
    } catch (\Throwable $e) {
        $testCase->tearDown();
        echo "\033[1;31mFAILED\033[0m\n";
        echo "  \033[31mError: " . $e->getMessage() . "\033[0m\n";
        echo "  \033[33mTrace:\033[0m\n" . $e->getTraceAsString() . "\n";
        $failed++;
    }
}

echo "\n------------------------------------------------------------\n";
if ($failed === 0) {
    echo "\033[1;32mRESULT: ALL {$passed}/{$total} TESTS PASSED (100%)\033[0m\n";
    exit(0);
} else {
    echo "\033[1;31mRESULT: {$failed}/{$total} TESTS FAILED\033[0m\n";
    exit(1);
}
