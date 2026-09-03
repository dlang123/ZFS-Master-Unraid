#!/bin/sh
set -eu

chmod +x /mock-bin/zfs /mock-bin/zpool
touch /tmp/zfsm_reload
ZFSM_TEST_BIN_DIR=/mock-bin timeout 3 php /usr/local/emhttp/plugins/zfs.master/nchan/zfs_master || [ "$?" -eq 124 ]
grep -Fq '"op":"getDatasets"' /tmp/zfsm-publish.log
grep -Fq '"name":"tank@root"' /tmp/zfsm-publish.log
grep -Fq '"name":"tank\/data@daily"' /tmp/zfsm-publish.log
echo 'nchan lazy-load smoke test passed'
