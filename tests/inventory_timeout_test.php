<?php
require __DIR__.'/../zfs.master/backend/ZFSMOperations.php';
$started = microtime(true);
$GLOBALS['zfsm_inventory_deadline'] = $started + 0.15;
try {
    runProcess('/bin/sleep', array('5'));
    throw new LogicException('Blocked inventory command did not time out');
} catch (RuntimeException $error) {
    if (strpos($error->getMessage(), 'timed out') === false) throw $error;
} finally {
    unset($GLOBALS['zfsm_inventory_deadline']);
}
if (microtime(true) - $started > 3) throw new RuntimeException('Timeout did not bound execution');
if (runProcess('/bin/true')['code'] !== 0) throw new RuntimeException('Normal execution changed after timeout');
echo "inventory command timeout passed\n";
