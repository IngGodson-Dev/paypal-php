<?php

declare(strict_types=1);

namespace Goddy\PayPal\Http;

use RuntimeException;

final class CurlHttpClient implements HttpClientInterface
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Impossible d’initialiser cURL.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($handle, $options);
        $responseBody = curl_exec($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($responseBody === false) {
            $message = curl_error($handle);
            curl_close($handle);
            throw new RuntimeException('Échec de la requête HTTP PayPal : ' . $message);
        }

        curl_close($handle);

        return new HttpResponse($statusCode, $responseBody);
    }

    /**
     * @param array<string, string> $headers
     * @return list<string>
     */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }

        return $formatted;
    }
}
