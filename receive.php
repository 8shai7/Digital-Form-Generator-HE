<?php
// receive.php — מקבל קובץ בשדה 'file'.
// אם POST['asset']='1' => זה "נכס" (asset) כלומר תמונה, נשמר בתיקיית /files.
// אחרת — HTML, נשמר בתיקיית /forms.
// אפשר גם להעביר POST['dir']='files' או 'forms' כדי לאכוף יעד מפורש.

header('Content-Type: application/json; charset=utf-8');
// עדיף להגביל לדומיין שלך:
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'Method Not Allowed']);
  exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
  http_response_code(400);
  echo json_encode(['error' => 'missing file']);
  exit;
}

// בסיס URL ציבורי
$scheme = (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'https');
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = getenv('PUBLIC_BASE_URL') ?: ($scheme . '://' . $host);

// קביעת תיקיית יעד יחסית
$isAsset   = (isset($_POST['asset']) && $_POST['asset'] === '1');
$targetRel = $isAsset ? 'files' : 'forms';

// אם הלקוח ביקש מפורשות ספרייה (למשל dir=files), נאפשר — אבל נחטא את הקלט
if (!empty($_POST['dir'])) {
  $d = preg_replace('~[^a-z0-9/_-]~i', '', $_POST['dir']);
  $d = trim($d, '/');
  if ($d !== '') $targetRel = $d;
}

$uploadDir = __DIR__ . '/' . $targetRel;
if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }
if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
  http_response_code(500);
  echo json_encode(['error' => 'upload directory not writable']);
  exit;
}

// קביעת שם קובץ
$orig = $_FILES['file']['name'] ?? '';
$ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
$base = preg_replace('/[^a-z0-9-_]/i', '_', pathinfo($orig, PATHINFO_FILENAME));
if ($base === '') $base = $isAsset ? 'image' : 'form';

// אימות סוגים בסיסי
$tmp  = $_FILES['file']['tmp_name'];
$mime = @mime_content_type($tmp) ?: '';

if ($isAsset) {
  // רק תמונות
  $allowedImgExts = ['png','jpg','jpeg','webp'];
  if (!$ext) {
    // קבע סיומת מה-MIME אם חסרה
    $ext = match ($mime) {
      'image/png'      => 'png',
      'image/jpeg'     => 'jpg',
      'image/webp'     => 'webp',
      default          => 'png'
    };
  }
  if (!in_array($ext, $allowedImgExts, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid image type']);
    exit;
  }
} else {
  // HTML בלבד
  if ($ext !== 'html') $ext = 'html';
}

$final = $base . '.' . $ext;
$counter = 1;
while (file_exists($uploadDir . '/' . $final)) {
  $final = $base . '-' . $counter++ . '.' . $ext;
}

if (!move_uploaded_file($tmp, $uploadDir . '/' . $final)) {
  http_response_code(500);
  echo json_encode(['error' => 'failed to save']);
  exit;
}

$publicUrl = rtrim($baseUrl, '/') . '/' . $targetRel . '/' . $final;
echo json_encode([
  'url'  => $publicUrl,
  'path' => '/' . $targetRel . '/' . $final
]);
