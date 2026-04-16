<?php
/**
 * AJAX Endpoint - Onboarding Details
 * Fetches single plan details from HR1 for modal display
 */
header('Content-Type: application/json');

require_once 'Connection/session_handler.php';
require_once 'Connection/hr1_onboarding.php';

// Get plan ID
$planId = (int)($_GET['id'] ?? 0);

if ($planId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid plan ID']);
    exit;
}

// Initialize HR1 Onboarding Service
$onboardingService = new HR1OnboardingService();

// Fetch plan details
$response = $onboardingService->getPlan($planId);

if ($response['success'] ?? false) {
    echo json_encode([
        'success' => true,
        'data' => $response['data'] ?? []
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => $response['error'] ?? 'Failed to fetch plan details'
    ]);
}
