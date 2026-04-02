<?php
declare(strict_types=1);

$privacyWebhookReceiverConfigs = [
    'budget.lillard.app' => [
        'environment' => 'prod-business',
        'receiver' => 'privacy webhook prod business',
    ],
];

// Existing production transactions for this account are owned by jr@lillard.org.
$privacyWebhookImportOwner = 'jr@lillard.org';
$privacyWebhookImportAccountId = 126794; // Meritrust - Lillard Development LLC - Checking

require dirname(__DIR__) . '/privacy_webhook_receiver.php';
