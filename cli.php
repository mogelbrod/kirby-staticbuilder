#!/usr/bin/env php
<?php
/*
 * CLI version of plugin
 *
 * Example usage:
 * php site/plugins/staticbuilder/cli.php # build everything
 * php site/plugins/staticbuilder/cli.php home error # build 'home' and 'error' pages
 */

namespace Kirby\Plugin\StaticBuilder;

use F;
use Router;

$ds = DIRECTORY_SEPARATOR;

// Parse options (--option[=value]) and create an array with positional arguments
$opts = [
	'kirby' => getcwd() . $ds . 'kirby',
	'site' => getcwd() . $ds . 'site.php',
	'json' => false,
	'quiet' => false,
	'help' => false,
];
$args = array_filter(array_slice($argv, 1), function($arg) use (&$opts) {
	if (substr($arg, 0, 2) === '--') {
		$parts = explode('=', substr($arg, 2));
		$opt = $parts[0];
		if (!isset($opts[$opt])) {
			echo "Error: unknown option '$opt'\n";
			exit(1);
		}
		$opts[$opt] = isset($parts[1]) ? $parts[1] : true;
		return false;
	}
	return true;
});

$command = array_shift($args);

if ($opts['json']) $opts['quiet'] = true;

// Show usage if not required arguments aren't provided
if (is_null($command) || $opts['help']) {
	echo <<<EOF
usage: {$argv[0]} [options...] <command> [pages...]

Available commands:
	build             Build entire site (or specific pages)
	list              List items that would be built but don't write anything

Options:
	[pages...]        Build the specified pages instead of the entire site
	--kirby=kirby     Directory where bootstrap.php is located
	--site=site.php   Path to kirby site.php config, specify 'false' to disable
	--json            Output data and outcome for each item as JSON
	--quiet           Suppress output
	--help            Display this help text

EOF;
	exit(1);
}

// Ensure dependencies exist
$bootstrapPath = "{$opts['kirby']}{$ds}bootstrap.php";
if (!file_exists($bootstrapPath)) {
	echo "bootstrap.php not found in '{$opts['kirby']}'.\n";
	echo "You can override the default location using --kirby=path/to/kirby-dir\n";
	exit(1);
} else {
	require_once($bootstrapPath);
}
if ($opts['site'] === 'false') {
	// Don't load site.php
} else if (!file_exists($opts['site'])) {
	echo "site.php not found at '{$opts['site']}'.\n";
	echo "You can override the default location using --site=path/to/site.php\n";
	exit(1);
} else {
	require_once($opts['site']);
}

$log = function($msg) use ($opts) {
	if (!$opts['quiet']) {
		echo "* $msg\n";
	}
};

$startTime = microtime(true);

// Bootstrap Kirby
$kirby = kirby();
date_default_timezone_set($kirby->options['timezone']);
$kirby->site();
$kirby->extensions();
$kirby->plugins();
$kirby->models();
$kirby->router = new Router($kirby->routes());

require_once('core/builder.php');
$builder = new Builder();

// Determine targets to build
if (count($args) > 0) {
	$targets = array_map('page', $args);
} else {
	$targets = [site()];
}

// Store results and track stats
$results = [];
if ($command == 'list') {
	$stats = [
		'outdated' => 0,
		'uptodate' => 0,
	];
} else {
	$stats = [
		'generated' => 0,
		'done' => 0,
	];
}

// Register result callback
$builder->itemCallback = function($item) use (&$results, &$stats, &$opts) {
	$results[] = $item;
	if ($item['status'] === '') $item['status'] = 'n/a';
	$stats[$item['status']] = isset($stats[$item['status']]) ? $stats[$item['status']] + 1 : 1;

	if (!$opts['quiet']) {
		$files = isset($item['files']) ? (', ' . count($item['files']) . ' files') : null;
		$size = r(is_int($item['size']), '(' . f::niceSize($item['size']) . "$files)");
		echo "[{$item['status']}] {$item['type']} - {$item['source']} $size\n";
	}
};

// Build each target and combine summaries
foreach ($targets as $target) {
	$builder->run($target, $command == 'build');
}

if (!$opts['quiet']) {
	$line = [];
	foreach ($stats as $state => $count) {
		$line[] = "$state: $count";
	}
	$log("Results: " . join(', ', $line));
}

if ($opts['json']) {
	echo json_encode($results, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
}

$executionTime = microtime(true) - $startTime;
$log("Finished in $executionTime s");

// Exit with error code if not successful
if (isset($stats['missing'])) {
	exit(2);
}