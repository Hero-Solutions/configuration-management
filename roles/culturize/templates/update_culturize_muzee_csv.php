<?php
declare(strict_types=1);

$csvUrl = '{{ culturize.muzee_csv.csv_url }}';
$sourceHosts = {{ culturize.muzee_csv.source_hosts | to_json }};
$sourcePathPrefix = rtrim('{{ culturize.muzee_csv.source_path_prefix | default("/collection/work") }}', '/');
$allowedSources = {{ (culturize.allowed_sources + (culturize.allowed_sources_extra | default([]))) | to_json }};
$allowedDestinations = {{ (culturize.allowed_destinations + (culturize.allowed_destinations_extra | default([]))) | to_json }};
$targetFile = '/etc/nginx/{{ culturize.nginx.name }}/nginx_redirect.conf';
$temporaryFile = '{{ culturize.dir }}/nginx_redirect.conf.tmp';
$errorFile = '{{ culturize.dir }}/error.log';

function fail(string $message, int $exitCode = 1): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($exitCode);
}

function hostAllowed(string $host, array $allowedHosts): bool
{
    foreach ($allowedHosts as $allowedHost) {
        if (strtolower($host) === strtolower($allowedHost)) {
            return true;
        }
    }

    return false;
}

function destinationAllowed(string $url, array $allowedHosts): bool
{
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }

    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host)) {
        return false;
    }

    return hostAllowed($host, $allowedHosts);
}

function sourceAllowed(string $source, array $allowedSources): bool
{
    return in_array($source, $allowedSources, true);
}

function validIdentifier(string $identifier): bool
{
    return preg_match("/^[a-zA-Z0-9\\-_']+$/", $identifier) === 1;
}

function buildRewriteLines(
    string $sourceUrl,
    string $destinationUrl,
    array $sourceHosts,
    string $sourcePathPrefix,
    array $allowedSources,
    array $allowedDestinations
): array {
    $sourceHost = parse_url($sourceUrl, PHP_URL_HOST);
    $sourcePath = parse_url($sourceUrl, PHP_URL_PATH);

    if (!is_string($sourceHost) || !is_string($sourcePath) || !hostAllowed($sourceHost, $sourceHosts)) {
        return [];
    }

    $mappings = [
        '/data/' => ['/data/', '/id/'],
        '/representation/' => ['/representation/', '/id/'],
    ];

    foreach ($mappings as $csvPath => $rewriteSources) {
        $prefix = $sourcePathPrefix . $csvPath;
        if (substr($sourcePath, 0, strlen($prefix)) !== $prefix) {
            continue;
        }

        $identifier = substr($sourcePath, strlen($prefix));
        if (!validIdentifier($identifier) || !destinationAllowed($destinationUrl, $allowedDestinations)) {
            return [false, $sourceUrl . ',' . $destinationUrl];
        }

        $lines = [];
        foreach ($rewriteSources as $rewriteSource) {
            if (!sourceAllowed($rewriteSource, $allowedSources)) {
                return [false, $sourceUrl . ',' . $destinationUrl];
            }

            $lines[] = 'rewrite ' . $rewriteSource . $identifier . '$ ' . $destinationUrl . ' redirect;' . PHP_EOL;
        }

        return $lines;
    }

    return [];
}

@unlink($errorFile);
@unlink($temporaryFile);

$input = @fopen($csvUrl, 'r');
if ($input === false) {
    fail('Could not open CSV URL: ' . $csvUrl);
}

$output = @fopen($temporaryFile, 'w');
if ($output === false) {
    fclose($input);
    fail('Could not write candidate file: ' . $temporaryFile);
}

$invalidLines = [];
$lineCount = 0;

while (($line = fgets($input)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }

    $row = str_getcsv($line);
    if (count($row) < 2) {
        continue;
    }

    $rewriteLines = buildRewriteLines(trim($row[0]), trim($row[1]), $sourceHosts, $sourcePathPrefix, $allowedSources, $allowedDestinations);
    if ($rewriteLines === []) {
        continue;
    }

    if ($rewriteLines[0] === false) {
        $invalidLines[] = $rewriteLines[1];
        continue;
    }

    foreach ($rewriteLines as $rewriteLine) {
        fwrite($output, $rewriteLine);
        $lineCount++;
    }
}

fclose($input);
fclose($output);

if ($invalidLines !== []) {
    file_put_contents($errorFile, implode(PHP_EOL, $invalidLines) . PHP_EOL);
    @unlink($temporaryFile);
    fail('Invalid culturize redirect lines found. See ' . $errorFile);
}

if ($lineCount === 0) {
    @unlink($temporaryFile);
    fail('No culturize redirect lines were generated.');
}

if (!copy($temporaryFile, $targetFile)) {
    @unlink($temporaryFile);
    fail('Could not copy redirect file into place: ' . $targetFile);
}

@unlink($temporaryFile);
