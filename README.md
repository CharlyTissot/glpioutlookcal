# Outlook Calendar Sync — Plugin GLPI

Synchronise automatiquement les **tâches planifiées de tickets** et les **événements externes du Planning GLPI** vers les **calendriers Outlook 365** des techniciens assignés, via l'API Microsoft Graph.

> Compatible **GLPI11.x** — Testé sur GLPI 11 (Symfony Kernel)

---

## Fonctionnement

```
GLPI Planning                    Microsoft Graph API
─────────────────                ──────────────────────
Tâche planifiée  ──────────────► Événement Outlook
Événement externe                (agenda du technicien)
```

- **Sens unique** : GLPI → Outlook uniquement
- **Technicien ciblé** : le technicien assigné à la tâche (ou aux techniciens du ticket en fallback)
- **Résolution UPN** : priorité à l'email du compte GLPI, fallback sur `login@domaine` configurable
- **Synchronisation incrémentale** : seuls les événements modifiés (checksum) sont mis à jour
- **Nettoyage automatique** : les événements Outlook orphelins (tâche supprimée dans GLPI) sont supprimés
- **Cron autonome** : `front/runcron.php` appelé directement par le crontab système (sans dépendre du cron GLPI)

---

## Structure

```
outlookcal/
├── setup.php                   # Déclaration plugin, hooks GLPI
├── hook.php                    # Installation / désinstallation (tables SQL)
├── inc/
│   ├── config.class.php        # Lecture/écriture configuration (clé/valeur)
│   └── sync.class.php          # Logique de synchronisation Graph API
├── front/
│   ├── config.php              # Page de configuration (interface admin GLPI)
│   └── runcron.php             # Script cron autonome (appelé par crontab système)
└── install/
    └── (géré dans hook.php)
```

---

## Prérequis

| Composant | Version minimale |
|-----------|-----------------|
| GLPI | 10.0.0 |
| PHP | 7.4 |
| Extension PHP | cURL |
| Microsoft 365 | Tenant avec app Entra enregistrée |

---

## Installation

### 1. Déposer le plugin

```bash
unzip outlookcal.zip -d /votre/dossier/glpi/plugins/
```

### 2. Activer dans GLPI

**Configuration → Plugins → Outlook Calendar Sync → Installer → Activer**

Le bouton **Réglages** apparaît dans la liste des plugins.

### 3. Configurer l'app Entra (Azure AD)

Dans le portail Azure AD, sur votre app registration, ajouter la permission :

| Permission | Type | Consentement |
|------------|------|--------------|
| `Calendars.ReadWrite` | Application | Administrateur requis |

> **Note :** Permission de type **Application** (pas Delegated) — permet d'écrire sur le calendrier de n'importe quel utilisateur du tenant sans session active.
>
> Même procédure que `Mail.Send` si déjà configuré sur le tenant.

### 4. Renseigner la configuration

Depuis **Configuration → Plugins → Outlook Calendar Sync → Réglages** :

| Champ | Description |
|-------|-------------|
| Tenant ID | ID de locataire Azure AD |
| Client ID | ID de l'application Entra |
| Client Secret | Secret client de l'application |
| Domaine UPN | Fallback si l'email GLPI est vide (`login@domaine.fr`) |
| Sources | Tâches planifiées et/ou Événements externes |
| Intervalle (min) | Fréquence de synchronisation (défaut : 5 min) |
| Fenêtre passé | Nombre de jours dans le passé à synchroniser (défaut : 7) |
| Fenêtre futur | Nombre de jours dans le futur à synchroniser (défaut : 90) |

> À la sauvegarde, le crontab système est **créé ou mis à jour automatiquement** avec l'intervalle choisi.

### 5. Vérifier le crontab

```bash
crontab -l | grep outlookcal
# Exemple : */5 * * * * /usr/local/bin/php .../outlookcal/front/runcron.php >> /tmp/outlookcal_cron.log 2>&1
```

### 6. Tester manuellement

```bash
/usr/local/bin/php /var/www/glpi/plugins/outlookcal/front/runcron.php
```

---

## Résolution UPN (email Outlook)

Le plugin détermine l'adresse email Outlook du technicien dans cet ordre :

1. **Email par défaut** du compte GLPI (`glpi_useremails` avec `is_default = 1`)
2. **Premier email** trouvé dans `glpi_useremails`
3. **Fallback** : `login_glpi@domaine_upn` (si Domaine UPN configuré)

> ⚠️ Si l'email GLPI ne correspond pas à l'UPN Outlook (ex : `charly` → `charly@domaine.fr`), configurer le **Domaine UPN** dans les réglages du plugin.

---

## Format des événements Outlook

### Tâches de tickets
- **Sujet** : `[Entité] - Titre du ticket`
- **Corps** : Description du ticket + Contenu de la tâche
- **Catégorie** : `GLPI`
- **Statut** : Occupé

### Événements externes Planning
- **Sujet** : Nom de l'événement
- **Corps** : Description de l'événement
- **Catégorie** : `GLPI`

---

## Tables SQL créées

| Table | Contenu |
|-------|---------|
| `glpi_plugin_outlookcal_configs` | Configuration clé/valeur du plugin |
| `glpi_plugin_outlookcal_mappings` | Correspondance GLPI ↔ Outlook (event ID) |

---

## Logs

- **Interface** : rapport du dernier cron visible dans la page de configuration
- **Fichier** : `/tmp/outlookcal_cron.log`
- **Log GLPI** : `files/_log/php-errors.log`

---

## Dépannage

| Symptôme | Cause probable | Solution |
|----------|---------------|----------|
| `Token Azure KO` | Credentials incorrects | Vérifier Tenant ID, Client ID, Secret |
| `SKIP user X : email vide` | Email GLPI non renseigné | Renseigner l'email sur le compte GLPI ou configurer le Domaine UPN |
| `aucun technicien trouve` | Tâche sans technicien assigné | Assigner un technicien à la tâche ou au ticket |
| Événement non créé malgré sync OK | Permission Entra manquante | Ajouter `Calendars.ReadWrite` (Application) + admin consent |
| Cron ne tourne pas | runcron.php non exécuté | Vérifier `crontab -l` et `/tmp/outlookcal_cron.log` |

**Réinitialiser le mapping** (bouton rouge dans la config) force la recréation de tous les événements Outlook au prochain cron.

---

## Auteur

**CharlyTissot**  
Plugin sous licence GPL v2