<?php

function publish($channel, $message) {
	file_put_contents('/tmp/zfsm-publish.log', $message."\n", FILE_APPEND);
}

