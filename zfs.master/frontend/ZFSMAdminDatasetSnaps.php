<?php
$plugin = "zfs.master";
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
$urlzmadmin = "/plugins/".$plugin."/backend/ZFSMAdmin.php";

require_once $docroot."/webGui/include/Helpers.php";
require_once $docroot."/plugins/".$plugin."/include/ZFSMBase.php";
require_once $docroot."/plugins/".$plugin."/include/ZFSMHelpers.php";
require_once $docroot."/plugins/".$plugin."/backend/ZFSMOperations.php";

$zpool = $_GET['zpool'] ?? '';
$zdataset = $_GET['zdataset'] ?? '';
$snapshot_result = getDatasetSnapshotsResult($zpool, $zdataset);
$snapshots = $snapshot_result['snapshots'];
$snapshot_error = $snapshot_result['error'];

?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta http-equiv="Content-Security-Policy" content="block-all-mixed-content">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="same-origin">


<style type="text/css">	
	.zfsm_dialog {
		width: 90%;
		height: 90%;
		margin: auto;
		text-align: center;
	}

	.zfs_snap_table {
		display: table;
		width: 96%;
		border: 1px solid #ccc;
		max-height: 600px;
		overflow: auto;
		margin: 2%;
	}

	.zfs_status_box {
		width: 80%;
		border: 1px solid #ccc;
		margin-left: 10%;
	}

	.zfs_table tr>td{
		width:auto!important;
		white-space: normal!important;
	}

	.zfs_delete_btn {
		text-align: center !important;
  	}

	#zfs_master.disk_status.zfs_snap_table thead tr td{
		text-align: center !important ;
	}

	#zfs_master.disk_status.zfs_snap_table thead tr td:nth-child(4){
		white-space: normal;
		padding: 0 12px;
	}

	#zfs_master.disk_status.zfs_snap_table tbody tr td{
		padding-left: 0;
		text-align: center;
		white-space: nowrap;
	}

	#zfs_master.disk_status.zfs_snap_table tbody tr td:first-child{
		padding-left: 12px;
		white-space: normal;
		word-break: break-all; 
	}

	#zfs_master.disk_status.zfs_snap_table tbody tr td:last-child{
		display: table-cell;
		vertical-align: middle;
		padding: 0 10px;
	}

	#zfs_master.disk_status.zfs_snap_table tbody tr td:last-child span:first-child{
		margin-left: 0;
	}

	#zfs_master.disk_status.zfs_snap_table tbody tr td:last-child span{
		margin: 0 5px;
		float: none;
		padding: 0;
	}

	#zfs_master.disk_status.zfs_snap_table tbody tr td:last-child span:last-child{
		margin-right: 0;
	}

	#zfs_master.disk_status thead tr>td+td+td+td, #zfs_master.disk_status thead tr>td+td+td, #zfs_master.disk_status thead tr>td{
		padding: 0;
		text-align: center;
	}

	#adminsnaps-form-div #zfs_master.disk_status tr>td{
		width: auto;
	}
</style>

<script type="text/javascript">
window.onload = function() {
    if (parent) {
        var oHead = document.getElementsByTagName("head")[0];
        var arrStyleSheets = parent.document.getElementsByTagName("style");
        for (var i = 0; i < arrStyleSheets.length; i++)
            oHead.appendChild(arrStyleSheets[i].cloneNode(true));

		var arrStyleSheets = parent.document.getElementsByTagName("link");

        for (var i = 0; i < arrStyleSheets.length; i++)
            oHead.appendChild(arrStyleSheets[i].cloneNode(true));
    }
}
</script>

<script src="<?php autov('/webGui/javascript/dynamix.js'); ?>"></script>
<link type="text/css" rel="stylesheet" href="<?php autov('/webGui/styles/default-fonts.css'); ?>">
<link type="text/css" rel="stylesheet" href="<?php autov('/webGui/styles/default-popup.css'); ?>">

<script src="<?php autov('/webGui/javascript/jquery.filetree.js'); ?>"></script>

<script type="text/javascript" src="<?php autov('/plugins/zfs.master/assets/sweetalert2.all.min.js'); ?>"></script>
<link type="text/css" rel="stylesheet" href="<?php autov('/plugins/zfs.master/assets/sweetalert2.min.css'); ?>">

</head>

<body>
	<div id="adminsnaps-form-div" class="zfsm_dialog">
	<table id="zfs_master" class="unraid zfs_snap_table disk_status legacy wide">
	<thead>
		<tr>
		<td><input type="checkbox" id="checkAll"></td>
		<td>Name</td>
		<td>Used</td>
		<td>Refer</td>
		<td>Written</td>
		<td>Defer Destroy</td>
		<td>Holds</td>
		<td>Creation Date</td>
		<td>Actions</td>
		</tr>
	</thead>
	<tbody id="zpools">
		<?php
		if ($snapshot_error !== ''):
			echo '<tr><td colspan="9" style="color:#e22828">'.htmlspecialchars($snapshot_error, ENT_QUOTES, 'UTF-8').'</td></tr>';
		endif;
		foreach ($snapshots as $snap):
			$snapshot_name = (string)($snap['name'] ?? '');
			$snapshot_html = htmlspecialchars($snapshot_name, ENT_QUOTES, 'UTF-8');
			$snapshot_js = htmlspecialchars(json_encode($snapshot_name), ENT_QUOTES, 'UTF-8');
			$row_id = 'snap-'.sha1($snapshot_name);
			echo '<tr id="'.$row_id.'">';
			echo '<td class="snapl-delete"><input class="snapl-check" type="checkbox" value="'.$snapshot_html.'" data-row-id="'.$row_id.'"></td>';
			echo '<td class="snapl-attribute-name">';
				echo '<i class="fa fa-hdd-o icon" style="color:#486dba"></i>';
				echo $snapshot_html;
			echo '</td>';
			echo '<td class="snapl-attribute-used">';
				echo fromBytesToString($snap['used']);
			echo '</td>';
			echo '<td class="snapl-attribute-referenced">';
				echo fromBytesToString($snap['referenced']);
			echo '</td>';
			echo '<td class="snapl-attribute-written">';
				echo fromBytesToString($snap['written']);
			echo '</td>';
			echo '<td class="snapl-attribute-defer_destroy">';
				echo htmlspecialchars((string)($snap['defer_destroy'] ?? ''), ENT_QUOTES, 'UTF-8');
			echo '</td>';
			echo '<td class="snapl-attribute-userrefs">';
				echo (int)($snap['userrefs'] ?? 0);
			echo '</td>';
			echo '<td class="snapl-attribute-creation">';
				$snapdate = new DateTime();
				$snapdate->setTimestamp($snap['creation']);
				$detail = $snapdate->format('Y-m-d H:i:s');
				echo '<span>'.$detail.'</span>';
			echo '</td>';
			echo '<td id="snapl-attribute-actions">';
				echo '<span class="zfs_bar_button"><a style="cursor:pointer" class="tooltip" title="Rollback Snapshot" onclick="rollbackSnapshot('.$snapshot_js.')"><i class="fa fa-backward" style="color:orange"></i></a></span>';
				echo '<span class="zfs_bar_button"><a style="cursor:pointer" class="tooltip" title="Rename Snapshot" onclick="renameSnapshot('.$snapshot_js.')"><i class="fa fa-font"></i></a></span>';
				echo '<span class="zfs_bar_button"><a style="cursor:pointer" class="tooltip" title="Hold Snapshot" onclick="holdSnapshot('.$snapshot_js.')"><i class="fa fa-pause"></i></a></span>';
				echo '<span class="zfs_bar_button"><a style="cursor:pointer" class="tooltip" title="Release Snapshot" onclick="releaseSnapshot('.$snapshot_js.')"><i class="fa fa-play"></i></a></span>';
				echo '<span class="zfs_bar_button"><a style="cursor:pointer" class="tooltip" title="Clone Snapshot" onclick="cloneSnapshot('.$snapshot_js.')"><i class="fa fa-clone"></i></a></span>';
			echo '</td>';
			echo '</tr>';
		endforeach;?>
	</tbody>
	</table>
	<div id="zfs_status" class="zfs_status_box">
		Ready!
	</div>
	<button id="delete-snaps" class="zfs_delete_btn" type="button">Delete Snapshots</button>
	</div>
</body>
</html>

<script>
  var zfsm_csrf_token = (window.parent && typeof window.parent.zfsm_csrf_token === 'string') ? window.parent.zfsm_csrf_token : ((typeof csrf_token !== 'undefined' && csrf_token) ? csrf_token : '');
  $.ajaxSetup({
	headers: { 'X-CSRF-Token': zfsm_csrf_token }
  });

  function parseJSONResponse(value) {
	return typeof value === 'string' ? JSON.parse(value) : value;
  }

  function escapeHtml(value) {
	return String(value ?? '').replace(/[&<>"']/g, function(char) {
		return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char];
	});
  }

  $("#delete-snaps").click(function(){
	var checkedVals = $('.snapl-check:checkbox:checked').map(function() {
		return {snapshot: this.value, rowId: $(this).data('row-id')};
	}).get();

	for (const selected of checkedVals) {
		$.post('<?=$urlzmadmin?>',{cmd: 'destroysnapshot', 'snapshot': selected.snapshot, 'csrf_token': zfsm_csrf_token}, function(data) {
			updateStatusOnDeletion(parseJSONResponse(data), selected.snapshot, selected.rowId);
		});
	}
  });

  $("#checkAll").click(function(){
    $('input:checkbox').not(this).prop('checked', this.checked);
	$('#delete-snaps').prop('disabled', checkBoxes.filter(':checked').length < 1);
  });

  var checkBoxes = $('.snapl-check');
  checkBoxes.change(function () {
	$('#delete-snaps').prop('disabled', checkBoxes.filter(':checked').length < 1);
  });

  $('.snapl-check').change();

  function updateStatusOnDeletion(data, snapshot, rowId) {
	if (data['failed'] && Object.keys(data['failed']).length > 0) {
		updateStatus('Destroy of Snapshot '+snapshot+' Failed - '+formatAnswer(data['failed']));
	} else {
		updateStatus('Destroy of Snapshot '+snapshot+' Successful');
		document.getElementById(rowId)?.remove();
	}
  }

  function rollbackSnapshot(snapshot) {
	Swal2.fire({
		  title: '<strong>Rollback Snapshot<br>'+escapeHtml(snapshot)+'</strong>',
		  icon: 'warning',
		  html: 'This operation discards changes made after the snapshot and cannot be undone.',
		  input: 'checkbox',
		  inputPlaceholder: 'Also destroy snapshots and bookmarks newer than this snapshot',
		  showConfirmButton: true,
		  confirmButtonText: "Rollback",
		  showCancelButton: true
	  }).then((result) => {
		  if (result.isConfirmed) {
			$.post('<?=$urlzmadmin?>',{cmd: 'rollbacksnapshot', 'snapshot': snapshot, 'destroy_newer': result.value, 'csrf_token': zfsm_csrf_token}, function(data){
				Swal2.fire({
					title: 'Rollback Result',
					icon: 'info',
					html: formatAnswer(parseJSONResponse(data))
				}).then((result) => window.location.reload());
			});
		  }
	  });
  }

  function renameSnapshot(snapshot) {
	const currentName = snapshot.split('@').slice(1).join('@');
	Swal2.fire({
		title: 'Rename Snapshot',
		input: 'text',
		inputValue: currentName,
		inputAttributes: {maxlength: 255, autocapitalize: 'off', autocorrect: 'off'},
		showCancelButton: true,
		inputValidator: value => value ? undefined : 'Enter a snapshot name'
	}).then((result) => {
		if (!result.isConfirmed) return;
		$.post('<?=$urlzmadmin?>', {cmd: 'renamesnapshot', snapshot: snapshot, newname: result.value, csrf_token: zfsm_csrf_token}, function(data) {
			Swal2.fire({title: 'Rename Result', icon: 'info', html: formatAnswer(parseJSONResponse(data))}).then(() => window.location.reload());
		});
	});
  }

  function holdSnapshot(snapshot) {
	$.post('<?=$urlzmadmin?>',{cmd: 'holdsnapshot', 'snapshot': snapshot, 'csrf_token': zfsm_csrf_token}, function(data){
		Swal2.fire({
			title: 'Hold Result',
			icon: 'info',
			html: formatAnswer(parseJSONResponse(data))
		}).then((result) => window.location.reload());
	});
  }

  function releaseSnapshot(snapshot) {
	$.post('<?=$urlzmadmin?>',{cmd: 'releasesnapshot', 'snapshot': snapshot, 'csrf_token': zfsm_csrf_token}, function(data){
		Swal2.fire({
			title: 'Release Result',
			icon: 'info',
			html: formatAnswer(parseJSONResponse(data))
		}).then((result) => window.location.reload());
	});
  }

  function cloneSnapshot(snapshot) {
	Swal2.fire({
		  title: '<strong>Destination Dataset for '+escapeHtml(snapshot)+'</strong>',
		  input: 'text',
		  inputPlaceholder: 'Dataset',
		  inputAttributes: {
			autocapitalize: 'off',
			autocorrect: 'off'
		  },
		  showConfirmButton: true,
		  showCancelButton: true
	  }).then((result) => {
		  if (result.isConfirmed) {
			  $.post('<?=$urlzmadmin?>',{cmd: 'clonesnapshot', 'snapshot': snapshot, 'clone': result.value, 'csrf_token': zfsm_csrf_token}, function(data) {
				Swal2.fire({
					title: 'Clone Result',
					icon: 'info',
					html: formatAnswer(parseJSONResponse(data))
				}).then((result) => window.location.reload());
			  });
		  }
	  });
  }

  function updateStatus(text) {
	$("#zfs_status").text(text);
  }

  function formatAnswer(answer, indentLevel = 0) {
    const indent = '&emsp;&emsp;'.repeat(indentLevel); 
    let result = '';

    for (const key in answer) {
        if (typeof answer[key] === 'object') {
            result += `${formatAnswer(answer[key], indentLevel + 1)}`;
        } else {
            result += `${indent}${escapeHtml(key)}: ${escapeHtml(answer[key])}<br>`;
        }
    }

    return result;
  }

</script>
