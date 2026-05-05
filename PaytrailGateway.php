<?php

namespace App\Services;

use Exception;

/**
 * Class PaytrailGateway
 * 
 * Handles secure API communication with the Paytrail Payment Gateway.
 * Demonstrates payload construction, HMAC-SHA256 signature generation, 
 * and secure cURL execution.
 */
class PaytrailGateway 
{
    private string $merchantId;
    private string $secretKey;
    private string $apiUrl = 'https://services.paytrail.com/payments';

    public function __construct(string $merchantId, string $secretKey) 
    {
        $this->merchantId = $merchantId;
        $this->secretKey = $secretKey;
    }

    /**
     * Creates a new payment request and returns the checkout URL.
     *
     * @param array $orderData The sanitized order details.
     * @return string The redirect URL to the payment gateway.
     * @throws Exception If the API request fails or signature is invalid.
     */
    public function createPaymentRequest(array $orderData): string 
    {
        date_default_timezone_set('Europe/Helsinki');
        
        $orderId = "ORD-" . date('Ymd-His') . "-" . rand(100, 999);
        $timestamp = date('Y-m-d\TH:i:s.v\Z'); // ISO 8601 with milliseconds
        $nonce = uniqid('hsh_', true);

        // 1. Construct the JSON Payload
        $body = [
            'stamp'     => $orderId,
            'reference' => $orderId,
            'amount'    => intval($orderData['amount'] * 100), // Convert to cents
            'currency'  => 'EUR',
            'language'  => strtoupper($orderData['language'] ?? 'EN'),
            'items'     => [
                [
                    'unitPrice'     => intval($orderData['amount'] * 100),
                    'units'         => 1,
                    'vatPercentage' => 25.5,
                    'productCode'   => 'SVC-REPAIR',
                    'description'   => $orderData['description'] ?? 'Service Repair'
                ]
            ],
            'customer'  => [
                'email'     => $orderData['email'],
                'firstName' => $orderData['firstName'],
                'lastName'  => $orderData['lastName'] ?? ' ',
                'phone'     => $orderData['phone']
            ],
            'redirectUrls' => [
                'success' => $orderData['successUrl'],
                'cancel'  => $orderData['cancelUrl']
            ]
        ];

        $bodyJson = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // 2. Generate HMAC-SHA256 Signature
        $headers = [
            'checkout-account'   => $this->merchantId,
            'checkout-algorithm' => 'sha256',
            'checkout-method'    => 'POST',
            'checkout-nonce'     => $nonce,
            'checkout-timestamp' => $timestamp
        ];

        ksort($headers); // Headers must be alphabetically sorted for valid signature
        $hmacPayload = "";
        foreach ($headers as $key => $value) {
            $hmacPayload .= $key . ':' . $value . "\n";
        }
        $hmacPayload .= $bodyJson;
        
        $signature = hash_hmac('sha256', $hmacPayload, $this->secretKey);

        // 3. Execute Secure Request
        return $this->executeCurlRequest($headers, $signature, $bodyJson);
    }

    /**
     * Executes the API call using cURL.
     */
    private function executeCurlRequest(array $headers, string $signature, string $bodyJson): string 
    {
        $ch = curl_init($this->apiUrl);

        $curlHeaders = [
            'Content-Type: application/json; charset=utf-8',
            'signature: ' . $signature
        ];
        foreach ($headers as $k => $v) {
            $curlHeaders[] = $k . ': ' . $v;
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyJson);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new Exception("Network Error: " . $curlErr);
        }

        if ($httpCode === 201) {
            $result = json_decode($response, true);
            if (isset($result['href'])) {
                return $result['href'];
            }
        }

        throw new Exception("API Error. Status Code: {$httpCode}. Response: {$response}");
    }
}