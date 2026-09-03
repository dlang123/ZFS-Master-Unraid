<?php

$plugin = 'zfs.master';
$docroot = $docroot ?? ($_SERVER['DOCUMENT_ROOT'] ?? '/usr/local/emhttp');

require_once $docroot.'/webGui/include/Helpers.php';
require_once $docroot.'/plugins/'.$plugin.'/include/ZFSMBase.php';
require_once $docroot.'/plugins/'.$plugin.'/include/ZFSMError.php';
require_once $docroot.'/plugins/'.$plugin.'/include/ZFSMHelpers.php';
require_once $docroot.'/plugins/'.$plugin.'/backend/ZFSMOperations.php';

$zfsm_cfg = loadConfig(parse_plugin_cfg($plugin, true));
$_POST = is_array($_POST ?? null) ? $_POST : array();
header('Content-Type: application/json; charset=utf-8');

function zfsmPostString($key, $default = '') {
	$value = $_POST[$key] ?? $default;
	return is_string($value) ? $value : $default;
}

function zfsmResolveAnswerCodes($answer) {
	if (!is_array($answer)) return zfsmFailure('request', 'The operation returned no usable result');
	$answer['succeeded'] = is_array($answer['succeeded'] ?? null) ? $answer['succeeded'] : array();
	$answer['failed'] = is_array($answer['failed'] ?? null) ? $answer['failed'] : array();
	foreach ($answer['succeeded'] as $key => $value) $answer['succeeded'][$key] = resolve_error($value);
	foreach ($answer['failed'] as $key => $value) $answer['failed'][$key] = resolve_error($value);
	return $answer;
}

function zfsmReturnAnswer($ret, $title, $success_text, $failed_text, $refresh = false, $unraid_notify = false) {
	$answer = zfsmResolveAnswerCodes($ret);
	if ($refresh && count($answer['succeeded']) > 0) refreshData();
	if ($unraid_notify) {
		if ($answer['succeeded']) zfsnotify($title, $success_text.' for:<br>'.implodeWithKeys('<br>', $answer['succeeded']), '', 'normal');
		if ($answer['failed']) zfsnotify($title, $failed_text.' for:<br>'.implodeWithKeys('<br>', $answer['failed']), '', 'warning');
	}
	echo json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

function zfsmRequireDestructiveMode($config, $subject) {
	return (int)($config['destructive_mode'] ?? 0) === 1 ? null : zfsmFailure($subject, 'Destructive Mode is disabled in ZFS Master settings');
}

try {
	$command = zfsmPostString('cmd');
	switch ($command) {
		case 'refresh':
			refreshData();
			zfsmReturnAnswer(array('succeeded' => array('refresh' => 0), 'failed' => array()), 'Refresh', '', '');
			break;

		case 'createdataset':
			$data = is_array($_POST['data'] ?? null) ? $_POST['data'] : array();
			$pool = is_string($data['zpool'] ?? null) ? $data['zpool'] : '';
			$name = is_string($data['name'] ?? null) ? trim($data['name'], '/') : '';
			$dataset = $pool.($name !== '' ? '/'.$name : '');
			zfsmReturnAnswer(createDataset($dataset, cleanZFSCreateDatasetParams($data)), 'ZFS Dataset Creation', 'Dataset created successfully', 'Unable to create dataset', true);
			break;

		case 'editdatasetproperty':
			zfsmReturnAnswer(setDatasetProperty(zfsmPostString('zdataset'), zfsmPostString('property'), zfsmPostString('value')), 'ZFS Dataset Edit', 'Dataset edited successfully', 'Unable to edit dataset', true);
			break;

		case 'getdatasetproperties':
			echo json_encode(getAllDatasetProperties(zfsmPostString('zdataset'), $zfsm_cfg['znapzend_data'] ?? 0), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
			break;

		case 'adddirectortlisting':
			zfsmReturnAnswer(addToDirectoryListing(zfsmPostString('zdataset')), 'Directory Listing', 'Dataset added successfully', 'Unable to add dataset');
			break;

		case 'removedirectorylisting':
			zfsmReturnAnswer(removeFromDirectoryListing(zfsmPostString('zdataset')), 'Directory Listing', 'Dataset removed successfully', 'Unable to remove dataset');
			break;

		case 'renamedataset':
			zfsmReturnAnswer(renameDataset(zfsmPostString('zdataset'), zfsmPostString('newname'), zfsmPostString('force')), 'ZFS Dataset Rename', 'Dataset renamed successfully', 'Unable to rename dataset', true);
			break;

		case 'destroydataset':
			$dataset = zfsmPostString('zdataset');
			$blocked = zfsmRequireDestructiveMode($zfsm_cfg, $dataset);
			zfsmReturnAnswer($blocked ?? destroyDataset($dataset, zfsmPostString('force')), 'ZFS Dataset Destroy', 'Dataset destroyed successfully', 'Unable to destroy dataset', true);
			break;

		case 'lockdataset':
			zfsmReturnAnswer(lockDataset(zfsmPostString('zdataset')), 'ZFS Dataset Lock', 'Dataset locked successfully', 'Unable to lock dataset', true);
			break;

		case 'unlockdataset':
			zfsmReturnAnswer(unlockDataset(zfsmPostString('zdataset'), zfsmPostString('passphrase')), 'ZFS Dataset Unlock', 'Dataset unlocked successfully', 'Unable to unlock dataset', true);
			break;

		case 'promotedataset':
			zfsmReturnAnswer(promoteDataset(zfsmPostString('zdataset')), 'ZFS Dataset Promote', 'Dataset promoted successfully', 'Unable to promote dataset', true);
			break;

		case 'movedirectory':
			zfsmReturnAnswer(moveDirectory(zfsmPostString('directory'), zfsmPostString('newname')), 'ZFS Directory Move', 'Directory moved successfully', 'Unable to move directory', true);
			break;

		case 'convertdirectory':
			$directory = zfsmPostString('directory');
			$blocked = zfsmRequireDestructiveMode($zfsm_cfg, $directory);
			zfsmReturnAnswer($blocked ?? convertDirectory($directory, zfsmPostString('pool')), 'ZFS Directory Convert', 'Directory converted successfully', 'Unable to convert directory', true, true);
			break;

		case 'deletedirectory':
			$directory = zfsmPostString('directory');
			$blocked = zfsmRequireDestructiveMode($zfsm_cfg, $directory);
			zfsmReturnAnswer($blocked ?? deleteDirectory($directory, zfsmPostString('force')), 'ZFS Directory Delete', 'Directory deleted successfully', 'Unable to delete directory', true);
			break;

		case 'rollbacksnapshot':
			zfsmReturnAnswer(rollbackDatasetSnapshot(zfsmPostString('snapshot'), zfsmPostString('destroy_newer')), 'ZFS Snapshot Rollback', 'Snapshot rolled back successfully', 'Unable to rollback snapshot', true);
			break;

		case 'renamesnapshot':
			$snapshot = zfsmPostString('snapshot');
			$pool = explode('/', explode('@', $snapshot, 2)[0], 2)[0];
			zfsmReturnAnswer(renameDatasetSnapshot($pool, $snapshot, zfsmPostString('newname')), 'ZFS Snapshot Rename', 'Snapshot renamed successfully', 'Unable to rename snapshot', true);
			break;

		case 'holdsnapshot':
			zfsmReturnAnswer(holdDatasetSnapshot(zfsmPostString('snapshot')), 'ZFS Snapshot Reference', 'Snapshot reference added successfully', 'Unable to add reference');
			break;

		case 'releasesnapshot':
			zfsmReturnAnswer(releaseDatasetSnapshot(zfsmPostString('snapshot')), 'ZFS Snapshot Release', 'Snapshot reference removed successfully', 'Unable to remove reference');
			break;

		case 'clonesnapshot':
			zfsmReturnAnswer(cloneDatasetSnapshot(zfsmPostString('snapshot'), zfsmPostString('clone')), 'ZFS Snapshot Clone', 'Snapshot cloned successfully', 'Unable to clone snapshot', true);
			break;

		case 'destroysnapshot':
			zfsmReturnAnswer(deleteDatasetSnapshot(zfsmPostString('snapshot'), zfsmPostString('recursive')), 'ZFS Snapshot Destroy', 'Snapshot destroyed successfully', 'Unable to destroy snapshot', true);
			break;

		case 'snapshotdataset':
			$snapshot = ($zfsm_cfg['snap_prefix'] ?? '').date($zfsm_cfg['snap_pattern'] ?? 'Y-m-d-His');
			zfsmReturnAnswer(createDatasetSnapshot(zfsmPostString('zdataset'), $snapshot, zfsmPostString('recursive')), 'ZFS Snapshot Create', 'Snapshot created successfully', 'Unable to create snapshot', true);
			break;

		default:
			zfsmReturnAnswer(zfsmFailure('request', 'Unknown or missing command'), 'Request', '', '');
	}
} catch (Throwable $error) {
	error_log('ZFS Master backend exception: '.$error->getMessage());
	zfsmReturnAnswer(zfsmFailure('request', $error->getMessage()), 'Request', '', '');
}

?>
