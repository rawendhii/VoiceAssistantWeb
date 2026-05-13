# Voice Assistant Web — Assistant vocal pour l’accessibilité numérique

Voice Assistant Web est une application web développée avec **Symfony 6.4**, **Twig**, **MySQL** et **JavaScript**, conçue pour améliorer l’accessibilité numérique des personnes en situation de handicap. Le projet permet à l’utilisateur de contrôler l’application, certaines pages web et des actions courantes grâce à la voix, afin de réduire la dépendance au clavier et à la souris.

Ce projet a été réalisé dans un contexte académique à **Esprit School of Engineering**. Il met en pratique le développement web, la sécurité applicative, la gestion de données, l’intégration d’API externes et les principes d’accessibilité.

## Description courte du dépôt

Application Symfony d’assistant vocal pour l’accessibilité numérique, destinée aux personnes en situation de handicap moteur ou visuel, avec commandes vocales, authentification, espace utilisateur, back-office administrateur, extension Chrome, intégration Gemini et Gmail.

## Topics GitHub recommandés

`symfony` `php` `twig` `mysql` `doctrine-orm` `web-speech-api` `voice-assistant` `accessibility` `assistive-technology` `disability-support` `chrome-extension` `gemini-api` `gmail-api` `webauthn`

## Table des matières

- [Objectif du projet](#objectif-du-projet)
- [Problématique](#problématique)
- [Fonctionnalités principales](#fonctionnalités-principales)
- [Fonctionnalités d’accessibilité](#fonctionnalités-daccessibilité)
- [Architecture du projet](#architecture-du-projet)
- [Technologies utilisées](#technologies-utilisées)
- [Structure du projet](#structure-du-projet)
- [Prérequis](#prérequis)
- [Installation](#installation)
- [Configuration](#configuration)
- [Base de données](#base-de-données)
- [Lancement du projet](#lancement-du-projet)
- [Extension Chrome](#extension-chrome)
- [Utilisation](#utilisation)
- [API principales](#api-principales)
- [Tests](#tests)
- [Sécurité](#sécurité)
- [Contribution](#contribution)
- [Auteur](#auteur)
- [Licence](#licence)
- [Remerciements](#remerciements)

## Objectif du projet

L’objectif de Voice Assistant Web est de proposer une interface web plus accessible aux personnes qui rencontrent des difficultés à utiliser un ordinateur de manière classique. L’application vise principalement les utilisateurs ayant une mobilité réduite, une incapacité partielle ou totale à utiliser leurs mains, ou des besoins d’assistance liés à la navigation numérique.

Le système permet de piloter des fonctionnalités par commande vocale, comme ouvrir une page, accéder au profil, consulter les fichiers, contrôler une page web, lancer une recherche, lire ou résumer une page, ou encore préparer l’envoi d’un email.

## Problématique

De nombreuses interfaces web restent difficiles à utiliser pour les personnes en situation de handicap, notamment lorsque l’expérience dépend fortement de la souris, du clavier ou d’une navigation complexe. Voice Assistant Web répond à ce problème en proposant une couche d’interaction vocale capable de simplifier l’accès aux fonctionnalités essentielles.

Le projet cherche donc à renforcer l’autonomie numérique des utilisateurs grâce à une application web assistive, sécurisée et extensible.

## Fonctionnalités principales

### Espace utilisateur

- Inscription, connexion et déconnexion.
- Gestion du profil personnel.
- Modification du mot de passe.
- Suppression du compte depuis l’espace utilisateur.
- Consultation des commandes vocales disponibles.
- Consultation des fichiers associés à l’utilisateur.

### Back-office administrateur

- Tableau de bord administrateur.
- Gestion des utilisateurs.
- Blocage et déblocage des comptes utilisateurs.
- Gestion des rôles.
- Gestion des commandes vocales.
- Gestion des fichiers.
- Consultation et suppression de l’historique des commandes exécutées.

### Commandes vocales

- Reconnaissance vocale côté navigateur.
- Interprétation des phrases utilisateur.
- Navigation dans l’application par la voix.
- Exécution de commandes locales fiables sans dépendre uniquement de l’IA.
- Utilisation de Gemini pour comprendre des commandes plus naturelles.
- Réponse vocale ou textuelle adaptée à l’action demandée.

### Contrôle du navigateur via extension Chrome

- Ouverture de sites comme Google, YouTube, Facebook ou Gmail.
- Recherche vocale sur des sites pris en charge.
- Clic sur un élément ou un résultat.
- Saisie automatique dans un champ actif.
- Défilement vers le haut ou vers le bas.
- Retour ou avance dans l’historique du navigateur.
- Lecture, pause, mute, unmute et gestion du volume des vidéos.
- Lecture ou résumé d’une page.
- Retour vers l’onglet de l’assistant.

### Email et Gmail

- Connexion à Gmail via Google OAuth.
- Préparation d’un email par commande vocale.
- Confirmation avant envoi.
- Modification du destinataire ou du message avant envoi.
- Annulation d’un email préparé.

### Authentification avancée

- Authentification classique email/mot de passe.
- Gestion des rôles `USER` et `ADMIN`.
- Support WebAuthn / Passkeys.
- Connexion par caméra via profil facial.
- Réinitialisation de mot de passe.

## Fonctionnalités d’accessibilité

Voice Assistant Web est pensé comme un outil d’assistance pour les personnes en situation de handicap. Les choix fonctionnels du projet favorisent l’autonomie, la simplicité et la réduction des interactions physiques nécessaires.

- Interaction vocale pour limiter l’usage de la souris et du clavier.
- Navigation simplifiée entre les pages principales.
- Interface claire pour réduire la charge cognitive.
- Actions vocales directes : ouvrir le profil, afficher les fichiers, se déconnecter, connecter Gmail, etc.
- Contrôle vocal du navigateur grâce à une extension Chrome.
- Possibilité de lire ou résumer une page pour faciliter l’accès à l’information.
- Préparation d’emails à la voix, avec confirmation avant envoi pour éviter les erreurs.
- Structure extensible vers une future version desktop, notamment JavaFX.

## Architecture du projet

L’application est organisée en deux espaces principaux.

### Front Office

L’espace utilisateur permet aux personnes connectées d’utiliser l’assistant vocal, de gérer leur profil, de consulter leurs fichiers et d’accéder aux commandes vocales disponibles.

### Back Office

L’espace administrateur permet de gérer les utilisateurs, les rôles, les fichiers, les commandes vocales et l’historique des commandes exécutées.

### API et services

Le projet expose plusieurs endpoints API pour permettre l’interprétation vocale, l’intégration avec l’extension Chrome, la communication avec Gemini, la gestion Gmail et la compatibilité avec une future application desktop.

## Technologies utilisées

### Backend

- PHP 8.1+
- Symfony 6.4
- Doctrine ORM
- Doctrine Migrations
- Symfony Security
- Symfony Mailer
- Symfony Reset Password Bundle
- WebAuthn Symfony Bundle
- Google API Client

### Frontend

- Twig
- HTML5
- CSS3
- JavaScript
- Stimulus
- Turbo
- Symfony AssetMapper
- Web Speech API

### Base de données

- MySQL

### Intelligence artificielle et services externes

- Gemini API
- Gmail API
- Google OAuth

### Navigateur

- Extension Chrome Manifest V3
- Content scripts
- Background service worker

## Structure du projet

```text
voice-assistant-web/
├── assets/                     # Fichiers JavaScript, CSS et contrôleurs Stimulus
├── bin/                        # Console Symfony
├── chrome-extension/           # Extension Chrome pour contrôler les pages web
├── config/                     # Configuration Symfony
├── migrations/                 # Migrations Doctrine
├── public/                     # Point d’entrée public de l’application
├── src/
│   ├── Command/                # Commandes Symfony CLI
│   ├── Controller/             # Contrôleurs web et API
│   ├── Entity/                 # Entités Doctrine
│   ├── Form/                   # Formulaires Symfony
│   ├── Repository/             # Repositories Doctrine
│   ├── Security/               # Authentification et sécurité
│   └── Service/                # Services métier
├── templates/                  # Templates Twig Front Office et Back Office
├── tests/                      # Tests automatisés
├── translations/               # Fichiers de traduction
├── composer.json               # Dépendances PHP
└── README.md                   # Documentation du projet
```

## Prérequis

Avant d’installer le projet, il faut disposer de :

- PHP 8.1 ou version supérieure.
- Composer.
- MySQL ou MariaDB.
- Symfony CLI, recommandé mais non obligatoire.
- Un navigateur compatible avec la Web Speech API, de préférence Google Chrome.
- Une clé API Gemini si les fonctionnalités IA sont utilisées.
- Des identifiants Google OAuth si l’intégration Gmail est utilisée.

## Installation

1. Cloner le repository :

```bash
git clone https://github.com/rawendhii/VoiceAssistantWeb
cd VoiceAssistantWeb
```

2. Installer les dépendances PHP :

```bash
composer install
```

3. Créer un fichier de configuration local :

```bash
cp .env .env.local
```

4. Adapter les variables d’environnement dans `.env.local` selon votre environnement local.

## Configuration

Exemple de configuration à adapter dans `.env.local` :

```env
APP_ENV=dev
APP_SECRET=change_me
DATABASE_URL="mysql://user:password@127.0.0.1:3306/voice_assistant_web?serverVersion=8.0&charset=utf8mb4"
MAILER_DSN=null://null
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0
DEFAULT_URI=https://127.0.0.1:8000
GEMINI_API_KEY=your_gemini_api_key
GEMINI_MODEL=gemini-1.5-flash
GOOGLE_CLIENT_ID=your_google_client_id
GOOGLE_CLIENT_SECRET=your_google_client_secret
GOOGLE_REDIRECT_URI=https://127.0.0.1:8000/google/oauth/callback
RELYING_PARTY_NAME=Voice Assistant Web
RELYING_PARTY_ID=127.0.0.1
```

Les valeurs sensibles comme les mots de passe, clés API et secrets OAuth ne doivent jamais être publiées dans le repository.

## Base de données

1. Créer la base de données :

```bash
php bin/console doctrine:database:create
```

2. Exécuter les migrations :

```bash
php bin/console doctrine:migrations:migrate
```

3. Créer un premier utilisateur avec un rôle :

```bash
php bin/console app:create-user
```

La commande permet de saisir le nom complet, l’email, le mot de passe et le rôle de l’utilisateur (`USER` ou `ADMIN`).

## Lancement du projet

Avec Symfony CLI :

```bash
symfony server:start
```

Ou avec le serveur PHP intégré :

```bash
php -S 127.0.0.1:8000 -t public
```

Ensuite, ouvrir l’application dans le navigateur :

```text
https://127.0.0.1:8000
```

ou :

```text
http://127.0.0.1:8000
```

selon la configuration utilisée.

## Extension Chrome

Le dossier `chrome-extension/` contient une extension Chrome Manifest V3 utilisée pour contrôler certaines pages web à partir de l’assistant vocal.

### Installation de l’extension

1. Ouvrir Google Chrome.
2. Aller dans `chrome://extensions/`.
3. Activer le mode développeur.
4. Cliquer sur **Load unpacked** ou **Charger l’extension non empaquetée**.
5. Sélectionner le dossier `chrome-extension/`.
6. Lancer l’application Symfony et vérifier que l’extension communique avec la page de l’assistant.

### Sites pris en charge

- Application locale Voice Assistant Web.
- Google.
- YouTube.
- Facebook.
- Gmail.

## Utilisation

Après connexion, l’utilisateur peut utiliser l’assistant vocal pour exécuter des actions simples. Exemples de commandes :

```text
Open home
Open my profile
Open my files
Open voice commands
Connect Gmail
Log out
Search cats on YouTube
Open Google
Scroll down
Scroll up
Go back
Play video
Pause video
Mute
Unmute
Read this page
Summarize this page
Send an email to example@gmail.com saying hello
```

L’assistant privilégie d’abord les commandes locales fiables. Si la commande est plus naturelle ou plus complexe, Gemini peut être utilisé pour générer un plan d’action, qui est ensuite validé côté Symfony avant exécution.

## API principales

| Endpoint | Méthode | Description |
|---|---:|---|
| `/api/voice/interpret` | POST | Interprète une commande vocale et retourne l’action à exécuter. |
| `/api/gemini/assistant` | POST | Interprétation IA orientée assistant. |
| `/api/auth/login` | POST | Connexion API. |
| `/api/auth/register` | POST | Inscription API. |
| `/api/auth/me` | GET | Retourne l’utilisateur connecté. |
| `/api/desktop/ping` | GET | Vérifie la disponibilité de l’API desktop. |
| `/api/desktop/interpret` | POST | Interprète une commande pour un futur client desktop. |
| `/desktop/speech-bridge` | GET | Page de pont vocal pour intégration desktop. |
| `/api/desktop/speech/push` | POST | Envoie du texte vocal vers le pont desktop. |
| `/api/desktop/speech/latest` | GET | Récupère la dernière commande vocale. |
| `/api/desktop/speech/clear` | POST | Efface la commande vocale stockée. |

## Modèle de données

Le projet utilise plusieurs entités Doctrine :

- `User` : utilisateur de l’application.
- `Role` : rôle associé à l’utilisateur.
- `ManagedFile` : fichier lié à un utilisateur.
- `VoiceCommand` : commande vocale configurable.
- `CommandHistory` : historique des commandes exécutées.
- `FaceLoginProfile` : profil facial pour connexion par caméra.
- `ResetPasswordRequest` : demande de réinitialisation de mot de passe.
- `WebauthnCredential` : identifiant WebAuthn / Passkey.

Relations principales :

- Un rôle peut être associé à plusieurs utilisateurs.
- Un utilisateur appartient à un rôle.
- Un fichier est associé à un utilisateur.
- Un historique de commande est associé à un utilisateur et éventuellement à une commande vocale.
- Un profil facial est associé à un utilisateur.

## Tests

Pour lancer les tests :

```bash
php bin/phpunit
```

ou :

```bash
vendor/bin/phpunit
```

## Sécurité

Le projet intègre plusieurs mécanismes de sécurité :

- Authentification par email et mot de passe.
- Hashage des mots de passe via Symfony Security.
- Contrôle d’accès selon les rôles.
- Protection CSRF sur les actions sensibles.
- Réinitialisation sécurisée du mot de passe.
- Support WebAuthn / Passkeys.
- Validation côté serveur des actions proposées par l’IA avant exécution.
- Confirmation obligatoire avant l’envoi d’un email vocal.

## Contribution

Les contributions sont les bienvenues pour améliorer l’accessibilité, la sécurité et les fonctionnalités vocales du projet.

1. Forker le projet.
2. Créer une branche :

```bash
git checkout -b feature/nom-fonctionnalite
```

3. Ajouter les modifications :

```bash
git add .
```

4. Valider les modifications :

```bash
git commit -m "Ajouter une nouvelle fonctionnalité"
```

5. Pousser la branche :

```bash
git push origin feature/nom-fonctionnalite
```

6. Créer une Pull Request.

## Pistes d’amélioration

- Améliorer la compatibilité avec les lecteurs d’écran.
- Ajouter plus de langues pour les commandes vocales.
- Ajouter un mode contraste élevé configurable.
- Ajouter des raccourcis vocaux personnalisables par utilisateur.
- Renforcer les tests automatisés.
- Ajouter une documentation API complète.
- Préparer une version desktop JavaFX connectée aux endpoints existants.

## Auteur

Projet développé dans un cadre académique à **Esprit School of Engineering**.

Nom de l’étudiant : `À compléter`

Classe / Groupe : `À compléter`

Année universitaire : `2024-2025`

## Licence

Ce projet est sous licence propriétaire, conformément à la configuration actuelle du fichier `composer.json`.

Toute utilisation, modification ou redistribution doit être autorisée par l’auteur du projet, sauf indication contraire ajoutée ultérieurement dans un fichier `LICENSE`.

## Remerciements

Merci à **Esprit School of Engineering** pour l’encadrement académique et pédagogique du projet.

Ce projet met l’accent sur l’accessibilité numérique, l’autonomie des personnes en situation de handicap et l’utilisation responsable de l’intelligence artificielle dans les interfaces web modernes.
