<?php

define('__ROOT__', dirname(dirname(__FILE__)));

require_once __ROOT__."/include/ZFSMBase.php";
require_once __ROOT__."/include/ZFSMError.php";
require_once __ROOT__."/include/ZFSMHelpers.php";
$unraid_helpers = '/usr/local/emhttp/webGui/include/Helpers.php';
$unraid_publish = '/usr/local/emhttp/webGui/include/publish.php';
if (is_file($unraid_helpers)) require_once $unraid_helpers;
if (is_file($unraid_publish)) require_once $unraid_publish;

function refreshData() {
	touch('/tmp/zfsm_reload');
}

function buildArrayRet() {
	return array('succeeded' => array(), 'failed' => array());
}

function zfsmFailure($subject, $message) {
	$ret = buildArrayRet();
	$ret['failed'][$subject] = $message;
	return $ret;
}

function zfsmValidPoolName($name) {
	return is_string($name) && strlen($name) > 0 && strlen($name) <= 255
		&& preg_match('/^[A-Za-z][A-Za-z0-9_.:-]*$/D', $name) === 1;
}

function zfsmValidDatasetName($name) {
	if (!is_string($name) || strlen($name) === 0 || strlen($name) > 1024 || strpos($name, '@') !== false || strpos($name, '#') !== false) return false;
	$parts = explode('/', $name);
	foreach ($parts as $part) {
		if ($part === '' || $part === '.' || $part === '..' || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:%-]*$/D', $part) !== 1) return false;
	}
	return zfsmValidPoolName($parts[0]);
}

function zfsmValidSnapshotName($name) {
	if (!is_string($name) || substr_count($name, '@') !== 1) return false;
	list($dataset, $snapshot) = explode('@', $name, 2);
	return zfsmValidDatasetName($dataset) && $snapshot !== '' && strlen($snapshot) <= 255
		&& preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:%-]*$/D', $snapshot) === 1;
}

function zfsmDatasetExists($name, $types = 'filesystem,volume') {
	if (!zfsmValidDatasetName($name)) return false;
	$result = runProcess('zfs', array('list', '-H', '-o', 'name', '-t', $types, $name));
	return ($result['code'] ?? 1) === 0 && trim($result['output'] ?? '') === $name;
}

function zfsmSnapshotExists($name) {
	if (!zfsmValidSnapshotName($name)) return false;
	$result = runProcess('zfs', array('list', '-H', '-o', 'name', '-t', 'snapshot', $name));
	return ($result['code'] ?? 1) === 0 && trim($result['output'] ?? '') === $name;
}

function zfsmDatasetColumns() {
	return array('name', 'type', 'used', 'available', 'referenced', 'encryption', 'keystatus', 'mountpoint', 'compression', 'compressratio', 'usedbysnapshots', 'quota', 'recordsize', 'atime', 'xattr', 'primarycache', 'readonly', 'casesensitivity', 'sync', 'creation', 'origin', 'volblocksize');
}

function zfsmSnapshotColumns() {
	return array('name', 'used', 'referenced', 'written', 'defer_destroy', 'userrefs', 'creation');
}

function zfsmNormalizeProperty($property, $value) {
	if ($value === '-') {
		if ($property === 'keystatus' || $property === 'mountpoint') return 'none';
		return null;
	}
	if ($property === 'quota' && $value === 'none') return 0;
	if ($property === 'compressratio') {
		if (preg_match('/^([0-9.]+)x$/', $value, $match)) return (int)round(((float)$match[1]) * 100);
		return is_numeric($value) ? (int)$value : 0;
	}
	$numeric = array('used', 'available', 'referenced', 'usedbysnapshots', 'quota', 'recordsize', 'creation', 'volblocksize', 'written', 'userrefs');
	return in_array($property, $numeric, true) && is_numeric($value) ? (int)$value : $value;
}

function zfsmParseTable($output, $columns) {
	$rows = array();
	foreach (preg_split('/\r\n|\r|\n/', trim((string)$output), -1, PREG_SPLIT_NO_EMPTY) ?: array() as $line) {
		$parts = explode("\t", $line);
		if (count($parts) !== count($columns)) continue;
		$row = array();
		foreach ($columns as $index => $property) {
			$value = zfsmNormalizeProperty($property, $parts[$index]);
			if ($value !== null) $row[$property] = $value;
		}
		$rows[] = $row;
	}
	return $rows;
}

function zfsmMatchesExclusion($name, $pattern) {
	if (!is_string($pattern) || trim($pattern) === '') return false;
	$regex = '#'.str_replace('#', '\\#', $pattern).'#i';
	$result = @preg_match($regex, $name);
	if ($result === false) {
		error_log('ZFS Master ignored invalid dataset exclusion pattern: '.$pattern);
		return false;
	}
	return $result === 1;
}

function zfsmLoadUserProperties($dataset_root, &$datasets) {
	$result = runProcess('zfs', array('get', '-H', '-p', '-r', '-t', 'filesystem,volume', '-o', 'name,property,value,source', 'all', $dataset_root));
	if (($result['code'] ?? 1) !== 0) return;
	foreach (preg_split('/\r\n|\r|\n/', trim($result['output'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: array() as $line) {
		$parts = explode("\t", $line, 4);
		if (count($parts) < 3 || strpos($parts[1], ':') === false || !isset($datasets[$parts[0]])) continue;
		$datasets[$parts[0]][$parts[1]] = $parts[2];
	}
}

function zfsmBuildDatasetNode($name, $datasets, $children) {
	$node = $datasets[$name];
	$node['child'] = array();
	foreach ($children[$name] ?? array() as $child_name) $node['child'][$child_name] = zfsmBuildDatasetNode($child_name, $datasets, $children);
	ksort($node['child']);
	return $node;
}

function zfsmEmptyDatasetTree($pool, $error = '') {
	$tree = array('name' => $pool, 'type' => 'filesystem', 'mountpoint' => 'none', 'child' => array(), 'directories' => array(), 'snapshots' => array(), 'total_snapshots' => 0);
	if ($error !== '') $tree['_error'] = $error;
	return $tree;
}

function zfsmLoadPoolTree($pool, $include_snapshots, $exclusion_pattern, $extended) {
	if (!zfsmValidPoolName($pool)) return zfsmEmptyDatasetTree($pool, 'Invalid pool name');
	$columns = zfsmDatasetColumns();
	$result = runProcess('zfs', array('list', '-H', '-p', '-r', '-t', 'filesystem,volume', '-o', implode(',', $columns), $pool));
	if (($result['code'] ?? 1) !== 0) {
		$error = trim($result['output'] ?? 'Unable to list datasets');
		error_log('ZFS Master dataset inventory failed for '.$pool.': '.$error);
		return zfsmEmptyDatasetTree($pool, $error);
	}

	$datasets = array();
	$excluded_roots = array();
	foreach (zfsmParseTable($result['output'], $columns) as $row) {
		$name = $row['name'] ?? '';
		$excluded = zfsmMatchesExclusion($name, $exclusion_pattern);
		foreach ($excluded_roots as $root) if ($name === $root || str_starts_with($name, $root.'/')) $excluded = true;
		if ($excluded) {
			$excluded_roots[] = $name;
			continue;
		}
		$row['child'] = array();
		$row['directories'] = array();
		$row['snapshots'] = array();
		$datasets[$name] = $row;
	}

	if (!isset($datasets[$pool])) return zfsmEmptyDatasetTree($pool, 'Pool root dataset was not returned by zfs list');
	if ((int)$extended === 1) zfsmLoadUserProperties($pool, $datasets);

	$total_snapshots = 0;
	if ($include_snapshots) {
		$snapshot_columns = zfsmSnapshotColumns();
		$snap_result = runProcess('zfs', array('list', '-H', '-p', '-r', '-t', 'snapshot', '-o', implode(',', $snapshot_columns), $pool));
		if (($snap_result['code'] ?? 1) !== 0) {
			$error = trim($snap_result['output'] ?? 'Unable to list snapshots');
			error_log('ZFS Master snapshot inventory failed for '.$pool.': '.$error);
			$datasets[$pool]['_snapshot_error'] = $error;
		} else {
			foreach (zfsmParseTable($snap_result['output'], $snapshot_columns) as $snapshot) {
				$dataset_name = explode('@', $snapshot['name'] ?? '', 2)[0];
				if (!isset($datasets[$dataset_name])) continue;
				$datasets[$dataset_name]['snapshots'][] = $snapshot;
				$total_snapshots++;
			}
		}
	}

	$children = array();
	foreach (array_keys($datasets) as $name) {
		if ($name === $pool || strpos($name, '/') === false) continue;
		$parent = substr($name, 0, strrpos($name, '/'));
		if (isset($datasets[$parent])) $children[$parent][] = $name;
	}
	$tree = zfsmBuildDatasetNode($pool, $datasets, $children);
	$tree['total_snapshots'] = $total_snapshots;
	return sortDatasetArray($tree);
}

function listDirectories($path, $childs, $zexc_pattern) {
	if (!is_string($path) || $path === '' || $path === 'none' || $path === 'legacy' || $path[0] !== '/' || !is_dir($path)) return array();
	$real_path = realpath($path);
	if ($real_path === false || $real_path === '/') return array();
	$remove = array($real_path.'/..', $real_path.'/.');
	foreach (is_array($childs) ? $childs : array() as $child) if (isset($child['mountpoint']) && is_string($child['mountpoint'])) $remove[] = rtrim($child['mountpoint'], '/');
	$dirs = glob(rtrim($real_path, '/').'/{,.}*', GLOB_ONLYDIR | GLOB_BRACE);
	if (!is_array($dirs)) return array();
	$dirs = array_values(array_unique(array_diff($dirs, $remove)));
	if ($zexc_pattern !== '') $dirs = array_values(array_filter($dirs, function($dir) use ($zexc_pattern) { return !zfsmMatchesExclusion($dir, $zexc_pattern); }));
	sort($dirs, SORT_NATURAL | SORT_FLAG_CASE);
	return $dirs;
}

function saveConfig($array) {
	$content = '';
	foreach ($array as $key => $elem) {
		if (!is_array($elem)) continue;
		$content .= '['.$key."]\n";
		foreach ($elem as $key2 => $elem2) {
			$values = is_array($elem2) ? $elem2 : array($elem2);
			foreach ($values as $value) {
				$value = str_replace(array('\\', '"', "\r", "\n"), array('\\\\', '\\"', '', ''), (string)$value);
				$content .= $key2.(is_array($elem2) ? '[]' : '').' = "'.$value."\"\n";
			}
		}
	}
	return file_put_contents('/boot/config/plugins/zfs.master/zfs.master.cfg', $content, LOCK_EX) !== false;
}

function addToDirectoryListing($zdataset) {
	if (!zfsmDatasetExists($zdataset, 'filesystem')) return zfsmFailure($zdataset, 'Dataset does not exist or is not a filesystem');
	$config = parse_plugin_cfg('zfs.master', true);
	$current = preg_split('/\r\n|\r|\n/', $config['general']['directory_listing'] ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: array();
	if (in_array($zdataset, $current, true)) return zfsmFailure($zdataset, ZFSM_ERR_ALREADY_SET_IN_CONFIG);
	$current[] = $zdataset;
	$config['general']['directory_listing'] = implode(PHP_EOL, array_values(array_unique($current)));
	$saved = saveConfig($config);
	$ret = buildArrayRet();
	$ret[$saved ? 'succeeded' : 'failed'][$zdataset] = $saved ? 0 : ZFSM_ERR_UNABLE_TO_SAVE;
	return $ret;
}

function removeFromDirectoryListing($zdataset) {
	$config = parse_plugin_cfg('zfs.master', true);
	$current = preg_split('/\r\n|\r|\n/', $config['general']['directory_listing'] ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: array();
	$key = array_search($zdataset, $current, true);
	if ($key === false) return zfsmFailure($zdataset, ZFSM_ERR_NOT_IN_CONFIG);
	unset($current[$key]);
	$config['general']['directory_listing'] = implode(PHP_EOL, $current);
	$saved = saveConfig($config);
	$ret = buildArrayRet();
	$ret[$saved ? 'succeeded' : 'failed'][$zdataset] = $saved ? 0 : ZFSM_ERR_UNABLE_TO_SAVE;
	return $ret;
}

function getZFSPools() {
	$ret_pools = array();
	$result = runProcess('zpool', array('list', '-H', '-o', 'name,size,alloc,free,health'));
	if (($result['code'] ?? 1) !== 0) {
		if (stripos($result['output'] ?? '', 'no pools available') === false) error_log('ZFS Master pool inventory failed: '.($result['output'] ?? 'unknown error'));
		return $ret_pools;
	}
	foreach (preg_split('/\r\n|\r|\n/', trim($result['output'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: array() as $line) {
		$parts = explode("\t", $line);
		if (count($parts) < 5 || !zfsmValidPoolName($parts[0])) continue;
		$ret_pools[$parts[0]] = array('Pool' => $parts[0], 'Health' => $parts[4], 'Name' => '', 'Size' => $parts[1], 'MountPoint' => '', 'Refer' => '', 'Used' => $parts[2], 'Free' => $parts[3], 'Snapshots' => 0, 'Origin' => '');
	}
	return $ret_pools;
}

function getZFSPoolDevices($zpool) {
	if (!zfsmValidPoolName($zpool)) return '';
	$result = runProcess('zpool', array('status', '-v', $zpool));
	if (($result['code'] ?? 1) !== 0) return '';
	$devices = array();
	$in_config = false;
	$skipped_pool = false;
	foreach (preg_split('/\r\n|\r|\n/', $result['output'] ?? '') ?: array() as $line) {
		if (trim($line) === 'config:') { $in_config = true; continue; }
		if (!$in_config) continue;
		if (str_starts_with(trim($line), 'errors:')) break;
		$parts = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY);
		if (!$parts || $parts[0] === 'NAME' || count($parts) < 2) continue;
		if (!$skipped_pool && $parts[0] === $zpool) { $skipped_pool = true; continue; }
		$devices[] = $parts[0];
	}
	return implode("\n", $devices);
}

function getZFSPoolDatasets($zpool, $zexc_pattern, $ext, $directory_listing = array()) {
	$result = zfsmLoadPoolTree($zpool, false, $zexc_pattern, $ext);
	$result['directories'] = listDirectories($result['mountpoint'] ?? '', $result['child'] ?? array(), $zexc_pattern);
	if ($directory_listing) $result['child'] = getDatasetDirectories($result['child'] ?? array(), $directory_listing, $zexc_pattern, $ext);
	return $result;
}

function getZFSPoolDatasetsAndSnapshots($zpool, $zexc_pattern, $ext, $directory_listing = array()) {
	$result = zfsmLoadPoolTree($zpool, true, $zexc_pattern, $ext);
	$result['directories'] = listDirectories($result['mountpoint'] ?? '', $result['child'] ?? array(), $zexc_pattern);
	if ($directory_listing) $result['child'] = getDatasetDirectories($result['child'] ?? array(), $directory_listing, $zexc_pattern, $ext);
	return $result;
}

function getDatasetDirectories($dataset_tree, $directory_listing, $zexc_pattern, $ext) {
	foreach ($dataset_tree as &$dataset) {
		if (in_array($dataset['name'] ?? '', $directory_listing, true)) $dataset['directories'] = listDirectories($dataset['mountpoint'] ?? '', $dataset['child'] ?? array(), $zexc_pattern);
		if (!empty($dataset['child'])) $dataset['child'] = getDatasetDirectories($dataset['child'], $directory_listing, $zexc_pattern, $ext);
	}
	return $dataset_tree;
}

function getDatasetProperty($zpool, $zdataset, $zproperty) {
	if (!zfsmValidDatasetName($zdataset) || !preg_match('/^[A-Za-z][A-Za-z0-9_.:-]*$/D', $zproperty)) return null;
	$result = runProcess('zfs', array('get', '-H', '-p', '-o', 'value', $zproperty, $zdataset));
	return ($result['code'] ?? 1) === 0 ? zfsmNormalizeProperty($zproperty, trim($result['output'] ?? '')) : null;
}

function getAllDatasetProperties($zdataset, $ext) {
	if (!zfsmDatasetExists($zdataset)) return array('_error' => 'Dataset does not exist');
	$columns = zfsmDatasetColumns();
	$result = runProcess('zfs', array('list', '-H', '-p', '-o', implode(',', $columns), $zdataset));
	if (($result['code'] ?? 1) !== 0) return array('_error' => trim($result['output'] ?? 'Unable to read dataset properties'));
	$rows = zfsmParseTable($result['output'], $columns);
	$properties = $rows[0] ?? array();
	if (!$properties) return array('_error' => 'ZFS returned no dataset properties');
	if ((int)$ext === 1) {
		$map = array($zdataset => $properties);
		zfsmLoadUserProperties($zdataset, $map);
		$properties = $map[$zdataset];
	}
	return $properties;
}

function getDatasetSnapshotsResult($zpool, $zdataset) {
	if (!zfsmValidPoolName($zpool) || !zfsmValidDatasetName($zdataset) || explode('/', $zdataset, 2)[0] !== $zpool) return array('ok' => false, 'snapshots' => array(), 'error' => 'Invalid pool or dataset name');
	$columns = zfsmSnapshotColumns();
	$result = runProcess('zfs', array('list', '-H', '-p', '-d', '1', '-t', 'snapshot', '-o', implode(',', $columns), $zdataset));
	if (($result['code'] ?? 1) !== 0) return array('ok' => false, 'snapshots' => array(), 'error' => trim($result['output'] ?? 'Unable to list snapshots'));
	$snapshots = zfsmParseTable($result['output'], $columns);
	usort($snapshots, function($a, $b) { return ($a['creation'] ?? 0) <=> ($b['creation'] ?? 0); });
	return array('ok' => true, 'snapshots' => $snapshots, 'error' => '');
}

function getDatasetSnapshots($zpool, $zdataset) {
	return getDatasetSnapshotsResult($zpool, $zdataset)['snapshots'];
}

function zfsmAllowedDatasetProperties() {
	return array('mountpoint', 'compression', 'quota', 'recordsize', 'atime', 'xattr', 'primarycache', 'readonly', 'casesensitivity', 'sync');
}

function zfsmSafePropertyValue($property, $value) {
	if (!is_string($value) || strlen($value) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $value)) return false;
	if ($property === 'mountpoint') return in_array($value, array('none', 'legacy'), true) || ($value !== '' && $value[0] === '/');
	return $value !== '';
}

function createDataset($zdataset, $zoptions) {
	if (!zfsmValidDatasetName($zdataset)) return zfsmFailure($zdataset, 'Invalid dataset name');
	$pool = explode('/', $zdataset, 2)[0];
	$passphrase = (string)($zoptions['passphrase'] ?? '');
	unset($zoptions['passphrase']);
	$allowed = array('mountpoint', 'compression', 'quota', 'recordsize', 'atime', 'xattr', 'primarycache', 'readonly', 'casesensitivity', 'sync', 'encryption', 'keylocation', 'keyformat');
	$args = array('create', '-p');
	foreach ($zoptions as $property => $value) {
		if (!in_array($property, $allowed, true) || !zfsmSafePropertyValue($property, (string)$value)) return zfsmFailure($zdataset, 'Invalid dataset property: '.$property);
		$args[] = '-o';
		$args[] = $property.'='.$value;
	}
	$encrypted = ($zoptions['encryption'] ?? 'off') === 'on';
	if ($encrypted && strlen($passphrase) < 8) return zfsmFailure($zdataset, 'Encryption passphrase must be at least 8 characters');
	$args[] = $zdataset;
	$result = runProcess('zfs', $args, $encrypted ? $passphrase."\n".$passphrase."\n" : null);
	$answer = commandAnswer($zdataset, $result);
	if (($result['code'] ?? 1) === 0) {
		$mountpoint = getDatasetProperty($pool, $zdataset, 'mountpoint');
		if (is_string($mountpoint) && $mountpoint !== '' && $mountpoint[0] === '/' && is_dir($mountpoint)) {
			@chown($mountpoint, 'nobody');
			@chgrp($mountpoint, 'users');
		}
	}
	return $answer;
}

function renameDataset($zdataset, $new_name, $force) {
	if (!zfsmDatasetExists($zdataset) || !zfsmValidDatasetName($new_name)) return zfsmFailure($new_name, 'Invalid source or destination dataset');
	$args = array('rename');
	if ((string)$force === '1') $args[] = '-f';
	$args[] = $zdataset;
	$args[] = $new_name;
	return commandAnswer($new_name, runProcess('zfs', $args));
}

function setDatasetProperty($zdataset, $property, $value) {
	if (!zfsmDatasetExists($zdataset) || !in_array($property, zfsmAllowedDatasetProperties(), true) || !zfsmSafePropertyValue($property, (string)$value)) return zfsmFailure($property, 'Invalid dataset, property, or value');
	$result = $value === 'inherit' ? runProcess('zfs', array('inherit', $property, $zdataset)) : runProcess('zfs', array('set', $property.'='.$value, $zdataset));
	return commandAnswer($property, $result);
}

function lockDataset($zdataset) {
	if (!zfsmDatasetExists($zdataset, 'filesystem')) return zfsmFailure($zdataset, 'Dataset does not exist');
	$unmount = runProcess('zfs', array('unmount', '-f', $zdataset));
	if (($unmount['code'] ?? 1) !== 0) return commandAnswer($zdataset, $unmount);
	return commandAnswer($zdataset, runProcess('zfs', array('unload-key', '-r', $zdataset)));
}

function unlockDataset($zdataset, $passphrase) {
	if (!zfsmDatasetExists($zdataset, 'filesystem') || !is_string($passphrase) || $passphrase === '') return zfsmFailure($zdataset, 'Invalid dataset or empty passphrase');
	$load = runProcess('zfs', array('load-key', $zdataset), $passphrase."\n");
	if (($load['code'] ?? 1) !== 0) return commandAnswer($zdataset, $load);
	return commandAnswer($zdataset, runProcess('zfs', array('mount', $zdataset)));
}

function promoteDataset($zdataset, $zforce = 0) {
	if (!zfsmDatasetExists($zdataset)) return zfsmFailure($zdataset, 'Dataset does not exist');
	return commandAnswer($zdataset, runProcess('zfs', array('promote', $zdataset)));
}

function destroyDataset($zdataset, $force) {
	if (!zfsmDatasetExists($zdataset)) return zfsmFailure($zdataset, 'Dataset does not exist');
	$args = array('destroy', '-v');
	if ((string)$force === '1') array_push($args, '-r', '-f');
	$args[] = $zdataset;
	return commandAnswer($zdataset, runProcess('zfs', $args));
}

function zfsmFilesystemMounts() {
	$result = runProcess('zfs', array('list', '-H', '-o', 'name,mountpoint', '-t', 'filesystem'));
	$mounts = array();
	if (($result['code'] ?? 1) !== 0) return $mounts;
	foreach (preg_split('/\r\n|\r|\n/', trim($result['output'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: array() as $line) {
		$parts = explode("\t", $line, 2);
		if (count($parts) !== 2 || $parts[1] === 'none' || $parts[1] === 'legacy' || $parts[1] === '/' || !is_dir($parts[1])) continue;
		$real = realpath($parts[1]);
		if ($real !== false) $mounts[$parts[0]] = rtrim($real, '/');
	}
	uksort($mounts, function($a, $b) use ($mounts) { return strlen($mounts[$b]) <=> strlen($mounts[$a]); });
	return $mounts;
}

function zfsmResolveDirectory($path, $must_exist = true) {
	if (!is_string($path) || $path === '' || $path[0] !== '/' || preg_match('/[\x00-\x1F\x7F]/', $path)) return null;
	$resolved = $must_exist ? realpath($path) : realpath(dirname($path));
	if ($resolved === false) return null;
	if (!$must_exist) $resolved = rtrim($resolved, '/').'/'.basename($path);
	foreach (zfsmFilesystemMounts() as $dataset => $mountpoint) {
		if ($resolved !== $mountpoint && str_starts_with($resolved, $mountpoint.'/')) return array('path' => $resolved, 'dataset' => $dataset, 'mountpoint' => $mountpoint);
	}
	return null;
}

function moveDirectory($directory, $new_name) {
	$source = zfsmResolveDirectory($directory, true);
	$destination = zfsmResolveDirectory($new_name, false);
	if (!$source || !$destination || $source['dataset'] !== $destination['dataset'] || file_exists($destination['path'])) return zfsmFailure($new_name, 'Directory move is outside its dataset or destination exists');
	if (@rename($source['path'], $destination['path'])) {
		$ret = buildArrayRet();
		$ret['succeeded'][$destination['path']] = 0;
		return $ret;
	}
	$error = error_get_last();
	return zfsmFailure($new_name, $error['message'] ?? 'Unable to move directory');
}

function deleteDirectory($directory, $force) {
	$resolved = zfsmResolveDirectory($directory, true);
	if (!$resolved) return zfsmFailure($directory, 'Directory is outside a mounted ZFS filesystem');
	if ((string)$force === '1') return commandAnswer($resolved['path'], runProcess('rm', array('-rf', '--', $resolved['path'])));
	return commandAnswer($resolved['path'], runProcess('rmdir', array('--', $resolved['path'])));
}

function convertDirectory($directory, $pool) {
	if (!zfsmValidPoolName($pool)) return zfsmFailure($directory, 'Invalid pool name');
	$source = zfsmResolveDirectory($directory, true);
	if (!$source || explode('/', $source['dataset'], 2)[0] !== $pool) return zfsmFailure($directory, 'Directory is not inside the selected pool');
	if (dirname($source['path']) !== $source['mountpoint']) return zfsmFailure($directory, 'Only a direct child directory of a dataset mountpoint can be converted');
	$relative = ltrim(substr($source['path'], strlen($source['mountpoint'])), '/');
	$dataset_name = $source['dataset'].($relative !== '' ? '/'.$relative : '');
	if (!zfsmValidDatasetName($dataset_name) || zfsmDatasetExists($dataset_name)) return zfsmFailure($directory, 'Target dataset name is invalid or already exists');
	$temp = $source['path'].'_zfsm_tmp_'.date('YmdHis');
	$moved = moveDirectory($source['path'], $temp);
	if (!$moved['succeeded']) return $moved;
	$created = createDataset($dataset_name, array());
	if (!$created['succeeded']) {
		moveDirectory($temp, $source['path']);
		return $created;
	}
	$mountpoint = getDatasetProperty($pool, $dataset_name, 'mountpoint');
	if (!is_string($mountpoint) || !is_dir($mountpoint)) {
		$cleanup = runProcess('zfs', array('destroy', $dataset_name));
		if (($cleanup['code'] ?? 1) === 0 && moveDirectory($temp, $source['path'])['succeeded']) return zfsmFailure($directory, 'Dataset mountpoint was unavailable; the new dataset was removed and the original directory was restored');
		return zfsmFailure($directory, 'Dataset mountpoint is unavailable; original data remains at '.$temp.' and manual recovery is required');
	}
	$spec = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('redirect', 1));
	$pipes = array();
	$process = @proc_open(array('rsync', '-aX', '--stats', '--info=progress2', rtrim($temp, '/').'/', rtrim($mountpoint, '/').'/'), $spec, $pipes, null, null, array('bypass_shell' => true));
	if (!is_resource($process)) return zfsmFailure($directory, 'Unable to start rsync; original data remains at '.$temp);
	fclose($pipes[0]);
	stream_set_blocking($pipes[1], false);
	publish('zfs_master', json_encode(array('op' => 'start_directory_copy', 'data' => array('dataset' => $dataset_name))));
	do {
		$output = stream_get_contents($pipes[1]);
		if ($output !== '') publish('zfs_master', json_encode(array('op' => 'directory_copy', 'data' => array('line' => $output, 'dataset' => $dataset_name))));
		$status = proc_get_status($process);
		if ($status['running']) usleep(100000);
	} while ($status['running']);
	$remaining = stream_get_contents($pipes[1]);
	if ($remaining !== '') publish('zfs_master', json_encode(array('op' => 'directory_copy', 'data' => array('line' => $remaining, 'dataset' => $dataset_name))));
	fclose($pipes[1]);
	$closed_code = proc_close($process);
	$exit_code = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed_code;
	publish('zfs_master', json_encode(array('op' => 'stop_directory_copy', 'data' => array('dataset' => $dataset_name))));
	if ($exit_code !== 0) return zfsmFailure($directory, 'rsync failed with exit code '.$exit_code.'; original data remains at '.$temp.' and the partial dataset was retained for inspection');
	$deleted = deleteDirectory($temp, 1);
	if (!$deleted['succeeded']) return zfsmFailure($directory, 'Copy completed, but temporary source could not be removed: '.$temp);
	refreshData();
	$ret = buildArrayRet();
	$ret['succeeded'][$directory] = 0;
	return $ret;
}

function createDatasetSnapshot($zdataset, $snapshot_name, $recursive) {
	if (!zfsmDatasetExists($zdataset) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:%-]*$/D', $snapshot_name)) return zfsmFailure($zdataset, 'Invalid dataset or snapshot name');
	$args = array('snapshot');
	if ((string)$recursive === '1') $args[] = '-r';
	$full_name = $zdataset.'@'.$snapshot_name;
	$args[] = $full_name;
	return commandAnswer($full_name, runProcess('zfs', $args));
}

function rollbackDatasetSnapshot($snapshot, $destroy_newer = 0) {
	if (!zfsmSnapshotExists($snapshot)) return zfsmFailure($snapshot, 'Snapshot does not exist');
	$args = array('rollback');
	if ((string)$destroy_newer === '1') $args[] = '-r';
	$args[] = $snapshot;
	return commandAnswer($snapshot, runProcess('zfs', $args));
}

function renameDatasetSnapshot($zpool, $snapshot, $new_name) {
	if (!zfsmValidPoolName($zpool) || !zfsmSnapshotExists($snapshot) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:%-]*$/D', $new_name)) return zfsmFailure($snapshot, 'Invalid snapshot rename request');
	$destination = explode('@', $snapshot, 2)[0].'@'.$new_name;
	return commandAnswer($destination, runProcess('zfs', array('rename', $snapshot, $destination)));
}

function holdDatasetSnapshot($snapshot) {
	if (!zfsmSnapshotExists($snapshot)) return zfsmFailure($snapshot, 'Snapshot does not exist');
	return commandAnswer($snapshot, runProcess('zfs', array('hold', 'zfsmaster', $snapshot)));
}

function releaseDatasetSnapshot($snapshot) {
	if (!zfsmSnapshotExists($snapshot)) return zfsmFailure($snapshot, 'Snapshot does not exist');
	return commandAnswer($snapshot, runProcess('zfs', array('release', 'zfsmaster', $snapshot)));
}

function cloneDatasetSnapshot($snapshot, $clone) {
	if (!zfsmSnapshotExists($snapshot) || !zfsmValidDatasetName($clone)) return zfsmFailure($snapshot, 'Invalid snapshot or clone dataset name');
	return commandAnswer($clone, runProcess('zfs', array('clone', $snapshot, $clone)));
}

function deleteDatasetSnapshot($snapshot, $recursive = 0) {
	if (!zfsmSnapshotExists($snapshot)) return zfsmFailure($snapshot, 'Snapshot does not exist');
	$args = array('destroy');
	if ((string)$recursive === '1') $args[] = '-r';
	$args[] = $snapshot;
	return commandAnswer($snapshot, runProcess('zfs', $args));
}

?>
