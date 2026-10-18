<?php

declare(strict_types=1);

// Local source/template checks only. No Ansible, inventory, .env, kernel or clients.
require dirname(__DIR__, 2).'/museumplus-oai-adapter/vendor/autoload.php';

use App\Import\MuseumPlusSearch;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;

$_ENV = $_SERVER = [];
$role = dirname(__DIR__).'/roles/museumplus-oai-adapter';
$tasks = Yaml::parse(file_get_contents($role.'/tasks/main.yml'));
$example = Yaml::parse(file_get_contents($role.'/examples/host-vars-additions.yml'))['museumplus_oai_adapter'];
$units = $example['museumplus']['org_units'];
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

// Exercise the common Jinja/Twig expression subset with equivalent filters.
// This is not an Ansible syntax-check or a remote execution test.
$twig = new Environment(new ArrayLoader(), ['strict_variables'=>true, 'autoescape'=>false]);
$twig->addFilter(new TwigFilter('string', static fn (mixed $v): string => (string) $v));
$twig->addFilter(new TwigFilter('to_json', static fn (mixed $v, bool $ensure_ascii = true): string => json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | ($ensure_ascii ? 0 : JSON_UNESCAPED_UNICODE))));
$twig->addFilter(new TwigFilter('replace', static fn (string $v, string $from, string $to): string => str_replace($from, $to, $v)));
$twig->addFilter(new TwigFilter('urlencode', static fn (mixed $v): string => str_replace('%2F', '/', rawurlencode((string) $v))));
$config = [
    'app_secret'=>'fake-${UNCHANGED}-"quoted"-é',
    'mysql'=>['username'=>'test/u@ser', 'password'=>'fake/$@:#?% "quote"', 'hostname'=>'database.invalid', 'port'=>3306, 'database'=>'fake-db'],
    'museumplus'=>[
        'export_url'=>'https://museumplus.invalid/export?example=1&next=2',
        'username'=>'test@example.invalid', 'password'=>'fake-${UNCHANGED}-"quoted"-é\\path',
        'search_field_path'=>'ObjCategoryVoc', 'search_operand'=>'85713',
        'timeout_seconds'=>180, 'org_units'=>$units,
    ],
    'oai'=>['identifier_prefix'=>'test:', 'default_set_spec'=>'vkc'],
];
$template = $twig->createTemplate(file_get_contents($role.'/templates/museumplus.env.j2'));
$rendered = $template->render(['museumplus_oai_adapter'=>$config, 'mariadb_version'=>'12.1.1']);
$env = (new Dotenv())->parse($rendered);
check($env['APP_ENV'] === 'prod' && $env['APP_DEBUG'] === '0', 'Wrong runtime mode.');
check($env['APP_SECRET'] === $config['app_secret'] && $env['MUSEUMPLUS_PASSWORD'] === $config['museumplus']['password'], 'Template quoting changed a synthetic secret.');
foreach (['export_url','username','search_field_path','search_operand','timeout_seconds'] as $key) {
    check($env['MUSEUMPLUS_'.strtoupper($key)] === (string) $config['museumplus'][$key], 'Lost setting '.$key);
}
check($env['MUSEUMPLUS_OAI_IDENTIFIER_PREFIX'] === 'test:' && $env['MUSEUMPLUS_DEFAULT_SET_SPEC'] === 'vkc', 'Lost internal storage settings.');
$dsn = parse_url($env['DATABASE_URL']);
check(rawurldecode($dsn['user']) === $config['mysql']['username'] && rawurldecode($dsn['pass']) === $config['mysql']['password'], 'Database credentials were not URL-encoded correctly.');
check($dsn['host'] === 'database.invalid' && str_contains($dsn['query'], '12.1.1-MariaDB'), 'Wrong database URL.');
check(count($units) === 30 && count(MuseumPlusSearch::parseOrgUnits($env['MUSEUMPLUS_ORG_UNITS'])) === 30, 'Organisation list incomplete or duplicated.');
$doc = new DOMDocument();
check($doc->loadXML(MuseumPlusSearch::xml($env['MUSEUMPLUS_SEARCH_FIELD_PATH'], $env['MUSEUMPLUS_SEARCH_OPERAND'], 1000, 0, MuseumPlusSearch::parseOrgUnits($env['MUSEUMPLUS_ORG_UNITS'])), LIBXML_NONET), 'Invalid generated filter.');
$xpath = new DOMXPath($doc);
$xpath->registerNamespace('s', 'http://www.zetcom.com/ria/ws/module/search');
check($xpath->evaluate('count(//s:and/s:or/s:equalsField[@fieldPath="__orgUnit"])') === 30.0, 'Organisations are not OR alternatives.');
check($xpath->evaluate('count(//s:and/s:equalsField[@fieldPath="ObjCategoryVoc"])') === 1.0, 'Category filter lost.');
foreach ($units as $unit) check(preg_match('/^org_[0-9]+_[A-Za-z0-9_-]+$/', $unit) === 1, 'Rejected supplied organisation code.');
unset($config['museumplus']['org_units']);
$env = (new Dotenv())->parse($template->render(['museumplus_oai_adapter'=>$config, 'mariadb_version'=>'12.1.1']));
check($env['MUSEUMPLUS_ORG_UNITS'] === '', 'Existing configuration without an organisation list stopped working.');
echo "PASS: settings, synthetic secret quoting, DSN, 30 organisations and category/OR filter; optional empty list\n";

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
check(task('Copy .env for MuseumPlus OAI adapter')['template']['src'] === 'museumplus.env.j2', 'Deployment does not use the new template.');
check(task('Copy .env for MuseumPlus OAI adapter')['no_log'] === true && task('Copy .env for MuseumPlus OAI adapter')['diff'] === false, 'Template may expose settings in output.');
check(isset(task('Schedule MuseumPlus import independently of the shared pipeline')['cron']), 'Missing dedicated schedule.');
check(task('Remove the previous MuseumPlus entry from the shared import script')['lineinfile']['state'] === 'absent', 'Old scheduled entry was not retired.');
echo "PASS: raw archive migration/conflict guards, protected template and separate import schedule\n";
