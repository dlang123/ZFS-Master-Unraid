#!/bin/sh
set -eu

chmod +x /mock-bin/zfs /mock-bin/zpool
for route in createdataset editdatasetproperty snapshotdataset rollbacksnapshot renamesnapshot destroysnapshot destroydataset; do
	ZFSM_TEST_BIN_DIR=/mock-bin php /tests/admin_route_test.php "$route"
done
