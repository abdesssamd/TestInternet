# Installation — Intranet Monitor Pro

## 1. Prérequis serveur

- XAMPP (Apache + PHP 8.2+ + MariaDB/MySQL) déjà installé, ou équivalent
- Extensions PHP requises : `pdo_mysql`, `openssl`, `mbstring`, `json` (activées par défaut sur XAMPP)
- Le projet doit être placé dans `htdocs/MONITOR` (ex : `E:\xamp8.1\htdocs\MONITOR`)
- Le schéma est compatible MariaDB 10.1+ (les colonnes de détails d'événements/alertes utilisent `TEXT` avec du JSON encodé, plutôt que le type natif `JSON`, absent des versions de MariaDB antérieures à 10.2)

Vérifier les extensions :
```powershell
php -m | findstr /i "pdo_mysql openssl mbstring json"
```

## 2. Base de données

1. Démarrer Apache et MySQL depuis le panneau XAMPP.
2. Vérifier le port MySQL réellement utilisé (certaines installations XAMPP modifiées écoutent sur un port différent de 3306) :
   ```powershell
   netstat -ano | findstr LISTENING | findstr mysqld
   ```
3. Importer le schéma avec les identifiants de cette instance (adapter host/port/utilisateur/mot de passe) :
   ```powershell
   & "E:\xamp8.1\mysql\bin\mysql.exe" -u <utilisateur> -p<mot_de_passe> -h 127.0.0.1 --port=<port> < "E:\xamp8.1\htdocs\MONITOR\database\schema.sql"
   ```
   ou via phpMyAdmin (`http://localhost/phpmyadmin`) > Importer > sélectionner `database/schema.sql`.
4. Le script crée la base `intranet_monitor`, toutes les tables, et un compte admin par défaut.

## 3. Configuration serveur

Éditer `server/config/config.php` selon l'environnement : `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`, fuseau horaire (`APP_TIMEZONE`). Sur cette instance, la configuration validée est `DB_HOST=127.0.0.1`, `DB_PORT=3307`, avec un compte MySQL dédié (ne pas utiliser un compte root partagé avec d'autres bases de production). Le fichier `config/database.php` aligne automatiquement le fuseau horaire de la session MySQL sur celui de PHP (`APP_TIMEZONE`) pour éviter tout décalage entre les timestamps SQL et PHP.

## 4. Accès au dashboard

1. Ouvrir `http://localhost/MONITOR/server/dashboard/login.php`
2. Se connecter avec :
   - Identifiant : `admin`
   - Mot de passe : `ChangeMoi!2026`
3. **Changer immédiatement ce mot de passe** depuis **Mon compte** (`http://localhost/MONITOR/server/dashboard/account.php`), accessible dans le menu latéral ou en cliquant sur votre nom d'utilisateur en haut à droite. Le formulaire demande le mot de passe actuel et impose 10 caractères minimum.

## 5. Enregistrer un poste (créer un agent)

1. Dans le dashboard, aller sur **Réglages**.
2. Saisir le nom du poste (ex : `PC-045`) et cliquer sur **Créer**.
3. Le token généré s'affiche **une seule fois** : le copier immédiatement.

## 6. Installation de l'agent sur un poste Windows

Ouvrir PowerShell **en administrateur** sur le poste cible :

```powershell
cd \\SERVEUR\partage\MONITOR\agent    # ou copier le dossier agent/ localement
.\install.ps1 -ServerUrl "http://192.168.1.10/MONITOR/server/api/report.php" `
              -AgentToken "TOKEN_COPIE_DEPUIS_LE_DASHBOARD" `
              -IntervalSeconds 60
```

Le script :
- crée `C:\ProgramData\IntranetMonitor`
- copie `agent.ps1` et génère `config.ps1` avec les valeurs fournies
- installe une tâche planifiée `IntranetMonitorAgent` (exécution SYSTEM, toutes les 60s, redémarre en cas d'échec)
- envoie un premier rapport de test

Résultat attendu :
```
=====================================
 INTRANET MONITOR PRO
 Installation Agent
=====================================

[OK] Dossier cree (C:\ProgramData\IntranetMonitor)
[OK] Fichiers copies
[OK] Configuration ecrite
[OK] Tache planifiee installee
[OK] Connexion serveur verifiee
[OK] Premier rapport envoye (voir agent.log pour le detail)

Installation terminee.
```

Vérifier ensuite dans le dashboard (`/devices`) que le poste apparaît avec son statut.

## 7. Test de l'agent

```powershell
cd C:\ProgramData\IntranetMonitor
& "E:\xamp8.1\htdocs\MONITOR\agent\test-agent.ps1" -ConfigPath ".\config.ps1"
```

Sortie attendue :
```
POWERSHELL      OK
LAN             OK
DNS             OK
INTERNET        OK
SERVER          OK
API             OK
AUTHENTICATION  OK
JSON            OK
REPORT          OK
```

## 8. Désinstallation d'un agent

```powershell
cd C:\ProgramData\IntranetMonitor
.\uninstall.ps1
```
Supprime la tâche planifiée et le dossier `C:\ProgramData\IntranetMonitor` après confirmation.

## 9. Désactiver un agent sans le désinstaller

Dans le dashboard > Réglages > bouton **Désactiver** en face du poste concerné. L'API rejettera alors ses rapports (HTTP 401) tant qu'il n'est pas réactivé.

## 9 bis. Couper l'accès Internet d'un poste à distance

Depuis le dashboard > **Postes** > cliquer sur un poste > carte **Accès Internet** > bouton **Couper Internet**.

**Ce qui est coupé :** uniquement l'accès Internet. Le poste **reste joignable sur le réseau local** (partages, imprimantes, serveur MONITOR). C'est volontaire : si on désactivait la carte réseau, l'agent ne pourrait plus recevoir l'ordre de rétablissement et il faudrait se déplacer physiquement.

**Comment ça marche :** l'agent n'écoute sur aucun port. La consigne est transmise dans la **réponse HTTP au rapport** que l'agent envoie déjà toutes les 60 s (champ `commands.block_internet`). L'agent pose alors deux règles de pare-feu Windows :
- une règle *Allow* sortante pour les plages privées (`10/8`, `172.16/12`, `192.168/16`, loopback, multicast) ;
- une règle *Block* sortante pour tout le reste.

**Délai :** jusqu'à 1 minute (le temps du prochain rapport). L'interface affiche « Coupure en attente » tant que l'agent n'a pas confirmé, puis « Internet coupé » une fois la règle réellement posée.

**Prérequis :** l'agent doit tourner avec des **droits administrateur** (c'est le cas s'il a été installé via `install.ps1` en tâche planifiée SYSTEM). Sans ces droits, la règle ne peut pas être créée et l'échec est écrit dans le log de l'agent.

**Rétablissement :** même écran, bouton **Rétablir Internet**. Les règles de pare-feu sont supprimées.

**Traçabilité :** chaque demande et chaque application sont journalisées dans les événements du poste (`INTERNET_BLOCK_REQUESTED`, `INTERNET_BLOCK_APPLIED`, `INTERNET_UNBLOCK_REQUESTED`, `INTERNET_BLOCK_RELEASED`), avec le nom de l'utilisateur dashboard à l'origine de l'action.

**Migration à appliquer** (base déjà installée) :
```powershell
& "E:\xamp8.1\mysql\bin\mysql.exe" -u <utilisateur> -p -h 127.0.0.1 --port=3307 intranet_monitor < "E:\xamp8.1\htdocs\MONITOR\database\migration_internet_block.sql"
```

### Protection du dossier de l'agent

Le dossier `C:\ProgramData\IntranetMonitor` contient `agent.ps1` et `config.ps1` (qui contient le token en clair). Par défaut, `C:\ProgramData` accorde un droit d'**écriture au groupe Utilisateurs**, hérité par les sous-dossiers : un utilisateur standard pourrait donc lire le token ou modifier l'agent.

`install.ps1` **coupe désormais cet héritage** et ne laisse que `SYSTEM` et `Administrateurs` (contrôle total). Un utilisateur standard ne peut plus ni lire, ni modifier, ni supprimer le contenu du dossier.

Pour vérifier sur un poste existant :
```powershell
(Get-Acl "C:\ProgramData\IntranetMonitor").Access |
    Format-Table IdentityReference, FileSystemRights, IsInherited
```
Seuls `AUTORITE NT\Système` et `BUILTIN\Administrateurs` doivent apparaître. Si d'autres comptes sont listés, le poste a été installé avec une version antérieure : relancer `install.ps1` pour appliquer le verrouillage.

### Détection de contournement

Le verrouillage ACL arrête un utilisateur standard, **pas un administrateur local** : celui-ci peut toujours reprendre la propriété du dossier, supprimer les règles de pare-feu ou modifier l'agent.

Le serveur ne fait donc pas confiance à ce que déclare l'agent : il **recoupe la déclaration avec la mesure**. Si un poste affirme appliquer la coupure alors que ses propres tests de connectivité réussissent, les deux ne peuvent pas être vrais simultanément — une alerte **critique** `INTERNET_BLOCK_BYPASSED` est levée (et notifiée par email si les notifications sont configurées). Même chose si l'agent cesse de remonter son état de blocage alors que la consigne est active.

Concrètement, un utilisateur qui débloque Internet sur son poste ne passe pas inaperçu : il apparaît en alerte critique dans le dashboard, avec l'horodatage.

> **Limite résiduelle :** un administrateur local déterminé peut modifier l'agent pour qu'il mente sur **les deux** champs à la fois (déclarer le blocage actif *et* déclarer Internet inaccessible). Le recoupement ci-dessus ne le détecte alors plus. Pour un blocage réellement opposable à un utilisateur admin de sa machine, il faut filtrer **en dehors du poste** : règle sur le routeur/pare-feu réseau, ou filtrage par adresse MAC/IP côté switch. Le mécanisme livré ici vise un parc d'utilisateurs standards, pas un adversaire disposant des droits admin.


## 10. Mise en place des fonctionnalités avancées (anomalies, appareils inconnus, notifications, disponibilité)

Ces 4 fonctionnalités s'ajoutent au socle déjà installé. Si vous avez suivi les étapes 1 à 9 ci-dessus avec la version actuelle de `database/schema.sql`, les tables et colonnes nécessaires existent déjà (rien à migrer). Il reste à les activer et à les configurer.

### 10.1 Vérifier le schéma

Si votre base a été créée avec une version antérieure du projet (avant l'ajout de `agent_baselines`, `known_ssids`, `lan_neighbors`, ou la colonne `alerts.notified_at`), ré-importez `database/schema.sql` : toutes les instructions utilisent `CREATE TABLE IF NOT EXISTS`/`ON DUPLICATE KEY UPDATE`, donc l'import est rejouable sans perte de données existantes. Seuls les `ALTER TABLE ... MODIFY COLUMN` sur les `ENUM` de `events`/`alerts` (ajout de nouvelles valeurs) doivent être exécutés manuellement si vous ne repartez pas d'un import complet :

```sql
ALTER TABLE `events` MODIFY COLUMN `type` ENUM(
    'AGENT_ONLINE','AGENT_OFFLINE','WIFI_CONNECTED','WIFI_DISCONNECTED',
    'INTERNET_ON','INTERNET_OFF','INTERFACE_ADDED','INTERFACE_REMOVED',
    'IP_CHANGED','GATEWAY_CHANGED','NETWORK_ANOMALY_DETECTED','GHOST_DEVICE_DETECTED'
) NOT NULL;

ALTER TABLE `alerts` MODIFY COLUMN `type` ENUM(
    'DOUBLE_CONNEXION','WIFI_ACTIVATED','INTERNET_DETECTED',
    'AGENT_UNREACHABLE','INTERFACE_ANOMALY','NETWORK_ANOMALY','GHOST_DEVICE'
) NOT NULL;

ALTER TABLE `alerts` ADD COLUMN `notified_at` DATETIME NULL AFTER `read_at`;
```

### 10.2 Générer la clé de chiffrement des secrets (mot de passe SMTP)

`server/config/config.php` contient une constante `SETTINGS_ENC_KEY` utilisée pour chiffrer le mot de passe SMTP stocké en base. Une clé est déjà générée par défaut, mais il est recommandé d'en générer une nouvelle propre à votre déploiement :

```powershell
& "E:\xamp8.1\php\php.exe" -r "echo bin2hex(random_bytes(32));"
```

Copier le résultat (64 caractères hexadécimaux) dans `define('SETTINGS_ENC_KEY', '...')`. **Ne jamais versionner/partager cette clé** : au même niveau de confidentialité que `DB_PASS`. Si elle est perdue ou changée après coup, le mot de passe SMTP déjà enregistré ne sera plus déchiffrable (il suffit de le ressaisir depuis la page Réglages).

### 10.3 Détection d'anomalies réseau

Rien à installer : activée par défaut (`anomaly_detection_enabled=1`). Chaque agent apprend automatiquement sa MAC attendue au premier contact — aucune configuration manuelle requise pour cette partie.

Pour affiner la détection, depuis le dashboard **Réglages** :
- **Sous-réseau attendu (CIDR)** : ex. `192.168.1.0/24`. Laisser vide pour désactiver la vérification IP hors sous-réseau (évite les faux positifs sur un parc avec DHCP multi-plages).
- **Réseaux Wi-Fi autorisés** : ajouter les SSID légitimes de l'entreprise. Tant que la liste est vide, la vérification SSID est désactivée (aucun faux positif au déploiement).

### 10.4 Notifications email (SMTP)

Depuis le dashboard **Réglages**, section "Notifications email (SMTP)" :
1. Renseigner le serveur SMTP (host, port, chiffrement TLS/SSL), les identifiants, l'expéditeur et les destinataires.
2. Cliquer sur **Envoyer un test** pour valider la configuration avant de l'activer réellement.
3. Cocher **Activer l'envoi d'emails**.

Le client SMTP est intégré (pas de dépendance externe, pas besoin d'un serveur mail local type sendmail — XAMPP/Windows n'en fournit pas par défaut). Compatible avec un relais SMTP classique (Gmail avec mot de passe d'application, Office 365, relais interne de l'entreprise).

Le throttle (délai minimum entre deux notifications pour un même poste/type d'alerte, 30 min par défaut) évite le spam en cas d'alerte répétée.

### 10.5 Détection des appareils non gérés (scan LAN)

Cette fonctionnalité s'appuie sur les agents déjà déployés : chaque agent scanne périodiquement sa table ARP locale (`Get-NetNeighbor`) et remonte les adresses MAC voisines au serveur, qui les compare aux postes connus.

**Pour les agents déjà installés avant cette mise à jour** : redéployer le fichier `agent.ps1` mis à jour (copier `agent/agent.ps1` par-dessus `C:\ProgramData\IntranetMonitor\agent.ps1` sur chaque poste). Le nouveau script reste compatible avec un `config.ps1` ancien (le scan LAN reste désactivé par défaut si les nouvelles variables sont absentes) — pour l'activer sur un poste déjà installé, ajouter dans son `config.ps1` :
```powershell
$NeighborsUrl             = "http://<IP_SERVEUR>/MONITOR/server/api/neighbors.php"
$GhostScanEnabled         = $true
$GhostScanIntervalMinutes = 15
$GhostScanStateFile       = "C:\ProgramData\IntranetMonitor\last_ghost_scan.txt"
```

**Pour les nouvelles installations** : `install.ps1` génère déjà ces variables automatiquement, rien à faire.

Aligner `$GhostScanIntervalMinutes` (côté agent) avec le réglage `ghost_scan_min_interval_seconds` (côté serveur, page Réglages) — il n'existe pas de mécanisme de poussée de configuration vers les agents dans cette version : les deux valeurs doivent être ajustées manuellement de façon cohérente.

Les appareils détectés sans agent apparaissent dans le dashboard, page **Appareils inconnus**. Un bouton "Écarter" permet de retirer durablement de la liste un appareil légitime (imprimante, box, etc.).

### 10.6 Rapport de disponibilité / SLA

Rien à configurer : disponible immédiatement une fois que des données `network_history` s'accumulent (chaque rapport agent en ajoute une ligne). Consultable dans le dashboard, page **Disponibilité** (vue globale + export CSV) et dans la fiche détail de chaque poste (`/devices/{id}`, carte "Disponibilité" 24h/7j/30j).

### 10.7 Tâche planifiée serveur pour la détection "agent hors ligne"

Sans cette tâche, un agent qui s'arrête de fonctionner ne déclenche jamais d'alerte (son statut reste simplement "dernier contact il y a longtemps" dans le dashboard, sans notification). Créer, **sur le serveur** (pas sur les postes clients), une tâche planifiée Windows :

```powershell
schtasks /Create /TN "IntranetMonitor-OfflineSweep" `
  /TR "\"E:\xamp8.1\php\php.exe\" \"E:\xamp8.1\htdocs\MONITOR\server\cron\offline_sweep.php\"" `
  /SC MINUTE /MO 2 /RU SYSTEM /F
```

Cette tâche s'exécute toutes les 2 minutes (plus court que le seuil `OFFLINE_THRESHOLD_SECONDS`=180s, pour une détection réactive), détecte les postes silencieux depuis plus de 3 minutes, émet un événement `AGENT_OFFLINE` et une alerte critique `AGENT_UNREACHABLE` (avec notification email si activée). Elle évite elle-même les doublons : un agent déjà signalé hors ligne ne redéclenche pas d'alerte tant qu'il n'a pas émis un nouveau rapport.

Vérifier manuellement :
```powershell
& "E:\xamp8.1\php\php.exe" "E:\xamp8.1\htdocs\MONITOR\server\cron\offline_sweep.php"
```

## Identifiants par défaut

| Élément | Valeur |
|---|---|
| Dashboard - identifiant | `admin` |
| Dashboard - mot de passe | `ChangeMoi!2026` |

## Pistes d'amélioration V2

- Gestion multi-utilisateurs dans le dashboard (création/suppression de comptes, rôles) — le changement de mot de passe du compte connecté est disponible depuis la page **Mon compte**
- Notifications Slack/Teams/Telegram en complément de l'email
- Export PDF des rapports (en plus du CSV déjà disponible pour la disponibilité)
- Carte réseau visuelle (topologie interactive), y compris les appareils non gérés détectés
- Agent Linux/macOS pour un parc hétérogène
- WebSocket (ou SSE) en remplacement du polling AJAX pour un rafraîchissement instantané
- Authentification agent par certificat client en plus du token
- Tableau de bord multi-sites / multi-VLAN
- Mécanisme de poussée de configuration serveur → agents (actuellement chaque poste doit être configuré manuellement) — un canal de commandes descendant existe déjà pour la coupure Internet, il peut servir de base
- Agrégation quotidienne + purge de `network_history` si le volume devient important sur un grand parc
