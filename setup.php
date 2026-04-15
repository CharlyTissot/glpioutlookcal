<?php
/**
 * Outlook Calendar Sync — Plugin GLPI
 * Compatible GLPI 10.x et 11.x
 */

define('PLUGIN_OUTLOOKCAL_VERSION',  '1.0.0');
define('PLUGIN_OUTLOOKCAL_MIN_GLPI', '10.0.0');
define('PLUGIN_OUTLOOKCAL_MAX_GLPI', '11.99.99');

function plugin_version_outlookcal(): array
{
    return [
        'name'         => 'Outlook Calendar Sync',
        'version'      => PLUGIN_OUTLOOKCAL_VERSION,
        'author'       => 'CharlyTissot',
        'license'      => 'GPL v2',
        'homepage'     => '',
        'requirements' => [
            'glpi' => ['min' => PLUGIN_OUTLOOKCAL_MIN_GLPI, 'max' => PLUGIN_OUTLOOKCAL_MAX_GLPI],
            'php'  => ['min' => '7.4'],
        ],
    ];
}

function plugin_outlookcal_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, PLUGIN_OUTLOOKCAL_MIN_GLPI, 'lt')) {
        echo 'GLPI ' . PLUGIN_OUTLOOKCAL_MIN_GLPI . ' minimum requis.';
        return false;
    }
    if (!function_exists('curl_init')) {
        echo 'Extension PHP cURL requise.';
        return false;
    }
    return true;
}

function plugin_outlookcal_check_config(bool $verbose = false): bool
{
    return true;
}

// Nom obligatoire : plugin_init_{nom} — PAS plugin_{nom}_init
function plugin_init_outlookcal(): void
{
    global $PLUGIN_HOOKS;
    $PLUGIN_HOOKS['csrf_compliant']['outlookcal'] = true;
    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS['config_page']['outlookcal'] = 'front/config.php';
    }
}
