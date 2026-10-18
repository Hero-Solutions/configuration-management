<?php
declare(strict_types=1);

// Local task YAML and synthetic values only. No inventory, .env, app bootstrap or network.
$vendor = dirname(__DIR__, 2) . '/iiif-condition-reports/vendor';
require $vendor . '/composer/ClassLoader.php';
$loader = new Composer\Autoload\ClassLoader();
foreach (['Symfony\\Component\\Yaml\\' => '/symfony/yaml',
    'Symfony\\Component\\Dotenv\\' => '/symfony/dotenv', 'Twig\\' => '/twig/twig/src'] as $prefix => $path) {
    $loader->addPsr4($prefix, $vendor . $path);
}
$loader->register();

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;

function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$tasks = Yaml::parse(file_get_contents(dirname(__DIR__) . '/roles/condition_reports/tasks/main.yml'));
$names = array_column($tasks, 'name');
$index = array_search('Configure external TMS loans', $names, true);
check($index !== false, 'TMS configuration task missing.');
$task = $tasks[$index];
check(array_search('Copy .env for condition reports', $names, true) < $index
    && $index < array_search('Install condition reports tool through composer', $names, true), 'Settings must be applied after the template and before app installation.');
check($task['no_log'] === true && $task['lineinfile']['mode'] === '0640', 'Credential handling changed.');

// Render the common Jinja/Twig subset with equivalent Ansible bool/quote filters.
// This verifies the resulting dotenv values; it is not a real Ansible execution.
$twig = new Environment(new ArrayLoader(), ['strict_variables' => true, 'autoescape' => false]);
$twig->addFilter(new TwigFilter('bool', static function ($value): bool {
    return in_array(is_string($value) ? strtolower($value) : $value, [true, 1, '1', 'true', 'yes', 'on'], true);
}));
$twig->addFilter(new TwigFilter('ternary', static function (bool $value, string $yes, string $no): string {
    return $value ? $yes : $no;
}));
$twig->addFilter(new TwigFilter('quote', static function ($value): string {
    $value = (string) $value;
    if ($value !== '' && !preg_match('/[^a-zA-Z0-9_@%+=:,.\/-]/', $value)) {
        return $value;
    }
    return "'" . str_replace("'", "'\"'\"'", $value) . "'";
}));
$fixture = ['external_loans_enabled' => true,
    'tms_api_url' => 'https://fixture.invalid/loan_projects.php',
    'tms_api_username' => 'fake-user',
    'tms_api_password' => 'FAKE-${LITERAL}-single\'double"-é\\path #suffix'];
foreach ([[], $fixture, array_replace($fixture, ['external_loans_enabled' => false]),
    array_replace($fixture, ['tms_api_password' => 'FAKE-\\n-\\r-${LITERAL}'])] as $settings) {
    $lines = [];
    foreach ($task['loop'] as $item) {
        $item['value'] = $twig->createTemplate($item['value'])->render(['condition_reports' => $settings]);
        $lines[] = $twig->createTemplate($task['lineinfile']['line'])->render(['item' => $item]);
    }
    $env = (new Dotenv())->parse(implode("\n", $lines) . "\n");
    $expected = ['EXTERNAL_LOANS_ENABLED' => ($settings['external_loans_enabled'] ?? false) ? '1' : '0',
        'TMS_API_URL' => $settings['tms_api_url'] ?? '',
        'TMS_API_USERNAME' => $settings['tms_api_username'] ?? '',
        'TMS_API_PASSWORD' => $settings['tms_api_password'] ?? ''];
    check($env === $expected, 'Generated settings did not preserve the synthetic values.');
}
echo "PASS: TMS settings, disabled defaults and password quoting; local fixtures only.\n";
