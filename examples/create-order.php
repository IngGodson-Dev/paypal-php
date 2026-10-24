<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Goddy\PayPal\PayPalClient;

$clientId = getenv('PAYPAL_CLIENT_ID');
$clientSecret = getenv('PAYPAL_CLIENT_SECRET');
$sandbox = filter_var(getenv('PAYPAL_SANDBOX') ?: 'true', FILTER_VALIDATE_BOOLEAN);

if (!$clientId || !$clientSecret) {
    fwrite(STDERR, "Définissez PAYPAL_CLIENT_ID et PAYPAL_CLIENT_SECRET dans l’environnement.\n");
    exit(1);
}

$paypal = new PayPalClient($clientId, $clientSecret, $sandbox);
$order = $paypal->createOrder(
    '10.00',
    'EUR',
    'https://votre-site.example/paypal/return',
    'https://votre-site.example/paypal/cancel',
);

foreach ($order['links'] ?? [] as $link) {
    if (($link['rel'] ?? null) === 'approve') {
        printf("Ouvrez cette URL pour approuver le paiement : %s\n", $link['href']);
        exit(0);
    }
}

fwrite(STDERR, "PayPal n’a pas retourné de lien d’approbation.\n");
exit(1);
