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

## 4 bis. Installation du serveur par l'assistant web (recommandé)

Au lieu d'éditer `config.php` et d'importer le schéma à la main, ouvrez :

```
http://localhost/MONITOR/install.php
```

L'assistant vérifie les prérequis (PHP, extensions, droits d'écriture), demande les accès MySQL et le compte administrateur, puis **crée la base, applique le schéma et toutes les migrations, et génère `server/config/config.php`** (avec une clé de chiffrement neuve).

L'assistant se **verrouille automatiquement** une fois l'installation terminée. **Supprimez ensuite `install.php`** : un rappel s'affiche à la dernière étape.

## 5. Enregistrer un poste (créer un agent)

> **Le système de token a été supprimé.** Il n'y a plus rien à créer à l'avance.

Un poste qui contacte le serveur pour la première fois est **enregistré automatiquement** (il s'identifie par son nom de machine, en-tête `X-Agent-Host`) et placé **sous surveillance par défaut**. C'est l'état sur lequel on préfère se tromper : un poste inconnu est supervisé jusqu'à décision contraire.

Pour retirer un poste de la surveillance : dashboard > **Postes** > le poste concerné. Seul un administrateur peut le faire, et l'action est journalisée (`MONITORING_ENABLED` / `MONITORING_DISABLED`) avec son nom.

L'ancien mode par token reste accepté pour les postes déjà déployés : si un `X-Agent-Token` valide est envoyé, il est utilisé en priorité.

## 6. Installation de l'agent sur un poste Windows

### Méthode simple : double-clic (recommandée)

1. Copier le dossier `agent/` sur le poste (partage réseau ou clé USB).
2. Ouvrir `server.txt` et **remplacer son contenu par l'adresse réelle de votre serveur** (ex : `100.10.1.136`). Le fichier livré contient un texte à remplacer : l'installation s'arrête tant que ce n'est pas fait.
3. **Double-cliquer sur `INSTALLER.bat`**, puis accepter l'élévation Windows (UAC).

C'est tout : aucune commande à taper, aucun token à copier.

`INSTALLER.bat` règle les deux blocages classiques :
- **ExecutionPolicy** — PowerShell refuse les `.ps1` par défaut (« l'exécution de scripts est désactivée sur ce système »). Le `.bat` lance PowerShell avec `-ExecutionPolicy Bypass`, sans modifier le réglage de la machine.
- **Droits administrateur** — le script s'auto-élève via UAC s'il n'est pas déjà lancé en admin.

L'adresse du serveur est tolérante à la saisie : `192.168.1.10`, `192.168.1.10/MONITOR` ou l'URL complète de `report.php` fonctionnent toutes — le script reconstruit l'URL correcte.

### Méthode manuelle (PowerShell)

```powershell
cd \\SERVEUR\partage\MONITOR\agent    # ou copier le dossier agent/ localement
powershell -ExecutionPolicy Bypass -File .\install.ps1 -ServerUrl "100.10.1.136"
```

Le paramètre `-AgentToken` n'est plus nécessaire (enregistrement automatique). Il reste accepté pour réinstaller un poste avec son ancien token.

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
[OK] Permissions verrouillees (SYSTEM + Administrateurs uniquement)
[OK] Fichiers copies

  Serveur cible : http://100.10.1.136/MONITOR/server/api/report.php

[OK] Configuration ecrite
[OK] Tache planifiee installee
[OK] Connexion au serveur verifiee (http://100.10.1.136/MONITOR/server/api/report.php)
[OK] Premier rapport envoye (voir agent.log pour le detail)

Installation terminee.
Serveur    : http://100.10.1.136/MONITOR/server/api/report.php
Log agent  : C:\ProgramData\IntranetMonitor\agent.log
```

Si le serveur est injoignable, l'installation se termine quand même (l'agent réessaiera) mais l'avertit explicitement :
```
[FAIL] Connexion au serveur
         URL testee : http://100.10.1.136/MONITOR/server/api/report.php
         Le délai d'attente de l'opération a expiré.
```

Vérifier ensuite dans le dashboard (`/devices`) que le poste apparaît avec son statut.

## 6 bis. L'agent n'atteint pas le serveur (timeouts dans agent.log)

Symptôme dans `C:\ProgramData\IntranetMonitor\agent.log` :

```
[ERROR] Echec envoi rapport (HTTP ) : Le délai d'attente de l'opération a expiré.
[ERROR] Echec definitif apres 3 tentatives.
```

Un `HTTP` vide (sans code) signifie que **le serveur n'a jamais répondu** : ce n'est pas un refus, c'est une adresse injoignable.

**Vérifier l'adresse réellement enregistrée sur le poste :**
```powershell
Select-String -Path "C:\ProgramData\IntranetMonitor\config.ps1" -Pattern 'ServerUrl'
```

**Cause la plus fréquente :** `config.ps1` contient encore une ancienne adresse. Modifier `server.txt` *après* une première installation ne change rien : `server.txt` n'est lu qu'au moment de l'installation et n'est pas copié sur le poste.

**Corriger sans réinstaller :**
```powershell
powershell -ExecutionPolicy Bypass -File .\set-server.ps1 -ServerUrl "100.10.1.136"
```
Le script affiche l'ancienne adresse, teste la nouvelle avant de l'écrire, met à jour `config.ps1` et relance l'agent.

**Autres causes possibles :**
- Apache arrêté sur le serveur → `http://<adresse>/MONITOR/` depuis un navigateur du poste.
- Pare-feu du serveur bloquant le port entrant.
- Poste sur un autre VLAN / sous-réseau que le serveur.
- **Port non standard oublié** : si le serveur écoute sur un autre port que 80, il doit figurer dans l'adresse (ex : `http://100.10.1.136:30/MONITOR/`). Le port est conservé par le script.

### Erreur HTTP 500 sur report.php

Différent d'un timeout : ici le serveur **répond**, mais échoue. Cause quasi systématique après une mise à jour du code : **les migrations de base n'ont pas été appliquées sur ce serveur**. Le code écrit dans des colonnes qui n'existent pas encore.

```powershell
php E:\xamp8.1\htdocs\MONITOR\database\migrate.php
```

Le script applique toutes les migrations manquantes, ignore celles déjà en place (relançable sans risque) et vérifie à la fin que le schéma est complet.

Depuis cette version, l'API renvoie un message explicite dans ce cas plutôt qu'un 500 muet :
```json
{"error":"Schema de base incomplet","hint":"Appliquez les migrations : database/migration_*.sql"}
```

### Erreur HTTP 401 sur neighbors.php

Corrigé : `neighbors.php` exigeait encore un token alors que `report.php` était déjà passé à l'enregistrement automatique. Mettre à jour les fichiers du serveur suffit.

> Depuis cette version, `install.ps1` **teste la connexion au serveur pendant l'installation** et affiche l'URL retenue. Une adresse injoignable est signalée immédiatement, au lieu d'être découverte plus tard dans les logs. L'installation refuse aussi de démarrer si `server.txt` contient encore la valeur d'exemple.

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

Dans le dashboard > Réglages > bouton **Désactiver** en face du poste concerné. L'API rejettera alors ses rapports (HTTP 403 « Poste desactive par un administrateur ») tant qu'il n'est pas réactivé.

À ne pas confondre avec la **surveillance** (section 5) : un poste désactivé n'est plus accepté du tout par l'API, alors qu'un poste hors surveillance continue d'être joignable mais n'est plus supervisé.

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

## Sécurité web (fichiers .htaccess)

Des fichiers `.htaccess` protègent l'installation. Ils nécessitent `AllowOverride All` côté Apache (valeur par défaut sous XAMPP) et les modules `mod_rewrite`, `mod_headers`, `mod_authz_core`.

**Fichiers rendus inaccessibles en HTTP** (réponse `403`) :

| Emplacement | Raison |
|---|---|
| `server/config/` | contient `config.php` : mot de passe MySQL et clé de chiffrement |
| `database/` | schéma et migrations SQL (structure de la base) |
| `agent/` | `config.ps1` contient l'adresse du serveur et, le cas échéant, un token |
| `server/models/`, `services/`, `middleware/`, `controllers/`, `cron/` | code interne, inclus par PHP mais jamais téléchargeable |
| `*.sql`, `*.log`, `*.ps1`, `*.bat`, `*.md`, `*.bak`, fichiers commençant par `.` | blocage global par extension |

Restent accessibles, comme il se doit : `server/api/` (les agents en ont besoin), `server/dashboard/`, `server/assets/` et `install.php`.

**Redirections mises en place** :

| Adresse | Destination |
|---|---|
| `/MONITOR/` | `install.php` si l'application n'est pas installée, sinon la page de connexion |
| `/MONITOR/dashboard` | `server/dashboard/index.php` |
| `/MONITOR/login` | `server/dashboard/login.php` |

**En-têtes de sécurité** ajoutés : `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: same-origin`. Le listing de répertoire est désactivé (`Options -Indexes`).

Pour vérifier que la protection est active :
```powershell
curl.exe -s -o NUL -w "%{http_code}`n" http://localhost/MONITOR/server/config/config.php   # doit afficher 403
curl.exe -s -o NUL -w "%{http_code}`n" http://localhost/MONITOR/server/dashboard/login.php # doit afficher 200
```

> Si ces commandes renvoient `200` sur le premier test, `AllowOverride` est probablement à `None` dans la configuration Apache : les `.htaccess` sont alors ignorés.

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
