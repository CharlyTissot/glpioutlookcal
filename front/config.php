<?php
/**
 * Outlook Calendar Sync — Page de configuration
 */

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

include_once __DIR__ . '/../inc/config.class.php';
include_once __DIR__ . '/../inc/sync.class.php';

$formAction = Plugin::getWebDir('outlookcal') . '/front/config.php';

// ── POST ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['save'])) {
        PluginOutlookcalConfig::saveFromPost($_POST);
        Html::redirect($formAction . '?msg=saved');
        exit();
    }

    if (isset($_POST['run_now'])) {
        try {
            $syncer = new PluginOutlookcalSync();
            $logs   = $syncer->run();
            $log    = implode("\n", $logs);
            PluginOutlookcalConfig::setValue('last_run',     date('Y-m-d H:i:s'));
            PluginOutlookcalConfig::setValue('last_run_log', $log);
            Html::redirect($formAction . '?msg=synced');
            exit();
        } catch (\Throwable $e) {
            // Afficher l'erreur sur la page
        }
    }

    if (isset($_POST['reset_mapping'])) {
        global $DB;
        $DB->doQuery("TRUNCATE TABLE `glpi_plugin_outlookcal_mappings`");
        Html::redirect($formAction . '?msg=reset');
        exit();
    }
}

Html::header('Outlook Calendar Sync', $_SERVER['PHP_SELF'], 'config', 'plugins');
Html::displayMessageAfterRedirect();

$msg = $_GET['msg'] ?? '';
if ($msg === 'saved')  echo '<div class="alert alert-success">Configuration enregistree — crontab mis a jour.</div>';
if ($msg === 'synced') echo '<div class="alert alert-info">Synchronisation terminee.</div>';
if ($msg === 'reset')  echo '<div class="alert alert-warning">Mapping reinitialise.</div>';

$cfg          = PluginOutlookcalConfig::getAll();
$cronInterval = max(1, (int)($cfg['cron_interval'] ?? 5));
$lastRun      = $cfg['last_run']     ?? '';
$lastLog      = $cfg['last_run_log'] ?? '';

global $DB;
$nbMapped = 0;
try {
    $r = $DB->request(['FROM' => 'glpi_plugin_outlookcal_mappings', 'COUNT' => 'c']);
    if ($r->count()) { $row = $r->current(); $nbMapped = (int)($row['c'] ?? 0); }
} catch (\Throwable $e) {}

// Vérifier si le crontab est actif
$cronActive = (bool)shell_exec('crontab -l 2>/dev/null | grep outlookcal/front/runcron.php');
?>

<div class="container-fluid mt-3">
<div class="row justify-content-center">
<div class="col-lg-9 col-xl-8">

<style>
.oc h3{font-size:16px;font-weight:700;margin:0 0 18px;padding-bottom:8px;border-bottom:2px solid #0078d4;color:#0078d4}
.oc .bloc{border:1px solid #dee2e6;border-radius:6px;margin-bottom:20px;overflow:hidden}
.oc .bloc-head{background:#0078d4;color:#fff;padding:10px 16px;font-size:13px;font-weight:600;display:flex;align-items:center;gap:8px}
.oc .bloc-body{padding:18px;background:#fff}
.oc .field-row{display:flex;align-items:flex-start;gap:12px;margin-bottom:12px}
.oc .field-row label{min-width:210px;font-weight:600;font-size:13px;color:#333;padding-top:7px}
.oc .field-wrap{flex:1}
.oc input[type=text],.oc input[type=password],.oc input[type=number]{width:100%;padding:6px 10px;border:1px solid #ced4da;border-radius:4px;font-size:13px;box-sizing:border-box}
.oc input:focus{border-color:#0078d4;outline:none;box-shadow:0 0 0 2px rgba(0,120,212,.12)}
.oc .hint{font-size:11px;color:#888;margin-top:3px}
.oc .chk-row{display:flex;align-items:center;gap:8px;margin-bottom:10px}
.oc .chk-row input{width:15px;height:15px;accent-color:#0078d4}
.oc .chk-row label{font-size:13px}
.oc hr{border:none;border-top:1px solid #eee;margin:14px 0}
.oc .btn-blue{background:#0078d4;color:#fff;border:none;padding:8px 22px;border-radius:4px;font-size:13px;font-weight:600;cursor:pointer}
.oc .btn-blue:hover{background:#005ea6}
.oc .btn-green{background:#107c10;color:#fff;border:none;padding:8px 22px;border-radius:4px;font-size:13px;font-weight:600;cursor:pointer;margin-right:6px}
.oc .btn-green:hover{background:#0a5e0a}
.oc .btn-red{background:#d83b01;color:#fff;border:none;padding:8px 22px;border-radius:4px;font-size:13px;font-weight:600;cursor:pointer}
.oc .btn-red:hover{background:#a52800}
.oc .badge{display:inline-block;background:#f0f0f0;border-radius:4px;padding:4px 10px;font-size:12px;margin-right:6px}
.oc .badge b{color:#0078d4}
.oc .badge-ok{background:#dff6dd;color:#1e5e1e;border:1px solid #6bb56b}
.oc .badge-ko{background:#fde7e9;color:#8b0000;border:1px solid #e08080}
.oc .log{background:#1e1e1e;color:#d4d4d4;border-radius:4px;padding:12px;font-family:monospace;font-size:12px;max-height:260px;overflow-y:auto;white-space:pre;margin-top:8px;line-height:1.5}
.oc table{border-collapse:collapse;width:100%;font-size:13px}
.oc th{background:#f5f5f5;padding:7px 12px;text-align:left;border:1px solid #dee2e6}
.oc td{padding:7px 12px;border:1px solid #dee2e6}
code{background:#f0f0f0;padding:1px 4px;border-radius:3px;font-size:12px}
</style>

<div class="oc">
<h3>Outlook Calendar Sync</h3>

<!-- CONFIGURATION -->
<div class="bloc">
    <div class="bloc-head">Configuration Azure / Microsoft Graph</div>
    <div class="bloc-body">
        <form method="POST" action="<?php echo $formAction; ?>">
            <?php echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]); ?>

            <div class="field-row">
                <label>Tenant ID *</label>
                <div class="field-wrap">
                    <input type="text" name="tenant_id" value="<?php echo htmlspecialchars($cfg['tenant_id'] ?? ''); ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" autocomplete="off" />
                    <div class="hint">Azure AD > Proprietes > ID de locataire</div>
                </div>
            </div>
            <div class="field-row">
                <label>Client ID *</label>
                <div class="field-wrap">
                    <input type="text" name="client_id" value="<?php echo htmlspecialchars($cfg['client_id'] ?? ''); ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" autocomplete="off" />
                    <div class="hint">App registrations > votre app > ID application (client)</div>
                </div>
            </div>
            <div class="field-row">
                <label>Client Secret *</label>
                <div class="field-wrap">
                    <input type="password" name="client_secret" value="<?php echo htmlspecialchars($cfg['client_secret'] ?? ''); ?>" placeholder="Laisser vide pour conserver l'existant" autocomplete="new-password" />
                    <div class="hint">App registrations > Certificats et secrets</div>
                </div>
            </div>
            <div class="field-row">
                <label>Domaine UPN (fallback)</label>
                <div class="field-wrap">
                    <input type="text" name="upn_domain" value="<?php echo htmlspecialchars($cfg['upn_domain'] ?? ''); ?>" placeholder="mondomaine.fr" />
                    <div class="hint">Priorite : email du compte GLPI. Fallback : login@domaine si email vide.</div>
                </div>
            </div>

            <hr>
            <p style="font-weight:600;font-size:13px;margin:0 0 10px">Sources a synchroniser</p>
            <div class="chk-row">
                <input type="checkbox" id="sync_tasks" name="sync_tasks" <?php echo !empty($cfg['sync_tasks']) ? 'checked' : ''; ?> />
                <label for="sync_tasks"><strong>Taches planifiees de tickets</strong> avec date debut et fin</label>
            </div>
            <div class="chk-row">
                <input type="checkbox" id="sync_external" name="sync_external" <?php echo !empty($cfg['sync_external']) ? 'checked' : ''; ?> />
                <label for="sync_external"><strong>Evenements externes Planning GLPI</strong></label>
            </div>

            <hr>
            <p style="font-weight:600;font-size:13px;margin:0 0 10px">Parametres</p>
            <div class="field-row">
                <label>Intervalle sync (minutes)</label>
                <div class="field-wrap">
                    <input type="number" name="cron_interval" min="1" max="60" value="<?php echo $cronInterval; ?>" style="width:80px" />
                    <div class="hint">Le crontab systeme est cree/mis a jour automatiquement a l'enregistrement.</div>
                </div>
            </div>
            <div class="field-row">
                <label>Fenetre passe (jours)</label>
                <div class="field-wrap">
                    <input type="number" name="days_past" min="1" max="365" value="<?php echo (int)($cfg['days_past'] ?? 7); ?>" style="width:80px" />
                </div>
            </div>
            <div class="field-row">
                <label>Fenetre futur (jours)</label>
                <div class="field-wrap">
                    <input type="number" name="days_future" min="1" max="730" value="<?php echo (int)($cfg['days_future'] ?? 90); ?>" style="width:80px" />
                </div>
            </div>

            <hr>
            <button type="submit" name="save" class="btn-blue">Enregistrer</button>
        </form>
    </div>
</div>

<!-- ACTIONS -->
<div class="bloc">
    <div class="bloc-head">Etat &amp; Actions</div>
    <div class="bloc-body">
        <div style="margin-bottom:14px">
            <span class="badge">Evenements synchronises : <b><?php echo $nbMapped; ?></b></span>
            <?php if ($lastRun): ?>
            <span class="badge">Dernier cron : <b><?php echo htmlspecialchars($lastRun); ?></b></span>
            <?php endif; ?>
            <span class="badge <?php echo $cronActive ? 'badge-ok' : 'badge-ko'; ?>">
                Crontab : <b><?php echo $cronActive ? 'Actif' : 'Inactif'; ?></b>
            </span>
        </div>

        <form method="POST" action="<?php echo $formAction; ?>" style="display:inline">
            <?php echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]); ?>
            <button type="submit" name="run_now" class="btn-green" onclick="return confirm('Lancer la sync maintenant ?')">Lancer maintenant</button>
        </form>
        <form method="POST" action="<?php echo $formAction; ?>" style="display:inline">
            <?php echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]); ?>
            <button type="submit" name="reset_mapping" class="btn-red" onclick="return confirm('Reinitialiser le mapping ?')">Reinitialiser le mapping</button>
        </form>
    </div>
</div>

<!-- RAPPORT -->
<?php if ($lastLog): ?>
<div class="bloc">
    <div class="bloc-head">Dernier rapport — <?php echo htmlspecialchars($lastRun); ?></div>
    <div class="bloc-body">
        <div class="log"><?php echo htmlspecialchars($lastLog); ?></div>
    </div>
</div>
<?php endif; ?>

<!-- AIDE -->
<div class="bloc">
    <div class="bloc-head">Permission Entra (Azure AD) requise</div>
    <div class="bloc-body" style="font-size:13px">
        <table style="max-width:440px">
            <tr><th>Permission</th><th>Type</th><th>Consentement</th></tr>
            <tr><td><code>Calendars.ReadWrite</code></td><td>Application</td><td>Admin requis</td></tr>
        </table>
        <p style="margin-top:10px;color:#555">Azure AD > App registrations > votre app > API permissions > Add > Microsoft Graph > Application permissions > <code>Calendars.ReadWrite</code> > Grant admin consent.</p>
    </div>
</div>

</div><!-- .oc -->
</div></div></div>
<?php Html::footer(); ?>
