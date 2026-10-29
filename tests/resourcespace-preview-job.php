<?php

declare(strict_types=1);

// Isolated regression test. Only the supplied handler excerpt and local stubs
// are loaded. No ResourceSpace bootstrap, configuration, database or network.
const STATUS_ERROR = 5;
$lang = ['jq_create_previews_failure_text' => 'Preview failed for %RESOURCE'];
$baseurl_short = '/';
$events = [];

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function get_resource_data($resource, $unused)
{
    return in_array($resource, [9001, 9002], true)
        ? ['ref' => $resource, 'file_extension' => 'tif'] : false;
}

function delete_previews($resource): void
{
    // Reproduce the supplied delete_previews() failure when lookup returns false.
    if (get_resource_data($resource, false) === false) {
        throw new RuntimeException('Trying to access array offset on false');
    }
    $GLOBALS['events']['deleted'][] = $resource;
}

function create_previews(...$arguments): bool
{
    $GLOBALS['events']['created'][] = $arguments;
    return $arguments[0] !== 9002;
}

function update_disk_usage($resource): void
{
    $GLOBALS['events']['disk_usage'][] = $resource;
}

function job_queue_update($jobref, $job_data, $status): void
{
    $GLOBALS['events']['updates'][$jobref] = [$job_data, $status];
}

function message_add(...$arguments): void
{
    $GLOBALS['events']['messages'][] = $arguments;
}

function runJob(string $handler, int $jobref, array $job_data): void
{
    $job = ['user' => 9];
    $job_failure_text = '';
    include $handler;
    $GLOBALS['events']['returned'][] = $jobref;
}

function runCommand(array $command, string $cwd): void
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    check(is_resource($process), 'Unable to start local verification command.');
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(proc_close($process) === 0, 'Local verification failed: ' . $output);
}

$sandbox = __DIR__ . '/preview-job-test-' . bin2hex(random_bytes(6));
$handler = $sandbox . '/include/job_handlers/create_previews.php';
$stub = $sandbox . '/include/image_processing.php';
$patch = dirname(__DIR__) . '/roles/resourcespace/files/missing-preview-resource.patch';
mkdir($sandbox . '/include/job_handlers', 0700, true);

try {
    copy(__DIR__ . '/fixtures/resourcespace-create-previews.php', $handler);
    file_put_contents($stub, "<?php\n// All dependencies are local stubs in the test runner.\n");

    $reproduced = false;
    try {
        runJob($handler, 2, ['resource' => 8420]);
    } catch (RuntimeException $error) {
        $reproduced = $error->getMessage() === 'Trying to access array offset on false';
    }
    check($reproduced && $events === [], 'Original failure was not reproduced before job completion.');

    // Apply the deliverable patch itself to the user-supplied excerpt.
    runCommand(['git', '-c', 'core.fsmonitor=false', 'apply', '--no-index', '--check', $patch], $sandbox);
    runCommand(['git', '-c', 'core.fsmonitor=false', 'apply', '--no-index', $patch], $sandbox);
    runCommand([PHP_BINARY, '-n', '-l', $handler], $sandbox);

    ob_start();
    try {
        runJob($handler, 2, ['resource' => 8420]);
        runJob($handler, 3, ['resource' => 9001, 'extension' => 'tif', 'alternative' => 7]);
        runJob($handler, 4, ['resource' => 9002]);
        runJob($handler, 5, []);
        runJob($handler, 6, ['resource' => 9001]);
    } finally {
        $log = ob_get_clean();
    }

    check($events['returned'] === [2, 3, 4, 5, 6], 'Queue did not continue after the missing resource.');
    check($events['updates'] === [
        2 => [['resource' => 8420], STATUS_ERROR],
        4 => [['resource' => 9002], STATUS_ERROR],
        5 => [[], STATUS_ERROR],
    ], 'Missing resource or existing failure paths have incorrect status updates.');
    check($events['deleted'] === [9001, 9002, 9001], 'Missing resource reached preview deletion.');
    check(array_column($events['created'], 0) === [9001, 9002, 9001], 'Preview generation changed unexpectedly.');
    check($events['created'][0] === [9001, false, 'tif', false, false, 7, true, false, true], 'Preview arguments changed.');
    check($events['disk_usage'] === [9001, 9001], 'Successful resource handling changed.');
    check(count($events['messages']) === 2, 'Existing failure notifications changed.');
    check(str_contains($log, 'Resource #8420 not found; skipping preview job #2.'), 'Missing resource was not logged.');

    echo "PASS: original crash reproduced; deliverable patch applies and parses; missing resource fails only its job; next jobs run; existing success and failure paths preserved.\n";
} finally {
    // Remove only the exact files and empty directories created by this test.
    foreach ([$handler, $stub] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($sandbox . '/include/job_handlers');
    rmdir($sandbox . '/include');
    rmdir($sandbox);
}
