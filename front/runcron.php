<?php
/**
 * Outlook Calendar Sync — Script cron autonome
 * Appelé directement par le crontab système, sans passer par le cron GLPI
 * Bootstrap GLPI 11 via Symfony Kernel
 */

$glpiRoot = dirname(__FILE__, 4);
chdir($glpiRoot);

require_once $glpiRoot . '/vendor/autoload.php';

use Glpi\Kernel\Kernel;
$kernel = new Kernel('production', false);
$kernel->boot();

$plugin = new Plugin();
if (!$plugin->isActivated('outlookcal')) {
    echo date('[Y-m-d H:i:s]') . " Plugin outlookcal non active.\n";
    exit(1);
}

include_once $glpiRoot . '/plugins/outlookcal/inc/config.class.php';
include_once $glpiRoot . '/plugins/outlookcal/inc/sync.class.php';

echo date('[Y-m-d H:i:s]') . " Outlook Calendar Sync — Demarrage...\n";

try {
    $syncer = new PluginOutlookcalSync();
    $logs   = $syncer->run();
    $log    = implode("\n", $logs);

    PluginOutlookcalConfig::setValue('last_run',     date('Y-m-d H:i:s'));
    PluginOutlookcalConfig::setValue('last_run_log', $log);

    echo $log . "\n";
    echo date('[Y-m-d H:i:s]') . " Termine.\n";
    exit(0);
} catch (\Throwable $e) {
    echo date('[Y-m-d H:i:s]') . " ERREUR : " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
