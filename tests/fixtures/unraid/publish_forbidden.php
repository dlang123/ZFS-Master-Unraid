<?php
function publish($channel, $message) {
    throw new RuntimeException('HTTP inventory must not call publish');
}
