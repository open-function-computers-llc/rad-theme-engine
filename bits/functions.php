<?php

use ofc\Site;

require(__DIR__."/vendor/autoload.php");

$site = new Site();
function site()
{
    global $site;
    return $site;
}
