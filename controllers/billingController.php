<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/jwt.php';
require_once __DIR__ . '/../utils/subscriptionService.php';

/**
 * Billing routes (/billing/*) — Google Play subscription verification + status.
 */
function handleBillingRoutes(string $uri, string $method): void
{
    if ($uri === '/billing/verify' && $method === 'POST') {
        billingVerify();
        return;
    }

    if ($uri === '/billing/status' && $method === 'GET') {
        billingStatus();
        return;
    }

    Response::error('Invalid billing route', 404);
}

/**
 * POST /billing/verify  body: { product_id, purchase_token }
 * Verifies a Play purchase server-side and unlocks premium for the user.
 */
function billingVerify(): void
{
    $tokenData = JWTHandler::requireAuth();
    $userId = (int)$tokenData['userId'];

    $input = getJsonInput();
    $productId = trim((string)($input['product_id'] ?? $input['productId'] ?? ''));
    $purchaseToken = trim((string)($input['purchase_token'] ?? $input['purchaseToken'] ?? ''));

    if ($productId === '' || $purchaseToken === '') {
        Response::error('product_id and purchase_token are required', 400);
        return;
    }

    try {
        $status = SubscriptionService::verifyPurchase($userId, $productId, $purchaseToken);
        Response::success($status, 'Purchase verified');
    } catch (Throwable $e) {
        error_log('Billing verify error: ' . $e->getMessage());
        Response::error('Verification failed: ' . $e->getMessage(), 400);
    }
}

/** GET /billing/status — current premium state for the user. */
function billingStatus(): void
{
    $tokenData = JWTHandler::requireAuth();
    $userId = (int)$tokenData['userId'];

    try {
        Response::success(SubscriptionService::getStatus($userId), 'Billing status retrieved');
    } catch (Throwable $e) {
        error_log('Billing status error: ' . $e->getMessage());
        Response::error('Failed to get billing status', 500);
    }
}
