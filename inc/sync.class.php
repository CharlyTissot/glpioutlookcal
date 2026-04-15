<?php
class PluginOutlookcalSync extends CommonDBTM
{
    static $rightname = 'config';

    public static function getTypeName($nb = 0): string
    {
        return 'Outlook Calendar Sync';
    }

    // ── Point d'entrée ────────────────────────────────────────
    public function run(): array
    {
        $logs = [];
        $log  = function (string $msg) use (&$logs) {
            $logs[] = '[' . date('H:i:s') . '] ' . $msg;
        };

        $cfg = PluginOutlookcalConfig::getAll();

        if (empty($cfg['tenant_id']) || empty($cfg['client_id']) || empty($cfg['client_secret'])) {
            $log('ERREUR : Configuration Azure incomplete (Tenant/Client/Secret manquants).');
            return $logs;
        }

        $token = $this->getToken($cfg);
        if (!$token) {
            $log('ERREUR : Token Azure KO — verifier Tenant ID, Client ID et Secret.');
            return $logs;
        }
        $log('Token Azure OK.');

        $created = $updated = $deleted = $errors = 0;

        if (!empty($cfg['sync_tasks']))    $this->syncTasks($cfg, $token, $log, $created, $updated, $errors);
        if (!empty($cfg['sync_external'])) $this->syncExternalEvents($cfg, $token, $log, $created, $updated, $errors);
        $this->purgeDeleted($token, $log, $deleted);

        $log(sprintf('Termine : +%d crees, ~%d maj, -%d supprimes, %d erreurs.', $created, $updated, $deleted, $errors));
        return $logs;
    }

    // ── Token Azure (Client Credentials) ─────────────────────
    private function getToken(array $cfg): ?string
    {
        $ch = curl_init('https://login.microsoftonline.com/' . rawurlencode($cfg['tenant_id']) . '/oauth2/v2.0/token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type'    => 'client_credentials',
                'client_id'     => $cfg['client_id'],
                'client_secret' => $cfg['client_secret'],
                'scope'         => 'https://graph.microsoft.com/.default',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $r = json_decode(curl_exec($ch), true);
        curl_close($ch);
        return $r['access_token'] ?? null;
    }

    // ── Tâches tickets planifiées ─────────────────────────────
    // Note GLPI 11 : colonne is_deleted supprimee de glpi_tickettasks
    private function syncTasks(array $cfg, string $token, callable $log, int &$created, int &$updated, int &$errors): void
    {
        global $DB;

        $from = date('Y-m-d H:i:s', strtotime('-' . (int)($cfg['days_past']   ?? 7)  . ' days'));
        $to   = date('Y-m-d H:i:s', strtotime('+' . (int)($cfg['days_future'] ?? 90) . ' days'));

        $rows = $DB->request([
            'FROM'  => 'glpi_tickettasks',
            'WHERE' => [
                ['NOT' => ['begin' => null]],
                ['NOT' => ['end'   => null]],
                ['begin' => ['>=', $from]],
                ['begin' => ['<=', $to]],
            ],
        ]);
        $log('Taches planifiees : ' . $rows->count());

        foreach ($rows as $task) {
            $techs = $this->resolveTechs($task);
            if (empty($techs)) {
                $log('  SKIP tache#' . $task['id'] . ' : aucun technicien assigne');
                continue;
            }
            foreach ($techs as $user) {
                $upn = $this->resolveUpn($user, $cfg);
                if (!$upn) {
                    $log('  SKIP user ' . $user['name'] . ' : email vide, configurer domaine UPN');
                    continue;
                }

                // Récupérer le ticket + entité pour le titre
                $ticketRows = $DB->request(['FROM' => 'glpi_tickets', 'WHERE' => ['id' => (int)$task['tickets_id']], 'LIMIT' => 1]);
                $ticket     = $ticketRows->count() ? $ticketRows->current() : [];
                $ticketName = $ticket['name'] ?? ('Ticket #' . $task['tickets_id']);

                $entityName = '';
                if (!empty($ticket['entities_id'])) {
                    $entRows = $DB->request(['SELECT' => ['name'], 'FROM' => 'glpi_entities', 'WHERE' => ['id' => (int)$ticket['entities_id']], 'LIMIT' => 1]);
                    if ($entRows->count()) $entityName = $entRows->current()['name'];
                }

                // Sujet : [Entité] - Titre ticket
                $subject = ($entityName ? '[' . $entityName . '] - ' : '') . $ticketName;

                // Corps : description ticket + contenu tâche
                $ticketBody = strip_tags(html_entity_decode($ticket['content'] ?? '', ENT_QUOTES, 'UTF-8'));
                $taskBody   = strip_tags(html_entity_decode($task['content']   ?? '', ENT_QUOTES, 'UTF-8'));
                $body = '';
                if ($ticketBody) $body .= "=== Description du ticket ===\n" . $ticketBody;
                if ($taskBody)   $body .= ($body ? "\n\n" : '') . "=== Contenu de la tache ===\n" . $taskBody;

                $this->upsert('task', (int)$task['id'], [
                    'subject'  => $subject,
                    'body'     => $body,
                    'begin'    => $task['begin'],
                    'end'      => $task['end'],
                    'checksum' => md5($ticketName . $entityName . $task['begin'] . $task['end'] . ($task['content'] ?? '') . ($ticket['content'] ?? '')),
                ], (int)$user['id'], $upn, $token, $log, $created, $updated, $errors);
            }
        }
    }

    private function resolveTechs(array $task): array
    {
        global $DB;
        // 1. Technicien direct sur la tâche
        if (!empty($task['users_id_tech'])) {
            $u = $this->getUser((int)$task['users_id_tech']);
            if ($u) return [$u];
        }
        // 2. Techniciens assignés au ticket (type=2)
        $techs = [];
        if (!empty($task['tickets_id'])) {
            foreach ($DB->request(['FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => (int)$task['tickets_id'], 'type' => 2]]) as $r) {
                $u = $this->getUser((int)$r['users_id']);
                if ($u) $techs[] = $u;
            }
        }
        return $techs;
    }

    // ── Événements externes Planning ──────────────────────────
    private function syncExternalEvents(array $cfg, string $token, callable $log, int &$created, int &$updated, int &$errors): void
    {
        global $DB;

        if (!$DB->tableExists('glpi_planningexternalevents')) {
            $log('Table glpi_planningexternalevents absente — skip.');
            return;
        }

        $from = date('Y-m-d H:i:s', strtotime('-' . (int)($cfg['days_past']   ?? 7)  . ' days'));
        $to   = date('Y-m-d H:i:s', strtotime('+' . (int)($cfg['days_future'] ?? 90) . ' days'));

        $rows = $DB->request([
            'FROM'  => 'glpi_planningexternalevents',
            'WHERE' => [
                ['NOT' => ['begin' => null]],
                ['NOT' => ['end'   => null]],
                ['begin' => ['>=', $from]],
                ['begin' => ['<=', $to]],
            ],
        ]);
        $log('Evenements externes : ' . $rows->count());

        foreach ($rows as $event) {
            if (empty($event['users_id'])) continue;
            $user = $this->getUser((int)$event['users_id']);
            if (!$user) continue;
            $upn = $this->resolveUpn($user, $cfg);
            if (!$upn) {
                $log('  SKIP user ' . $user['name'] . ' : email vide, configurer domaine UPN');
                continue;
            }
            $body = strip_tags(html_entity_decode($event['text'] ?? '', ENT_QUOTES, 'UTF-8'));
            $this->upsert('event', (int)$event['id'], [
                'subject'  => $event['name'] ?: 'Evenement Planning GLPI',
                'body'     => $body,
                'begin'    => $event['begin'],
                'end'      => $event['end'],
                'checksum' => md5(($event['name'] ?? '') . $event['begin'] . $event['end'] . ($event['text'] ?? '')),
            ], (int)$user['id'], $upn, $token, $log, $created, $updated, $errors);
        }
    }

    // ── CREATE ou UPDATE dans Outlook ────────────────────────
    private function upsert(string $type, int $srcId, array $item, int $userId, string $upn, string $token, callable $log, int &$created, int &$updated, int &$errors): void
    {
        global $DB;

        $existing = $DB->request([
            'FROM'  => 'glpi_plugin_outlookcal_mappings',
            'WHERE' => ['source_type' => $type, 'source_id' => $srcId, 'glpi_users_id' => $userId],
        ]);

        $payload = $this->buildPayload($item);

        if ($existing->count() === 0) {
            $resp = $this->graphPost('/users/' . rawurlencode($upn) . '/events', $payload, $token);
            if (!empty($resp['id'])) {
                $DB->insert('glpi_plugin_outlookcal_mappings', [
                    'source_type'      => $type,
                    'source_id'        => $srcId,
                    'glpi_users_id'    => $userId,
                    'outlook_event_id' => $resp['id'],
                    'upn'              => $upn,
                    'checksum'         => $item['checksum'],
                    'synced_at'        => date('Y-m-d H:i:s'),
                ]);
                $log('  [+] ' . $type . '#' . $srcId . ' -> ' . $upn);
                $created++;
            } else {
                $log('  [ERR] Creation ' . $type . '#' . $srcId . ' -> ' . $upn . ' | ' . json_encode($resp));
                $errors++;
            }
            return;
        }

        $map = $existing->current();
        if ($map['checksum'] === $item['checksum']) return;

        $resp = $this->graphPatch('/users/' . rawurlencode($upn) . '/events/' . rawurlencode($map['outlook_event_id']), $payload, $token);
        if (!empty($resp['id'])) {
            $DB->update('glpi_plugin_outlookcal_mappings', ['checksum' => $item['checksum'], 'synced_at' => date('Y-m-d H:i:s')], ['id' => $map['id']]);
            $log('  [~] ' . $type . '#' . $srcId . ' -> ' . $upn);
            $updated++;
        } else {
            // Event disparu d'Outlook → recréer
            $resp2 = $this->graphPost('/users/' . rawurlencode($upn) . '/events', $payload, $token);
            if (!empty($resp2['id'])) {
                $DB->update('glpi_plugin_outlookcal_mappings', [
                    'outlook_event_id' => $resp2['id'],
                    'checksum'         => $item['checksum'],
                    'synced_at'        => date('Y-m-d H:i:s'),
                ], ['id' => $map['id']]);
                $log('  [+] ' . $type . '#' . $srcId . ' recree -> ' . $upn);
                $created++;
            } else {
                $log('  [ERR] MAJ ' . $type . '#' . $srcId . ' -> ' . $upn);
                $errors++;
            }
        }
    }

    // ── Nettoyage orphelins ───────────────────────────────────
    private function purgeDeleted(string $token, callable $log, int &$deleted): void
    {
        global $DB;
        foreach ($DB->request(['FROM' => 'glpi_plugin_outlookcal_mappings']) as $m) {
            $alive = false;
            if ($m['source_type'] === 'task') {
                $alive = $DB->request(['FROM' => 'glpi_tickettasks', 'WHERE' => ['id' => $m['source_id']]])->count() > 0;
            } elseif ($m['source_type'] === 'event' && $DB->tableExists('glpi_planningexternalevents')) {
                $alive = $DB->request(['FROM' => 'glpi_planningexternalevents', 'WHERE' => ['id' => $m['source_id']]])->count() > 0;
            }
            if (!$alive) {
                $this->graphDelete('/users/' . rawurlencode($m['upn']) . '/events/' . rawurlencode($m['outlook_event_id']), $token);
                $DB->delete('glpi_plugin_outlookcal_mappings', ['id' => $m['id']]);
                $log('  [-] ' . $m['source_type'] . '#' . $m['source_id'] . ' supprime -> ' . $m['upn']);
                $deleted++;
            }
        }
    }

    // ── Payload Outlook ───────────────────────────────────────
    private function buildPayload(array $item): array
    {
        return [
            'subject'    => $item['subject'],
            'body'       => ['contentType' => 'text', 'content' => $item['body']],
            'start'      => ['dateTime' => date('Y-m-d\TH:i:s', strtotime($item['begin'])), 'timeZone' => 'Europe/Paris'],
            'end'        => ['dateTime' => date('Y-m-d\TH:i:s', strtotime($item['end'])),   'timeZone' => 'Europe/Paris'],
            'showAs'     => 'busy',
            'categories' => ['GLPI'],
        ];
    }

    // ── Graph API ─────────────────────────────────────────────
    private function graphPost(string $path, array $body, string $token): array
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        $ch   = curl_init('https://graph.microsoft.com/v1.0' . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        ]);
        $r = curl_exec($ch); curl_close($ch);
        return json_decode((string)$r, true) ?? [];
    }

    private function graphPatch(string $path, array $body, string $token): array
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        $ch   = curl_init('https://graph.microsoft.com/v1.0' . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PATCH',
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        ]);
        $r = curl_exec($ch); curl_close($ch);
        return json_decode((string)$r, true) ?? [];
    }

    private function graphDelete(string $path, string $token): void
    {
        $ch = curl_init('https://graph.microsoft.com/v1.0' . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        ]);
        curl_exec($ch); curl_close($ch);
    }

    // ── Helpers ───────────────────────────────────────────────
    private function getUser(int $id): ?array
    {
        global $DB;
        if ($id <= 0) return null;
        $iter = $DB->request(['FROM' => 'glpi_users', 'WHERE' => ['id' => $id]]);
        return $iter->count() ? $iter->current() : null;
    }

    /**
     * Résolution UPN — priorité email GLPI (glpi_useremails), fallback login@domaine
     */
    private function resolveUpn(array $user, array $cfg): ?string
    {
        global $DB;

        // 1. Email par défaut du compte GLPI
        $iter = $DB->request(['FROM' => 'glpi_useremails', 'WHERE' => ['users_id' => (int)$user['id'], 'is_default' => 1], 'LIMIT' => 1]);
        if ($iter->count()) {
            $email = trim($iter->current()['email'] ?? '');
            if ($email !== '') return $email;
        }

        // 2. N'importe quel email du compte
        $iter2 = $DB->request(['FROM' => 'glpi_useremails', 'WHERE' => ['users_id' => (int)$user['id']], 'LIMIT' => 1]);
        if ($iter2->count()) {
            $email = trim($iter2->current()['email'] ?? '');
            if ($email !== '') return $email;
        }

        // 3. Fallback : login@domaine
        $domain = trim($cfg['upn_domain'] ?? '');
        if ($domain !== '') return trim($user['name']) . '@' . $domain;

        return null;
    }
}
