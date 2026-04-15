<?php
function plugin_outlookcal_install(): bool
{
    global $DB;

    if (!$DB->tableExists('glpi_plugin_outlookcal_configs')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_outlookcal_configs` (
            `id`    INT(11)      NOT NULL AUTO_INCREMENT,
            `name`  VARCHAR(255) NOT NULL DEFAULT '',
            `value` LONGTEXT              DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        foreach ([
            'tenant_id'     => '',
            'client_id'     => '',
            'client_secret' => '',
            'upn_domain'    => '',
            'sync_tasks'    => '1',
            'sync_external' => '1',
            'cron_interval' => '5',
            'days_past'     => '7',
            'days_future'   => '90',
            'last_run'      => '',
            'last_run_log'  => '',
        ] as $name => $value) {
            $DB->doQuery("INSERT INTO `glpi_plugin_outlookcal_configs` (`name`, `value`)
                VALUES ('" . $DB->escape($name) . "', '" . $DB->escape($value) . "')");
        }
    }

    if (!$DB->tableExists('glpi_plugin_outlookcal_mappings')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_outlookcal_mappings` (
            `id`               INT(11)      NOT NULL AUTO_INCREMENT,
            `source_type`      VARCHAR(50)  NOT NULL,
            `source_id`        INT(11)      NOT NULL,
            `glpi_users_id`    INT(11)      NOT NULL,
            `outlook_event_id` VARCHAR(512) NOT NULL,
            `upn`              VARCHAR(255) NOT NULL,
            `checksum`         VARCHAR(64)  NOT NULL DEFAULT '',
            `synced_at`        DATETIME              DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_mapping` (`source_type`(20), `source_id`, `glpi_users_id`),
            KEY `idx_source`        (`source_type`(20), `source_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    return true;
}

function plugin_outlookcal_uninstall(): bool
{
    global $DB;
    $DB->doQuery("DROP TABLE IF EXISTS `glpi_plugin_outlookcal_configs`");
    $DB->doQuery("DROP TABLE IF EXISTS `glpi_plugin_outlookcal_mappings`");

    // Supprimer la ligne crontab
    $marker  = 'outlookcal/front/runcron.php';
    $current = shell_exec('crontab -l 2>/dev/null') ?? '';
    $lines   = array_filter(explode("\n", $current), fn($l) => strpos($l, $marker) === false && trim($l) !== '');
    $tmp     = tempnam(sys_get_temp_dir(), 'crontab_');
    file_put_contents($tmp, implode("\n", $lines) . "\n");
    shell_exec('crontab ' . escapeshellarg($tmp));
    unlink($tmp);

    return true;
}
