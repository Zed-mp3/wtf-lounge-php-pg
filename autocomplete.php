<?php
/**
 * Autocomplete API endpoint
 * Returns product suggestions for the search bar
 */

require_once __DIR__ . '/includes/auth.php';
configureSession();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

// Only logged-in users can access
if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['suggestions' => []]);
    exit;
}

header('Cache-Control: no-store');
$query = isset($_GET['q']) ? mb_substr(trim((string) $_GET['q']), 0, 50) : '';

$suggestions = [];

if (strlen($query) >= 2) {
    try {
        // Search for products matching the query
        $stmt = $pdo->prepare(
            "SELECT productname 
             FROM products 
             WHERE productname ILIKE ? 
             AND is_active
             LIMIT 10"
        );
        $stmt->execute(['%' . escapeLike($query) . '%']);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $suggestions = array_column($results, 'productname');
        
        // Also search categories
        $stmt = $pdo->prepare(
            "SELECT DISTINCT categoryname 
             FROM categories 
             WHERE categoryname ILIKE ? 
             LIMIT 5"
        );
        $stmt->execute(['%' . escapeLike($query) . '%']);
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $categoryNames = array_column($categories, 'categoryname');
        
        // Merge and remove duplicates
        $suggestions = array_unique(array_merge($suggestions, $categoryNames));
        
        // Limit to 10 suggestions
        $suggestions = array_slice($suggestions, 0, 10);
        
    } catch (Exception $e) {
        // Return empty on error
        $suggestions = [];
    }
}

header('Content-Type: application/json');
echo json_encode(['suggestions' => $suggestions]);