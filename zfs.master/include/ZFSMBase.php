<?php
	$plugin = "zfs.master";
	$docroot = $docroot ?? ($_SERVER['DOCUMENT_ROOT'] ?? '/usr/local/emhttp');
	$docroot = $docroot ?: '/usr/local/emhttp';
	$plugin_config = "/boot/config/plugins/".$plugin."/".$plugin.".cfg";
	$plugin_include = $docroot."/plugins/".$plugin."/include/";
	$plugin_session_file = "/tmp/".$plugin."-session.data";
		
	$urlzmadmin = "/plugins/".$plugin."/backend/ZFSMAdmin.php";
	$urlcreatedataset = "/plugins/".$plugin."/frontend/ZFSMCreateDataset.php";
	$urladmindatasetsnaps = "/plugins/".$plugin."/frontend/ZFSMAdminDatasetSnaps.php";
?>
