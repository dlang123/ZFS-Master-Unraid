<?php
error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$docroot = getenv('ZFSM_TEST_DOCROOT');
if (!$docroot) throw new RuntimeException('Set ZFSM_TEST_DOCROOT to the isolated fixture document root');
$mode = $argv[1] ?? 'full';
putenv('ZFSM_TEST_LAZY='.($mode === 'lazy' ? '1' : '0'));
if ($mode === 'pool_failure') putenv('ZFSM_TEST_POOL_FAIL=1');
if ($mode === 'snapshot_failure') putenv('ZFSM_TEST_SNAPSHOT_FAIL=1');
$_POST = array('cmd' => 'inventory');
if ($mode === 'pool') $_POST = array('cmd' => 'inventorysnapshots', 'pool' => 'tank');
if ($mode === 'invalid') $_POST = array('cmd' => 'inventorysnapshots', 'pool' => '../tank');
if ($mode === 'missing') $_POST = array('cmd' => 'inventorysnapshots');
if ($mode === 'gone') $_POST = array('cmd' => 'inventorysnapshots', 'pool' => 'missing');
if ($mode === 'refresh_paused') $_POST = array('cmd' => 'refresh');

// The runner mounts an isolated /tmp; publish() throws if inventory calls it.
require $docroot.'/webGui/include/publish.php';
ob_start();
require $docroot.'/plugins/zfs.master/backend/ZFSMAdmin.php';
$output = ob_get_clean();
$answer = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
if (in_array($mode, array('invalid', 'missing', 'gone', 'pool_failure', 'refresh_paused'), true)) {
    check(!empty($answer['failed']), 'Expected a reported failure: '.$output);
    check(empty($answer['succeeded']), 'Failed request reported success');
} else {
    check($answer['publishing_paused'] === true, 'Test must run with publishing paused');
    check(isset($answer['config']), 'Missing live configuration');
    check(count($answer['data']['pools']) === 1, 'Wrong pool count');
    $tree = $answer['data']['datasets']['tank'];
    check(isset($tree['child']['tank/data'], $tree['child']['tank/vol']), 'Dataset hierarchy missing');
    if ($mode === 'lazy') {
        check($answer['op'] === 'getDatasets', 'Expected dataset-first response');
        check($answer['data']['pools']['tank']['Snapshots'] === null, 'Pending snapshots must not be reported as zero');
    } elseif ($mode === 'snapshot_failure') {
        check(!empty($answer['data']['errors']['tank']), 'Snapshot failure was hidden');
    } else {
        check($answer['op'] === 'getAll', 'Expected complete response');
        check($tree['snapshots'][0]['name'] === 'tank@root', 'Root snapshot missing');
        check($tree['child']['tank/data']['snapshots'][0]['name'] === 'tank/data@daily', 'Child snapshot missing');
        check($answer['data']['pools']['tank']['Snapshots'] === 2, 'Snapshot total incorrect');
    }
}
check(is_file('/tmp/publishPaused'), 'Endpoint removed the global pause flag');
check(!isset($GLOBALS['zfsm_inventory_deadline']), 'Inventory deadline leaked into subsequent operations');
echo $mode." inventory route passed\n";
