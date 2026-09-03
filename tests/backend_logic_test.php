<?php

error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});

require_once __DIR__.'/../zfs.master/backend/ZFSMOperations.php';

function assertSameValue($expected, $actual, $message) {
	if ($expected !== $actual) {
		fwrite(STDERR, $message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
		exit(1);
	}
}

assertSameValue(true, zfsmValidPoolName('tank-1'), 'Valid pool name rejected');
assertSameValue(false, zfsmValidPoolName('../tank'), 'Unsafe pool name accepted');
assertSameValue(true, zfsmValidDatasetName('tank/appdata/plex'), 'Valid dataset name rejected');
assertSameValue(false, zfsmValidDatasetName('tank/app data'), 'Dataset containing whitespace accepted');
assertSameValue(true, zfsmValidSnapshotName('tank/appdata@daily-1'), 'Valid snapshot name rejected');
assertSameValue(false, zfsmValidSnapshotName('tank/appdata@bad/name'), 'Invalid snapshot name accepted');

$columns = zfsmDatasetColumns();
$values = array('tank/data', 'filesystem', '1024', '2048', '512', 'off', '-', '/mnt/tank/data', 'lz4', '1.25x', '256', 'none', '131072', 'on', 'sa', 'all', 'off', 'sensitive', 'standard', '1700000000', '-', '-');
$rows = zfsmParseTable(implode("\t", $values), $columns);
assertSameValue(1, count($rows), 'Dataset TSV row was not parsed');
assertSameValue(125, $rows[0]['compressratio'], 'Compression ratio normalization failed');
assertSameValue(0, $rows[0]['quota'], 'Quota normalization failed');
assertSameValue('none', $rows[0]['keystatus'], 'Unavailable keystatus normalization failed');
assertSameValue(false, isset($rows[0]['origin']), 'Inapplicable origin should be omitted');

$datasets = array(
	'tank' => array('name' => 'tank'),
	'tank/a' => array('name' => 'tank/a'),
	'tank/a/b' => array('name' => 'tank/a/b')
);
$children = array('tank' => array('tank/a'), 'tank/a' => array('tank/a/b'));
$tree = zfsmBuildDatasetNode('tank', $datasets, $children);
assertSameValue('tank/a/b', $tree['child']['tank/a']['child']['tank/a/b']['name'], 'Dataset tree construction failed');

assertSameValue(true, zfsmMatchesExclusion('tank/dockerfiles/abc', '/dockerfiles/.*'), 'Dataset exclusion did not match');
assertSameValue(false, zfsmMatchesExclusion('tank/media', '/dockerfiles/.*'), 'Dataset exclusion matched the wrong dataset');
assertSameValue(array(), listDirectories('', array(), ''), 'Empty mountpoint must never be enumerated');
assertSameValue(array(), listDirectories('/', array(), ''), 'Filesystem root must never be enumerated');

$directory_test_root = '/tmp/zfsm-test-mount';
if (!is_dir($directory_test_root)) mkdir($directory_test_root, 0777, true);
$empty_directory = $directory_test_root.'/empty';
if (!is_dir($empty_directory)) mkdir($empty_directory, 0777, true);
assertSameValue(true, !empty(deleteDirectory($empty_directory, 0)['succeeded']), 'Non-force directory removal failed for an empty directory');
assertSameValue(false, file_exists($empty_directory), 'Non-force directory removal did not remove the empty directory');
$nonempty_directory = $directory_test_root.'/nonempty';
if (!is_dir($nonempty_directory)) mkdir($nonempty_directory, 0777, true);
file_put_contents($nonempty_directory.'/data', 'test');
$nonforce_result = deleteDirectory($nonempty_directory, 0);
assertSameValue(true, !empty($nonforce_result['failed']), 'Non-force directory removal must reject a non-empty directory');
assertSameValue(true, is_dir($nonempty_directory), 'Non-force directory removal deleted a non-empty directory');
assertSameValue(true, !empty(deleteDirectory($nonempty_directory, 1)['succeeded']), 'Forced recursive directory removal failed');
assertSameValue(false, file_exists($nonempty_directory), 'Forced recursive directory removal left the directory behind');
$nested_parent = $directory_test_root.'/nested';
$nested_child = $nested_parent.'/child';
mkdir($nested_child, 0777, true);
$nested_conversion = convertDirectory($nested_child, 'tank');
assertSameValue('Only a direct child directory of a dataset mountpoint can be converted', $nested_conversion['failed'][$nested_child] ?? '', 'Nested directory conversion was not rejected safely');
rmdir($nested_child);
rmdir($nested_parent);
assertSameValue(null, zfsmResolveDirectory($directory_test_root, true), 'A dataset mountpoint must never be accepted as a deletable directory');

$tree = zfsmLoadPoolTree('tank', true, '', 1);
assertSameValue('tank', $tree['name'], 'Pool root dataset was not returned');
assertSameValue('tank/data', $tree['child']['tank/data']['name'], 'Child filesystem missing from inventory');
assertSameValue('volume', $tree['child']['tank/vol']['type'], 'ZFS volume type was not retained');
assertSameValue('storage', $tree['org.example:role'], 'Root user property missing');
assertSameValue('media', $tree['child']['tank/data']['org.example:role'], 'Child user property missing');
assertSameValue(2, $tree['total_snapshots'], 'Snapshot total is incorrect');
assertSameValue('tank@root', $tree['snapshots'][0]['name'], 'Root-dataset snapshot missing');
assertSameValue('tank/data@daily', $tree['child']['tank/data']['snapshots'][0]['name'], 'Child snapshot missing');

$pools = getZFSPools();
assertSameValue('ONLINE', $pools['tank']['Health'], 'Pool inventory parsing failed');
assertSameValue("mirror-0\nsda\nsdb", getZFSPoolDevices('tank'), 'Pool device parsing failed');

$snapshot_result = getDatasetSnapshotsResult('tank', 'tank/data');
assertSameValue(true, $snapshot_result['ok'], 'Direct snapshot inventory failed');
assertSameValue('tank/data@daily', $snapshot_result['snapshots'][0]['name'], 'Direct snapshot name is incorrect');

$test_log = getenv('ZFSM_TEST_LOG');
if ($test_log) {
	@unlink($test_log);
	assertSameValue(true, !empty(renameDataset('tank/data', 'tank/data-renamed', 1)['succeeded']), 'Dataset rename failed');
	assertSameValue(true, !empty(setDatasetProperty('tank/data', 'compression', 'zstd')['succeeded']), 'Dataset property update failed');
	assertSameValue(true, !empty(destroyDataset('tank/data', 1)['succeeded']), 'Recursive dataset destroy failed');
	assertSameValue(true, !empty(createDatasetSnapshot('tank/data', 'manual', 1)['succeeded']), 'Recursive snapshot create failed');
	assertSameValue(true, !empty(rollbackDatasetSnapshot('tank/data@daily', 0)['succeeded']), 'Safe snapshot rollback failed');
	assertSameValue(true, !empty(rollbackDatasetSnapshot('tank/data@daily', 1)['succeeded']), 'Snapshot rollback with newer-snapshot removal failed');
	assertSameValue(true, !empty(cloneDatasetSnapshot('tank/data@daily', 'tank/clone')['succeeded']), 'Snapshot clone failed');
	assertSameValue(true, !empty(deleteDatasetSnapshot('tank/data@daily', 0)['succeeded']), 'Snapshot delete failed');
	assertSameValue(true, !empty(createDataset('tank/secure', array('encryption' => 'on', 'keyformat' => 'passphrase', 'keylocation' => 'prompt', 'passphrase' => 'secret123'))['succeeded']), 'Encrypted dataset create failed');
	$commands = file_get_contents($test_log);
	foreach (array('rename -f tank/data tank/data-renamed', 'set compression=zstd tank/data', 'destroy -v -r -f tank/data', 'snapshot -r tank/data@manual', 'rollback tank/data@daily', 'rollback -r tank/data@daily', 'clone tank/data@daily tank/clone', 'destroy tank/data@daily', 'create -p -o encryption=on -o keyformat=passphrase -o keylocation=prompt tank/secure') as $expected_command) {
		assertSameValue(true, strpos($commands, $expected_command) !== false, 'Expected command was not issued: '.$expected_command);
	}
	assertSameValue(false, strpos($commands, 'secret123') !== false, 'Encryption passphrase leaked into command arguments');
}

echo "backend logic tests passed\n";
