<?php

if (PHP_SAPI !== 'cli') {
    die('Run from CLI only!');
}

if ($_SERVER['argc'] < 3) {
    die('Use '.PHP_EOL.realpath(dirname(__FILE__).'/../').'/cli.php APP CLASS PARAMS');
}

try {
    if (!isset($wa_config_path)) {
        $wa_config_path = realpath(dirname(__FILE__).'/../wa-config');
    }
    require_once $wa_config_path.'/SystemConfig.class.php';
    $config = new SystemConfig('cli');
    waSystem::getInstance(null, $config)->dispatchCli($_SERVER['argv']);
} catch (Exception $e) {
    waLog::log($e, 'cli.log');
    if (waSystemConfig::isDebug()) {
        fwrite(STDERR, PHP_EOL.$e.PHP_EOL);
    }
}
