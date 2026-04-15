<?php
class PluginOutlookcalConfig extends CommonDBTM
{
    static $rightname = 'config';

    public static function getTypeName($nb = 0): string
    {
        return 'Outlook Calendar Sync';
    }

    public static function getValue(string $name, string $default = ''): string
    {
        global $DB;
        $iter = $DB->request(['FROM' => 'glpi_plugin_outlookcal_configs', 'WHERE' => ['name' => $name]]);
        return $iter->count() ? (string)($iter->current()['value'] ?? $default) : $default;
    }

    public static function setValue(string $name, string $value): void
    {
        global $DB;
        $exists = $DB->request(['FROM' => 'glpi_plugin_outlookcal_configs', 'WHERE' => ['name' => $name]])->count();
        if ($exists) {
            $DB->update('glpi_plugin_outlookcal_configs', ['value' => $value], ['name' => $name]);
        } else {
            $DB->insert('glpi_plugin_outlookcal_configs', ['name' => $name, 'value' => $value]);
        }
    }

    public static function getAll(): array
    {
        global $DB;
        $cfg = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_outlookcal_configs']) as $row) {
            $cfg[$row['name']] = $row['value'];
        }
        return $cfg;
    }

    public static function saveFromPost(array $post): void
    {
        // Champs texte
        foreach (['tenant_id', 'client_id', 'upn_domain', 'cron_interval', 'days_past', 'days_future'] as $f) {
            if (isset($post[$f])) {
                self::setValue($f, trim($post[$f]));
            }
        }
        // Secret : ne pas écraser si vide
        if (!empty(trim($post['client_secret'] ?? ''))) {
            self::setValue('client_secret', trim($post['client_secret']));
        }
        // Checkboxes
        self::setValue('sync_tasks',    isset($post['sync_tasks'])    ? '1' : '0');
        self::setValue('sync_external', isset($post['sync_external']) ? '1' : '0');

        // Créer / mettre à jour le crontab système
        self::updateCrontab((int)($post['cron_interval'] ?? 5));
    }

    public static function updateCrontab(int $interval): void
    {
        $interval   = max(1, $interval);
        $phpBin     = trim(shell_exec('which php 2>/dev/null') ?: '/usr/local/bin/php');
        $scriptPath = realpath(dirname(__DIR__) . '/front/runcron.php');
        $marker     = 'outlookcal/front/runcron.php';
        $cronExpr   = ($interval === 1) ? '* * * * *' : '*/' . $interval . ' * * * *';
        $newLine    = $cronExpr . ' ' . $phpBin . ' ' . $scriptPath . ' >> /tmp/outlookcal_cron.log 2>&1';

        $current = shell_exec('crontab -l 2>/dev/null') ?? '';
        $lines   = explode("\n", $current);

        // Supprimer l'ancienne entrée outlookcal
        $lines = array_filter($lines, fn($l) => strpos($l, $marker) === false && trim($l) !== '');

        // Ajouter la nouvelle
        $lines[] = $newLine;

        $newCrontab = implode("\n", $lines) . "\n";
        $tmp = tempnam(sys_get_temp_dir(), 'crontab_');
        file_put_contents($tmp, $newCrontab);
        shell_exec('crontab ' . escapeshellarg($tmp));
        unlink($tmp);
    }
}
