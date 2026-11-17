<?php
// ===== غيّر كلمة المرور دي قبل الرفع =====
const ADMIN_PASSWORD = 'ChangeMe123';

const STORE   = __DIR__ . '/store.php';   // البيانات (محمية من التحميل المباشر)
const UPLOADS = __DIR__ . '/uploads';     // الصور المرفوعة
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function load() {
    $empty = ['seeded' => false, 'messages' => []];
    if (!is_file(STORE)) return $empty;
    $raw = file_get_contents(STORE);
    $raw = substr($raw, strpos($raw, "\n") + 1);
    return json_decode($raw, true) ?: $empty;
}
function store($d) {
    file_put_contents(STORE, "<?php http_response_code(403); exit; ?>\n" . json_encode($d, JSON_UNESCAPED_UNICODE));
}
function views($bump) {
    $fh = fopen(__DIR__ . '/views.txt', 'c+');
    flock($fh, LOCK_EX);
    $n = max((int)stream_get_contents($fh), 1247);
    if ($bump) { $n++; ftruncate($fh, 0); rewind($fh); fwrite($fh, (string)$n); }
    flock($fh, LOCK_UN); fclose($fh);
    return $n;
}
function admin($in) {
    if (!hash_equals(ADMIN_PASSWORD, (string)($in['key'] ?? ''))) { usleep(500000); out(['error' => 'unauthorized'], 401); }
}
function save_image($img) {
    if (!is_string($img) || !preg_match('#^data:image/(jpeg|png|webp);base64,(.+)$#s', $img, $m)) return $img;
    $bin = base64_decode($m[2], true);
    if ($bin === false || strlen($bin) > 3000000 || getimagesizefromstring($bin) === false) out(['error' => 'bad image'], 400);
    if (!is_dir(UPLOADS)) mkdir(UPLOADS, 0755, true);
    $name = 'img_' . md5($bin) . '.' . ($m[1] === 'jpeg' ? 'jpg' : $m[1]);
    if (!is_file(UPLOADS . '/' . $name)) file_put_contents(UPLOADS . '/' . $name, $bin);
    return 'uploads/' . $name;
}

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? '';
$lock = fopen(__DIR__ . '/lock.tmp', 'c');
flock($lock, LOCK_EX);
$d = load();

switch ($action) {
    case 'get':
        out(['seeded' => !empty($d['seeded']), 'categories' => $d['categories'] ?? [], 'projects' => $d['projects'] ?? [],
             'services' => $d['services'] ?? [], 'testimonials' => $d['testimonials'] ?? [],
             'settings' => $d['settings'] ?? new stdClass, 'views' => views(false)]);
    case 'visit':
        out(['views' => views(true)]);
    case 'login':
        admin($in); out(['ok' => 1]);
    case 'save':
        admin($in);
        foreach (['categories', 'projects', 'services', 'testimonials'] as $k) {
            if (!is_array($in[$k] ?? null)) out(['error' => "bad $k"], 400);
            $d[$k] = $in[$k];
        }
        foreach ($d['projects'] as &$p) { $p['image'] = save_image($p['image'] ?? ''); }
        unset($p);
        $d['settings'] = is_array($in['settings'] ?? null) ? $in['settings'] : [];
        $d['seeded'] = true;
        store($d);
        out(['ok' => 1, 'projects' => $d['projects']]);
    case 'message':
        $m = [
            'id' => 'msg_' . time() . mt_rand(100, 999),
            'name' => mb_substr((string)($in['name'] ?? ''), 0, 100),
            'phone' => mb_substr((string)($in['phone'] ?? ''), 0, 40),
            'service' => mb_substr((string)($in['service'] ?? ''), 0, 120),
            'details' => mb_substr((string)($in['details'] ?? ''), 0, 2000),
            'createdAt' => date('c'),
        ];
        if ($m['name'] === '' || $m['details'] === '') out(['error' => 'empty'], 400);
        $d['messages'] = array_slice(array_merge([$m], $d['messages'] ?? []), 0, 500);
        store($d);
        out(['ok' => 1]);
    case 'messages':
        admin($in); out(['messages' => $d['messages'] ?? []]);
    default:
        out(['error' => 'unknown action'], 404);
}
