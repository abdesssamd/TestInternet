# Intranet Monitor Pro

Console de supervision réseau local : suivi en temps réel des postes Windows d'un LAN, de leurs interfaces réseau (Ethernet/Wi-Fi), de leur accès Internet réel, des doubles connexions à risque, avec historique d'événements et système d'alertes.

## Architecture

```
                     INTERNET
                        │
                 ┌──────┴──────┐
                 │   ROUTEUR   │
                 └──────┬──────┘
                        │
                     LAN
                        │
              ┌─────────┴─────────┐
              │ Serveur PHP/MySQL │
              │ Intranet Monitor  │
              └─────────┬─────────┘
                        │
          ┌─────────────┼─────────────┐
          │             │             │
       PC-001         PC-002        PC-003
       Agent          Agent         Agent
```

Chaque agent Windows (PowerShell) communique exclusivement avec l'API REST PHP via HTTPS/HTTP, authentifié par un token unique par poste. Le serveur PHP/MySQL stocke l'état de chaque poste, génère les événements de changement et les alertes, et les expose au dashboard via des appels AJAX (pas de rechargement de page).

## Stack

- **Backend** : PHP 8.2+, PDO, MySQL/MariaDB, architecture MVC légère
- **Frontend** : Bootstrap 5, Chart.js, Bootstrap Icons (vendorisés localement, pas de dépendance CDN)
- **Agent** : PowerShell 5.1+ (Windows 10/11/Server), aucune dépendance Node.js

## Fonctionnalités

- Détection Internet fiable par triple test (HTTPS + DNS + TCP), pas juste "passerelle présente"
- Détection Ethernet/Wi-Fi avec SSID, IP, MAC, passerelle par interface
- Détection de double connexion réseau simultanée (ex : LAN entreprise + Wi-Fi/4G vers Internet)
- **Détection d'anomalies réseau** : MAC inattendue (usurpation), IP hors sous-réseau attendu, connexion à un SSID Wi-Fi non autorisé — baseline auto-apprise par poste
- **Détection d'appareils non gérés** : scan ARP périodique par les agents, repère les appareils présents sur le LAN sans agent installé
- **Notifications email** pour les alertes critiques (client SMTP intégré, sans dépendance externe)
- **Rapport de disponibilité/SLA** par poste (24h/7j/30j) avec export CSV
- Détection automatique des agents devenus injoignables (tâche planifiée serveur)
- Historique des changements réseau (IP, passerelle, interfaces, Wi-Fi, Internet)
- Système d'alertes avec accusé de lecture
- Dashboard responsive avec mode sombre, recherche, filtres, tri, pagination
- Sécurité : prepared statements, tokens agents hashés, CSRF, rate limiting, sessions sécurisées, secrets SMTP chiffrés (AES-256-GCM)

## Structure du projet

```
MONITOR/
├── server/        Application PHP (MVC) + dashboard web
├── agent/         Agent PowerShell + scripts d'installation
├── database/       Schéma SQL
├── docs/          Documentation complémentaire
├── tests/         Scripts de test
├── README.md
└── INSTALLATION.md
```

## Démarrage rapide

Voir [INSTALLATION.md](INSTALLATION.md) pour la procédure complète (serveur + agent).

## Identifiants par défaut

- Dashboard : `admin` / `ChangeMoi!2026` (à changer immédiatement après la première connexion)

## Pistes d'évolution (V2)

Voir la section correspondante dans [INSTALLATION.md](INSTALLATION.md).
