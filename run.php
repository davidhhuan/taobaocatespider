<?php
if (PHP_SAPI != "cli") {
    die('Run this programe in command only');
}

include_once dirname(__FILE__) . '/Spider.php';

Spider::run();