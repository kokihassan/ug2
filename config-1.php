<?php
// ============================================================
// DATABASE
// ============================================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'restaurant_bot');
define('DB_USER', 'botuser');
define('DB_PASS', 'ALBASHA0#@');
define('DB_CHARSET', 'utf8mb4');

// ============================================================
// AI / KEYS
// ============================================================
define('GEMINI_API_KEY', 'AQ.Ab8RN6I6AiBTk-hJQwQdFcOpVkBLELWRZ87MlYoGbLAD8G_R1A');

// ============================================================
// APP
// ============================================================
define('APP_URL', 'http://13.62.37.86');
define('UPLOAD_PATH', '/var/www/restaurant/uploads/');
define('UPLOAD_URL', 'http://13.62.37.86/uploads/');
define('JWT_SECRET', '73fa1e883e92707f02a9f07c96c5fc8970d81f5436940a2b32150674337b54c3');
define('ADMIN_SESSION_LIFETIME', 86400);

// ============================================================
// CORS
// ============================================================
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// ============================================================
// DATABASE CONNECTION
// ============================================================
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'DB connection failed: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

// ============================================================
// RESPONSES
// ============================================================
function success($data = [], $message = 'success', $code = 200) {
    http_response_code($code);
    echo json_encode(['success' => true, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit();
}
function error($message = 'Error', $code = 400, $data = []) {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit();
}

// ============================================================
// JWT AUTH
// ============================================================
function generateToken($payload) {
    $h = rtrim(base64_encode(json_encode(['alg'=>'HS256','typ'=>'JWT'])), '=');
    $payload['iat'] = time(); $payload['exp'] = time() + ADMIN_SESSION_LIFETIME;
    $p = rtrim(base64_encode(json_encode($payload)), '=');
    $s = rtrim(base64_encode(hash_hmac('sha256', "$h.$p", JWT_SECRET, true)), '=');
    return "$h.$p.$s";
}
function verifyToken($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;
    [$h, $p, $s] = $parts;
    $expected = rtrim(base64_encode(hash_hmac('sha256', "$h.$p", JWT_SECRET, true)), '=');
    if (!hash_equals($s, $expected)) return false;
    $data = json_decode(base64_decode($p . str_repeat('=', 4 - strlen($p) % 4)), true);
    if (!$data || $data['exp'] < time()) return false;
    return $data;
}

// Admin auth - rejects customer-type tokens
function requireAuth() {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    $token = null;
    if (str_starts_with($auth, 'Bearer ')) {
        $token = substr($auth, 7);
    } elseif (!empty($_GET['token'])) {
        // Allow token via query string for direct-download links (export CSV, etc.)
        $token = $_GET['token'];
    }
    if (!$token) error('Unauthorized', 401);
    $data = verifyToken($token);
    if (!$data || ($data['type'] ?? '') === 'customer') error('Invalid or expired token', 401);
    return $data;
}

// ============================================================
// CUSTOMER AUTH (separate token type from admin)
// ============================================================
function requireCustomerAuth() {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (!str_starts_with($auth, 'Bearer ')) error('يجب تسجيل الدخول', 401);
    $data = verifyToken(substr($auth, 7));
    if (!$data || ($data['type'] ?? '') !== 'customer') error('جلسة غير صالحة، يرجى تسجيل الدخول مرة أخرى', 401);
    return $data;
}

// Optional customer auth - returns customer_id if logged in, null otherwise.
function optionalCustomerAuth() {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (!str_starts_with($auth, 'Bearer ')) return null;
    $data = verifyToken(substr($auth, 7));
    if (!$data || ($data['type'] ?? '') !== 'customer') return null;
    return $data['customer_id'];
}

// ============================================================
// FILE UPLOAD
// ============================================================
function uploadImage($file, $folder = 'items') {
    $uploadDir = UPLOAD_PATH . $folder . '/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
    $allowed = ['jpg','jpeg','png','webp','gif'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed) || $file['size'] > 5*1024*1024) return false;
    $filename = uniqid() . '_' . time() . '.' . $ext;
    if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
        return UPLOAD_URL . $folder . '/' . $filename;
    }
    return false;
}

// ============================================================
// ORDER NUMBER
// ============================================================
function generateOrderNumber() {
    $db = getDB();
    $stmt = $db->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()");
    $count = $stmt->fetchColumn() + 1;
    return 'ORD-' . date('Ymd') . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
}

// ============================================================
// WHATSAPP NOTIFICATIONS - DISABLED (no WAHA on this server)
// ============================================================
function sendWhatsApp($number, $message, $imageUrl = null) {
    return ['success' => false, 'disabled' => true];
}
