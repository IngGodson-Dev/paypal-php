# PayPal PHP Client

Client PHP orienté objet pour intégrer l’API **PayPal Orders v2** dans une application PHP 8.0 ou supérieure. Il gère l’authentification OAuth 2.0, la création de commandes et leur capture côté serveur.

> **Interprétation :** Ce projet applique les principes **SOLID**, notamment la responsabilité unique et l’inversion des dépendances, avec un transport HTTP injectable (approche Strategy).

## Fonctionnalités

- Authentification OAuth 2.0 avec renouvellement du jeton et cache en mémoire.
- Création d’une commande avec montant, devise et URLs de retour/annulation.
- Capture serveur d’une commande PayPal.
- Environnements Sandbox et Live.
- Exceptions dédiées pour l’authentification et les erreurs de l’API.
- Transport HTTP remplaçable, ce qui permet de tester sans contacter PayPal.

Ce client ne traite pas les webhooks, ne stocke pas de commandes et n’implémente pas d’interface de paiement. Ces responsabilités restent dans l’application qui l’utilise.

## Prérequis

- PHP 8.0 ou supérieur.
- Extensions PHP `curl` et `json` activées.
- Composer 2.
- Un compte développeur PayPal et une application REST créée dans le [tableau de bord développeur PayPal](https://developer.paypal.com/dashboard/).

## Installation

Installez-le dans votre application :

```bash
composer require goddy/paypal-php
```
## Configuration

Créez une application REST dans le tableau de bord PayPal et récupérez son **Client ID** et son **Secret**. Commencez avec les identifiants Sandbox. Configurez-les dans l’environnement du serveur; ne les inscrivez jamais dans le code, dans Git ou dans le navigateur.

Exemple PowerShell :

```powershell
$env:PAYPAL_CLIENT_ID = "votre_client_id_sandbox"
$env:PAYPAL_CLIENT_SECRET = "votre_secret_sandbox"
$env:PAYPAL_SANDBOX = "true"
```

Exemple Linux/macOS :

```bash
export PAYPAL_CLIENT_ID="votre_client_id_sandbox"
export PAYPAL_CLIENT_SECRET="votre_secret_sandbox"
export PAYPAL_SANDBOX="true"
```

Le fichier `.env.example` rappelle les noms des variables, mais le package ne charge pas automatiquement de fichier `.env`. Dans une application, utilisez la configuration de votre hébergeur ou une bibliothèque dédiée, par exemple `vlucas/phpdotenv`.

## Utilisation

### 1. Créer la commande

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Goddy\PayPal\PayPalClient;

$paypal = new PayPalClient(
    clientId: getenv('PAYPAL_CLIENT_ID'),
    clientSecret: getenv('PAYPAL_CLIENT_SECRET'),
    sandbox: filter_var(getenv('PAYPAL_SANDBOX') ?: 'true', FILTER_VALIDATE_BOOLEAN),
);

$order = $paypal->createOrder(
    amount: '10.00',
    currency: 'EUR',
    returnUrl: 'https://votre-site.example/paypal/return',
    cancelUrl: 'https://votre-site.example/paypal/cancel',
);

foreach ($order['links'] as $link) {
    if ($link['rel'] === 'approve') {
        header('Location: ' . $link['href']);
        exit;
    }
}

throw new RuntimeException('PayPal n’a pas retourné de lien d’approbation.');
```

L’exemple exécutable [examples/create-order.php](examples/create-order.php) affiche le lien d’approbation au lieu de rediriger.

### 2. Capturer après approbation

Lorsque PayPal redirige l’acheteur vers votre `returnUrl`, récupérez l’identifiant de commande fourni dans la requête, vérifiez qu’il correspond à une commande appartenant à l’utilisateur et capturez-la côté serveur :

```php
$orderId = $_GET['token'] ?? '';
if ($orderId === '') {
    http_response_code(400);
    exit('Identifiant PayPal manquant.');
}

$result = $paypal->captureOrder($orderId);

if (($result['status'] ?? null) === 'COMPLETED') {
    // Marquer la commande locale comme payée après vérification métier.
}
```

Ne marquez pas une commande comme payée simplement parce que le navigateur revient sur votre site. Vérifiez la réponse de capture et, pour les flux de production, mettez en place le traitement et la validation des webhooks PayPal. Cette bibliothèque ne valide pas les signatures de webhook.

## Exécuter l’exemple

Après `composer install`, configurez les variables d’environnement puis lancez :

```bash
php examples/create-order.php
```

L’exemple utilise la Sandbox par défaut. Il ne déclenche aucun paiement réel tant que vous ne le configurez pas avec les identifiants Live.

## Architecture

```text
src/
├── Exception/
│   ├── ApiException.php
│   ├── AuthenticationException.php
│   └── PayPalException.php
├── Http/
│   ├── CurlHttpClient.php
│   ├── HttpClientInterface.php
│   └── HttpResponse.php
└── PayPalClient.php
```

- `PayPalClient` orchestre OAuth et les appels Orders v2; il ne met pas en œuvre les détails de cURL.
- `HttpClientInterface` définit le contrat HTTP. `CurlHttpClient` est l’implémentation par défaut et peut être remplacée ou simulée dans les tests.
- Les exceptions distinguent les erreurs OAuth, les erreurs HTTP/API (avec code HTTP et détails) et les autres erreurs PayPal.
- Les montants sont fournis sous forme de chaîne pour éviter les approximations des nombres flottants lors de la sérialisation JSON.

## Tests

Les tests utilisent un faux transport HTTP et ne nécessitent aucun compte PayPal :

```bash
composer test
```

La suite GitHub Actions exécute aussi les tests avec PHP 8.0 à 8.3 et PHP 8.5, notamment avec PHP 8.5.11 installé sur ce PC.

## Sécurité et limites

- Utilisez les identifiants **Sandbox** pendant le développement; passez `sandbox: false` uniquement en production.
- Gardez le Secret dans une variable d’environnement ou un gestionnaire de secrets. Ne le transmettez jamais au code frontend.
- Validez l’utilisateur, le montant attendu, la devise et l’état de la commande dans votre application avant la capture.
- N’acceptez pas un identifiant de commande fourni par le client sans vérification d’appartenance.
- Le client a des délais de connexion et de réponse, mais pas de stratégie de nouvelle tentative automatique.
- Le client ne remplace pas les exigences de conformité, de journalisation ou de gestion des webhooks propres à votre application.

## Publier sur GitHub

1. Créez un dépôt GitHub vide.
2. Depuis ce dossier, initialisez Git et poussez la branche principale :

   ```bash
   git init
   git add .
   git commit -m "Initial PayPal PHP client"
   git branch -M main
   git remote add origin https://github.com/VOTRE-COMPTE/VOTRE-DEPOT.git
   git push -u origin main
   ```

3. Remplacez `goddy/paypal-php` dans `composer.json` par `votre-compte/nom-du-depot` avant publication Composer Packagist.
4. Ajoutez une description et des sujets au dépôt GitHub. Ne poussez jamais vos identifiants PayPal.

## Licence

Distribué sous licence MIT. Voir [LICENSE](LICENSE).
