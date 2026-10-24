<?php

declare(strict_types=1);

namespace Goddy\PayPal;

use Goddy\PayPal\Exception\ApiException;
use Goddy\PayPal\Exception\AuthenticationException;
use Goddy\PayPal\Exception\PayPalException;
use Goddy\PayPal\Http\CurlHttpClient;
use Goddy\PayPal\Http\HttpClientInterface;
use Goddy\PayPal\Http\HttpResponse;
use InvalidArgumentException;
use JsonException;

final class PayPalClient
{
    private const SANDBOX_URL = 'https://api-m.sandbox.paypal.com';
    private const LIVE_URL = 'https://api-m.paypal.com';

    private ?string $accessToken = null;
    private int $tokenExpiresAt = 0;
    private HttpClientInterface $httpClient;
    private string $baseUrl;

    public function __construct(
        private string $clientId,
        private string $clientSecret,
        bool $sandbox = true,
        ?HttpClientInterface $httpClient = null,
    ) {
        if ($clientId === '' || $clientSecret === '') {
            throw new InvalidArgumentException('Le Client ID et le Client Secret PayPal sont obligatoires.');
        }

        $this->baseUrl = $sandbox ? self::SANDBOX_URL : self::LIVE_URL;
        $this->httpClient = $httpClient ?? new CurlHttpClient();
    }

    /**
     * Crée une commande PayPal et retourne la réponse Orders v2.
     *
     * @return array<string, mixed>
     */
    public function createOrder(
        string $amount,
        string $currency,
        string $returnUrl,
        string $cancelUrl,
    ): array {
        if (!preg_match('/^(?=.*[1-9])\d+(?:\.\d{1,2})?$/', $amount)) {
            throw new InvalidArgumentException('Le montant doit être positif et contenir au maximum deux décimales.');
        }

        $currency = strtoupper($currency);
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('La devise doit être un code ISO 4217 de trois lettres.');
        }

        $this->validateUrl($returnUrl, 'returnUrl');
        $this->validateUrl($cancelUrl, 'cancelUrl');

        return $this->apiRequest('POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'amount' => [
                    'currency_code' => $currency,
                    'value' => $amount,
                ],
            ]],
            'application_context' => [
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
                'user_action' => 'PAY_NOW',
            ],
        ]);
    }

    /**
     * Capture une commande après le retour de l’acheteur sur votre site.
     *
     * @return array<string, mixed>
     */
    public function captureOrder(string $orderId): array
    {
        if ($orderId === '') {
            throw new InvalidArgumentException('L’identifiant de commande PayPal est obligatoire.');
        }

        return $this->apiRequest('POST', '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture');
    }

    private function validateUrl(string $url, string $field): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException(sprintf('%s doit être une URL HTTP ou HTTPS valide.', $field));
        }
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function apiRequest(string $method, string $path, ?array $payload = null): array
    {
        $body = $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR);
        $response = $this->httpClient->request($method, $this->baseUrl . $path, [
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $body);

        return $this->decodeResponse($response, 'PayPal a refusé la requête.');
    }

    private function getAccessToken(): string
    {
        if ($this->accessToken !== null && $this->tokenExpiresAt > time()) {
            return $this->accessToken;
        }

        $response = $this->httpClient->request('POST', $this->baseUrl . '/v1/oauth2/token', [
            'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ], 'grant_type=client_credentials');

        try {
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AuthenticationException('Réponse OAuth PayPal illisible.', $response->statusCode, $exception);
        }

        if (!$response->isSuccessful() || !is_array($data) || !isset($data['access_token'])) {
            $message = is_array($data) && isset($data['error_description'])
                ? (string) $data['error_description']
                : 'Authentification PayPal impossible.';
            throw new AuthenticationException($message, $response->statusCode);
        }

        $this->accessToken = (string) $data['access_token'];
        $expiresIn = isset($data['expires_in']) ? (int) $data['expires_in'] : 300;
        $this->tokenExpiresAt = time() + max(0, $expiresIn - 60);

        return $this->accessToken;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(HttpResponse $response, string $fallbackMessage): array
    {
        try {
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PayPalException('Réponse PayPal illisible.', $response->statusCode, $exception);
        }

        if (!$response->isSuccessful()) {
            $message = is_array($data) && isset($data['message'])
                ? (string) $data['message']
                : $fallbackMessage;
            $details = is_array($data) ? $data : [];
            throw new ApiException($message, $response->statusCode, $details);
        }

        if (!is_array($data)) {
            throw new PayPalException('Format de réponse PayPal inattendu.', $response->statusCode);
        }

        return $data;
    }
}
