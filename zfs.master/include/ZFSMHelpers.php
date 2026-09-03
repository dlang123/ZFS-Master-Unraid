<?php

function loadConfig($config) {	
	$config = is_array($config) ? $config : [];
	$zfsm_ret['refresh_interval'] = isset($config['general']['refresh_interval']) ? intval($config['general']['refresh_interval']) : 30;
	$zfsm_ret['lazy_load'] = isset($config['general']['lazy_load']) ? intval($config['general']['lazy_load']) : "0";
	$zfsm_ret['znapzend_data'] = isset($config['general']['znapzend_data']) ? intval($config['general']['znapzend_data']) : "0";

	$zfsm_ret['destructive_mode'] = isset($config['general']['destructive_mode']) ? intval($config['general']['destructive_mode']) : 0;

	if (isset($config['general']['exclussion']) && $config['general']['exclussion'] != '' && !isset($config['general']['exclusion'])):
		$config['general']['exclusion'] = $config['general']['exclussion'];
	endif;

	if (!isset($config['general']['exclusion']) || $config['general']['exclusion'] == '' || $config['general']['exclusion'] == ' '):
		$zfsm_ret['dataset_exclusion'] = '';
	else:
		$zfsm_ret['dataset_exclusion'] = $config['general']['exclusion'];
	endif;
		
	$zfsm_ret['snap_max_days_alert'] = isset($config['general']['snap_max_days_alert']) ? intval($config['general']['snap_max_days_alert']) : 30;
	$zfsm_ret['snap_prefix'] = isset($config['general']['snap_prefix']) ? $config['general']['snap_prefix'] : '';

	if (!isset($config['general']['snap_pattern']) || $config['general']['snap_pattern'] == ''):
		$zfsm_ret['snap_pattern'] = 'Y-m-d-His';
	else:
		$zfsm_ret['snap_pattern'] = $config['general']['snap_pattern'];
	endif;

	if (!isset($config['general']['directory_listing']) || $config['general']['directory_listing'] == ''):
		$zfsm_ret['directory_listing'] = array();
	else:
		$zfsm_ret['directory_listing'] = preg_split('/\r\n|\r|\n/', $config['general']['directory_listing']);
	endif;
	
	return $zfsm_ret;
}

function zfsnotify( $subject, $description, $message, $type="normal") {	
	$notify = ($GLOBALS["docroot"] ?? '/usr/local/emhttp').'/plugins/dynamix/scripts/notify';
	runProcess($notify, array('-e', 'ZFS Master', '-s', $subject, '-d', $description, '-m', (string)$message, '-i', $type));
}

function fromBytesToString($bytes) {
	$units = array('B', 'KiB', 'MiB', 'GiB', 'TiB'); 

	$bytes = is_numeric($bytes) ? max((float)$bytes, 0) : 0;
   	$pow = floor(($bytes ? log($bytes) : 0) / log(1024)); 
   	$pow = min($pow, count($units) - 1); 

   	$bytes /= pow(1024, $pow);

   return round($bytes, 2) . ' ' . $units[$pow]; 
}
	  
function implodeWithKeys($glue, $array, $symbol = ': ') {
	return implode( $glue, array_map( function($k, $v) use($symbol) {
			return $k . $symbol . $v;
		},
		array_keys($array),
		array_values($array))
	);
}
	
function runProcess($command, $args = array(), $stdin = null) {
	$test_bin_dir = getenv('ZFSM_TEST_BIN_DIR');
	if ($test_bin_dir !== false && $test_bin_dir !== '') {
		$candidate = rtrim($test_bin_dir, '/').'/'.basename($command);
		if (is_file($candidate)) $command = $candidate;
	}
	$descriptor_spec = array(
		0 => array('pipe', 'r'),
		1 => array('pipe', 'w'),
		2 => array('redirect', 1)
	);
	$cmd = array_merge(array($command), array_values($args));
	$pipes = array();
	$process = @proc_open($cmd, $descriptor_spec, $pipes, null, null, array('bypass_shell' => true));

	if (!is_resource($process)) {
		return array('code' => ZFSM_ERR_UNABLE_TO_CREATE_PROC, 'output' => 'Unable to start '.basename($command));
	}

	if ($stdin !== null && $stdin !== '') {
		fwrite($pipes[0], $stdin);
	}
	fclose($pipes[0]);
	$output = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	$code = proc_close($process);

	return array('code' => $code, 'output' => trim((string)$output));
}

function commandAnswer($subject, $result) {
	$answer = array('succeeded' => array(), 'failed' => array());
	if (($result['code'] ?? 1) === 0) {
		$answer['succeeded'][$subject] = 0;
	} else {
		$message = trim((string)($result['output'] ?? ''));
		$answer['failed'][$subject] = $message !== '' ? $message : (int)($result['code'] ?? 1);
	}
	return $answer;
}
	
function cleanZFSCreateDatasetParams($params) {
	$retParams = is_array($params) ? $params : array();

	unset($retParams['zpool']);
	unset($retParams['name']);
		
	foreach ($retParams as $key => $value):
		if ($value == 'inherit'):
			unset($retParams[$key]);
		endif;
	endforeach;
		
	if (($retParams['mount'] ?? 'yes') == 'no'):
		$retParams['mountpoint'] = 'none';
	else:
		if (!isset($retParams['mountpoint']) || $retParams['mountpoint'] == ''):
			unset($retParams['mountpoint']);
		endif;
	endif;

	if (($retParams['encryption'] ?? 'no') == 'no'):
		unset($retParams['encryption']);
		unset($retParams['passphrase']);
	else:
		if (!isset($retParams['passphrase']) || $retParams['passphrase'] == ''):
			unset($retParams['encryption']);
		else:
			$retParams['encryption'] = 'on';
			$retParams['keylocation'] = 'prompt';
			$retParams['keyformat'] = 'passphrase';
		endif;
	endif;
		
	unset($retParams['mount']);
	
	if (!isset($retParams['quota']) || $retParams['quota'] == '' || $retParams['quota'] == '0'):
		unset($retParams['quota']);
	else:
		$retParams['quota'] = $retParams['quota'].$retParams['quotaunit'];
	endif;

	unset($retParams['quotaunit']);
		
	return $retParams;
}
	
function sortDatasetArray($datasetArray) {
	if (!is_array($datasetArray)) {
		return array();
	}

	if (isset($datasetArray['snapshots']) && is_array($datasetArray['snapshots']) && count($datasetArray['snapshots']) > 0):
		usort($datasetArray['snapshots'], function($item1, $item2) { 
			return ($item1['creation'] ?? 0) <=> ($item2['creation'] ?? 0);
		});
	endif;

	if (!isset($datasetArray['child']) || is_null($datasetArray['child']) || !is_array($datasetArray['child']) || count($datasetArray['child']) <= 0):
		return $datasetArray;
	endif;

	ksort($datasetArray['child']);
	
	foreach ($datasetArray['child'] as $dataset):
		if (is_array($dataset) && isset($dataset['name'])) {
			$datasetArray['child'][$dataset['name']] = sortDatasetArray($dataset);
		}
	endforeach;
	
	return $datasetArray;
}

function generatePoolDatasetOptions($dataset_array) {
	if (!isset($dataset_array['child']) || !is_array($dataset_array['child']) || count($dataset_array['child']) <= 0):
		return;
	endif;
	
	foreach ($dataset_array['child'] as $zdataset):
		$option = ltrim(stristr($zdataset['name'], '/'), '/')."/";
		echo '<option value="'.htmlspecialchars($option, ENT_QUOTES, 'UTF-8').'">';

		if (count($zdataset['child']) > 0):
			generatePoolDatasetOptions($zdataset);
		endif;
	endforeach;
}

?>
