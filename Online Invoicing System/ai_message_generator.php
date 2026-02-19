<?php
require 'openai_config.php';

function generateAIReminder($clientName, $invoiceNumber, $amount, $minutesOverdue)
{
    // Tone based on MINUTES (not days)
    if ($minutesOverdue <= 1) {
        $tone = "friendly and polite";
    } elseif ($minutesOverdue <= 3) {
        $tone = "professional and firm";
    } else {
        $tone = "urgent but respectful";
    }

    $prompt = "
Write a $tone invoice payment reminder email.

Client Name: $clientName
Invoice Number: $invoiceNumber
Amount Due: ₱$amount
Minutes Overdue: $minutesOverdue

Rules:
- Keep it professional
- Do not threaten
- Encourage immediate payment
- Keep it under 150 words
- End with: SmartInvoice Team
";

    $data = [
        "model" => "gpt-4.1-mini",
        "messages" => [
            ["role" => "user", "content" => $prompt]
        ],
        "temperature" => 0.7
    ];

    $ch = curl_init("https://api.openai.com/v1/chat/completions");

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer " . OPENAI_API_KEY
        ],
        CURLOPT_POSTFIELDS => json_encode($data)
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        curl_close($ch);
        return false;
    }

    curl_close($ch);

    $result = json_decode($response, true);

    return $result['choices'][0]['message']['content'] ?? false;
}
