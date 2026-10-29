<?php

declare(strict_types=1);

// Local source/template checks only. No Ansible, inventory, runtime .env, kernel or clients.
require dirname(__DIR__, 2).'/museumplus-oai-adapter/vendor/autoload.php';

use App\Import\MuseumPlusSearch;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;
use Twig\TwigTest;

$_ENV = $_SERVER = [];
$role = dirname(__DIR__).'/roles/museumplus-oai-adapter';
$tasks = Yaml::parse(file_get_contents($role.'/tasks/main.yml'));
$example = Yaml::parse(file_get_contents($role.'/examples/host-vars-additions.yml'))['museumplus_oai_adapter'];
$publicationIds = $example['museumplus']['webpublication_ids'];
function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function task(string $name): array
{
    global $tasks;
    foreach ($tasks as $task) if ($task['name'] === $name) return $task;
    throw new RuntimeException('Missing task: '.$name);
}

// Compare the template with local production-mode YAML, without booting
// Symfony or resolving any variables from the machine running this test.
function checkRequiredEnvironment(array $configuration, array $env, string $file): void
{
    foreach ($configuration as $key => $value) {
        if (is_string($key) && str_starts_with($key, 'when@') && $key !== 'when@prod') continue;
        if (is_array($value)) {
            checkRequiredEnvironment($value, $env, $file);
        } elseif (is_string($value)) {
            preg_match_all('/%env\(([^)]+)\)%/', $value, $matches);
            foreach ($matches[1] as $expression) {
                if (str_contains($expression, 'default:')) continue;
                $parts = explode(':', $expression);
                $name = end($parts);
                check(array_key_exists($name, $env), 'Missing required variable '.$name.' from '.$file);
            }
        }
    }
}

// Exercise the common Jinja/Twig expression subset with equivalent filters.
// This is not an Ansible syntax-check or a remote execution test.
$twig = new Environment(new ArrayLoader(), ['strict_variables'=>true, 'autoescape'=>false]);
$twig->addFilter(new TwigFilter('string', static fn (mixed $v): string => (string) $v));
$twig->addFilter(new TwigFilter('to_json', static fn (mixed $v, bool $ensure_ascii = true): string => json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | ($ensure_ascii ? 0 : JSON_UNESCAPED_UNICODE))));
$twig->addFilter(new TwigFilter('replace', static fn (string $v, string $from, string $to): string => str_replace($from, $to, $v)));
$twig->addFilter(new TwigFilter('urlencode', static fn (mixed $v): string => str_replace('%2F', '/', rawurlencode((string) $v))));
$twig->addTest(new TwigTest('sequence', static fn (mixed $v): bool => is_array($v) || is_string($v)));
$twig->addTest(new TwigTest('mapping', static fn (mixed $v): bool => is_array($v) && !array_is_list($v)));
$twig->addTest(new TwigTest('string', is_string(...)));
$twig->addTest(new TwigTest('integer', is_int(...)));
$twig->addTest(new TwigTest('match', static fn (string $v, string $pattern): bool => preg_match('~'.$pattern.'~D', $v) === 1));
$config = [
    'app_secret'=>'fake-${UNCHANGED}-"quoted"-é',
    'mysql'=>['username'=>'test/u@ser', 'password'=>'fake/$@:#?% "quote"', 'hostname'=>'database.invalid', 'port'=>3306, 'database'=>'fake-db'],
    'museumplus'=>[
        'export_url'=>'https://museumplus.invalid/export?example=1&next=2',
        'username'=>'test@example.invalid', 'password'=>'fake-${UNCHANGED}-"quoted"-é\\path',
        'timeout_seconds'=>180, 'webpublication_ids'=>['101', 202],
        // Obsolete settings must not be emitted into the deployed environment.
        'search_field_path'=>'ObjCategoryVoc', 'search_operand'=>'999', 'org_units'=>['org_1_Test'],
    ],
    'oai'=>['identifier_prefix'=>'test:', 'default_set_spec'=>'vkc'],
];
$template = $twig->createTemplate(file_get_contents($role.'/templates/.env'));
$rendered = $template->render(['museumplus_oai_adapter'=>$config, 'mariadb_version'=>'12.1.1']);
$env = (new Dotenv())->parse($rendered);
$adapterConfig = dirname(__DIR__, 2).'/museumplus-oai-adapter/config';
foreach ([$adapterConfig.'/services.yaml', ...glob($adapterConfig.'/packages/*.yaml')] as $file) {
    checkRequiredEnvironment(Yaml::parse(file_get_contents($file)), $env, basename($file));
}
check($env['APP_ENV'] === 'prod' && $env['APP_DEBUG'] === '0', 'Wrong runtime mode.');
check($env['APP_SECRET'] === $config['app_secret'] && $env['MUSEUMPLUS_PASSWORD'] === $config['museumplus']['password'], 'Template quoting changed a synthetic secret.');
foreach (['export_url','username','timeout_seconds'] as $key) {
    check($env['MUSEUMPLUS_'.strtoupper($key)] === (string) $config['museumplus'][$key], 'Lost setting '.$key);
}
check($env['MUSEUMPLUS_OAI_IDENTIFIER_PREFIX'] === 'test:' && $env['MUSEUMPLUS_DEFAULT_SET_SPEC'] === 'vkc', 'Lost internal storage settings.');
$dsn = parse_url($env['DATABASE_URL']);
check(rawurldecode($dsn['user']) === $config['mysql']['username'] && rawurldecode($dsn['pass']) === $config['mysql']['password'], 'Database credentials were not URL-encoded correctly.');
check($dsn['host'] === 'database.invalid' && str_contains($dsn['query'], '12.1.1-MariaDB'), 'Wrong database URL.');
check($publicationIds === [], 'Host example must not contain environment-specific publication IDs.');
check(MuseumPlusSearch::parsePublicationIds($env['MUSEUMPLUS_WEBPUBLICATION_IDS']) === ['101', '202'], 'Publication IDs lost during template rendering.');
foreach (['MUSEUMPLUS_ORG_UNITS','MUSEUMPLUS_SEARCH_FIELD_PATH','MUSEUMPLUS_SEARCH_OPERAND'] as $legacy) check(!array_key_exists($legacy, $env), 'Legacy filter still emitted: '.$legacy);
$doc = new DOMDocument();
check($doc->loadXML(MuseumPlusSearch::xml(MuseumPlusSearch::parsePublicationIds($env['MUSEUMPLUS_WEBPUBLICATION_IDS']), 1000, 0), LIBXML_NONET), 'Invalid generated filter.');
$xpath = new DOMXPath($doc);
$xpath->registerNamespace('s', 'http://www.zetcom.com/ria/ws/module/search');
check($xpath->evaluate('count(//s:expert/s:or/s:equalsField[@fieldPath="ObjWebPublicationGrp.WebPublicationVoc"])') === 2.0, 'Publication flags are not OR alternatives.');
check($xpath->evaluate('count(//s:and | //s:equalsField[@fieldPath="__orgUnit" or @fieldPath="ObjCategoryVoc"])') === 0.0, 'Legacy filters still restrict the request.');
// Filter validation is checked below with synthetic host settings.
$listGuards = task('Validate MuseumPlus webpublication filter')['assert']['that'];
$idGuards = task('Validate MuseumPlus webpublication IDs')['assert']['that'];
foreach ([
    [[], false], [['webpublication_ids'=>[]], false], [['webpublication_ids'=>'101,202'], false],
    [['webpublication_ids'=>['named'=>'101']], false], [['webpublication_ids'=>['101',202]], true],
    [['webpublication_ids'=>['0']], false], [['webpublication_ids'=>['Extranet']], false],
    [['webpublication_ids'=>[true]], false], [['webpublication_ids'=>[1.5]], false],
    [['webpublication_ids'=>['101"/><or>']], false], [['webpublication_ids'=>['101']], true],
] as [$settings, $expected]) {
    $allowed = true;
    foreach ($listGuards as $guard) {
        $allowed = $allowed && $twig->createTemplate('{% if '.$guard.' %}yes{% endif %}')->render(['museumplus_oai_adapter'=>['museumplus'=>$settings]]) === 'yes';
    }
    if ($allowed) foreach ($settings['webpublication_ids'] as $item) {
        foreach ($idGuards as $guard) $allowed = $allowed && $twig->createTemplate('{% if '.$guard.' %}yes{% endif %}')->render(['item'=>$item]) === 'yes';
    }
    check($allowed === $expected, 'Publication configuration guard failed for '.json_encode($settings));
}

echo "PASS: settings, synthetic secret quoting, DSN and publication OR filter; invalid lists rejected; legacy filters omitted\n";

$guards = task('Check raw archives can be preserved without overwriting')['assert']['that'];
$root = '/opt/.repos/adapter';
$missing = ['exists'=>false];
$directory = ['exists'=>true, 'isdir'=>true, 'islnk'=>false];
$link = ['exists'=>true, 'isdir'=>false, 'islnk'=>true, 'lnk_source'=>$root.'/shared/museumplus-raw'];
foreach ([
    [$missing, $missing, true], [$directory, $missing, true], [$missing, $directory, true],
    [$link, $directory, true], [$directory, $directory, false], [$missing, $link, false],
    [array_replace($link, ['lnk_source'=>'/unexpected/archive']), $directory, false],
] as [$existing, $shared, $expected]) {
    $allowed = true;
    foreach ($guards as $guard) {
        $allowed = $allowed && $twig->createTemplate('{% if '.$guard.' %}yes{% endif %}')->render([
            'museumplus_raw_existing'=>['stat'=>$existing], 'museumplus_raw_shared'=>['stat'=>$shared],
            'museumplus_oai_adapter'=>['repo_dir'=>$root],
        ]) === 'yes';
    }
    check($allowed === $expected, 'Raw migration guard failed for '.json_encode([$existing, $shared]));
}
check(!in_array('museumplus-raw', task('Delete existing MuseumPlus OAI adapter var folders')['with_items'], true), 'Raw archive entered the deletion loop.');
check(task('Link persistent MuseumPlus raw archive')['file']['force'] === false, 'Raw link could overwrite existing data.');
check(in_array('--no-clobber', task('Preserve existing raw files in the shared archive')['command']['argv'], true), 'Raw move could overwrite a shared archive.');
check(task('Copy .env for MuseumPlus OAI adapter')['template']['src'] === '.env', 'Deployment does not use templates/.env.');
check(task('Copy .env for MuseumPlus OAI adapter')['no_log'] === true && task('Copy .env for MuseumPlus OAI adapter')['diff'] === false, 'Template may expose settings in output.');
check(isset(task('Schedule MuseumPlus import independently of the shared pipeline')['cron']), 'Missing dedicated schedule.');
check(task('Remove the previous MuseumPlus entry from the shared import script')['lineinfile']['state'] === 'absent', 'Old scheduled entry was not retired.');
echo "PASS: raw archive migration/conflict guards, protected template and separate import schedule\n";

// Cached containers from the previous vendor tree must be removed before booting Symfony.
$order = array_flip(array_column($tasks, 'name'));
$install = 'Install MuseumPlus OAI adapter through composer';
$environment = 'Copy .env for MuseumPlus OAI adapter';
$removeCache = 'Remove old MuseumPlus Symfony cache without booting the application';
$createCache = 'Recreate MuseumPlus Symfony cache folder';
$scripts = 'Run MuseumPlus OAI adapter Composer post-install scripts';
$migrations = 'Run MuseumPlus OAI adapter migrations';
check(str_contains(task($install)['command'], '--no-scripts'), 'Composer may boot Symfony with an old cached container.');
check($order[$install] < $order[$environment] && $order[$environment] < $order[$removeCache]
    && $order[$removeCache] < $order[$createCache] && $order[$createCache] < $order[$scripts]
    && $order[$scripts] < $order[$migrations], 'Cache cleanup/configuration must finish before scripts and migrations boot Symfony.');
$cache = '{{ museumplus_oai_adapter.repo_dir }}/shared/cache';
check(task($removeCache)['file'] === ['path'=>$cache, 'state'=>'absent'], 'Cache cleanup must target only the shared cache, without booting the application.');
check(task($createCache)['file'] === [
    'path'=>$cache, 'state'=>'directory', 'mode'=>'0775',
    'owner'=>'{{ application_user }}', 'group'=>'{{ php.group }}',
], 'Shared cache must be writable by the application after cleanup.');
check(task($scripts)['environment'] === ['APP_ENV'=>'prod', 'APP_DEBUG'=>'0'], 'Post-install scripts must rebuild the production cache.');
check(!isset($order['Clear MuseumPlus OAI adapter prod cache']), 'Redundant cache clear remains after the post-install scripts.');
echo "PASS: install without scripts, configure environment, remove/recreate shared cache, then run scripts and migrations\n";
