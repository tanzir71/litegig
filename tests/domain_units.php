<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'litegig-domain-units-' . bin2hex(random_bytes(6));
@mkdir($tmp . DIRECTORY_SEPARATOR . 'data', 0750, true);
@mkdir($tmp . DIRECTORY_SEPARATOR . 'uploads', 0750, true);
@mkdir($tmp . DIRECTORY_SEPARATOR . 'backups', 0750, true);

putenv('LITEGIG_DATA_DIR=' . $tmp . DIRECTORY_SEPARATOR . 'data');
putenv('LITEGIG_DB_PATH=' . $tmp . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'litegig.db');
putenv('LITEGIG_UPLOAD_DIR=' . $tmp . DIRECTORY_SEPARATOR . 'uploads');
putenv('LITEGIG_BACKUP_DIR=' . $tmp . DIRECTORY_SEPARATOR . 'backups');
putenv('LITEGIG_SECURITY_HEADERS=false');
putenv('LITEGIG_LOCALE=en_US');
putenv('LITEGIG_CURRENCY=USD');

define('LITEGIG_ROOT', $root);

require_once LITEGIG_ROOT . '/app/bootstrap.php';
require_once LITEGIG_ROOT . '/app/database.php';
require_once LITEGIG_ROOT . '/app/models/requests.php';
require_once LITEGIG_ROOT . '/app/models/users.php';

$checks = 0;

function check_domain(bool $value, string $label): void {
    global $checks;
    $checks++;
    if (!$value) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

function rm_tree_domain_units(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rm_tree_domain_units($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

check_domain(parse_price_to_cents('12.34') === 1234, 'plain decimal prices parse to cents');
check_domain(parse_price_to_cents('12,34') === 1234, 'comma decimal prices parse to cents');
check_domain(parse_price_to_cents('$19.99') === 1999, 'currency-marked prices parse to cents');
check_domain(parse_price_to_cents('12.345') === null, 'over-precise prices are rejected');
check_domain(parse_price_to_cents('not a price') === null, 'non-numeric prices are rejected');

$pdo = db();
setting_set('default_fee_percent', '12.50');
check_domain(abs(app_fee_percent() - 12.5) < 0.001, 'fee percent reads numeric settings');
setting_set('default_fee_percent', '-4');
check_domain(app_fee_percent() === 0.0, 'fee percent clamps below zero');
setting_set('default_fee_percent', '80');
check_domain(app_fee_percent() === 50.0, 'fee percent clamps above fifty');
setting_set('default_fee_percent', 'bad');
check_domain(abs(app_fee_percent() - 8.0) < 0.001, 'fee percent falls back for invalid settings');

$taskType = [
    'summary_fields' => [],
    'fields' => [
        ['key' => 'pickup', 'label' => 'Pickup', 'type' => 'geo', 'required' => true],
        ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'number', 'required' => true],
        ['key' => 'price_cents', 'label' => 'Fee', 'type' => 'price', 'required' => true],
        ['key' => 'fragile', 'label' => 'Fragile', 'type' => 'boolean'],
        ['key' => 'speed', 'label' => 'Speed', 'type' => 'select', 'required' => true, 'options' => [
            ['value' => 'standard', 'label' => 'Standard'],
            ['value' => 'rush', 'label' => 'Rush'],
        ]],
        ['key' => 'notes', 'label' => 'Notes', 'type' => 'text', 'required' => true],
        ['key' => 'proof', 'label' => 'Proof', 'type' => 'attachment', 'required' => true],
    ],
];

$errors = [];
$meta = coerce_metadata_from_post($taskType, [
    'pickup_address' => '10 Market St',
    'pickup_lat' => '39.9526',
    'pickup_lng' => '-75.1652',
    'quantity' => '3.5',
    'price_cents' => '25.00',
    'fragile' => 'on',
    'speed' => 'standard',
    'notes' => 'Leave at desk',
], [], $errors, ['proof' => 'existing-proof.pdf']);

check_domain($errors === [], 'valid schema metadata has no errors');
check_domain($meta['pickup']['address'] === '10 Market St' && abs((float)$meta['pickup']['lat'] - 39.9526) < 0.0001, 'geo metadata stores address and coordinates');
check_domain($meta['quantity'] === 3.5, 'number metadata keeps decimals');
check_domain($meta['price_cents'] === 2500, 'price metadata stores cents');
check_domain($meta['fragile'] === 1, 'boolean metadata stores enabled state');
check_domain($meta['proof'] === 'existing-proof.pdf', 'required attachment can retain existing private file');
check_domain(request_primary_price_cents($taskType, $meta) === 2500, 'primary request price uses schema price field');
check_domain(request_first_geo($taskType, $meta)['address'] === '10 Market St', 'first geo helper extracts the first geocoded field');
check_domain(infer_summary_keys($taskType) === ['pickup', 'price_cents'], 'summary key inference prefers geo and price fields');
check_domain(request_summary_value($taskType, $meta, 'speed') === 'Standard', 'select summary values use labels');
check_domain(request_summary_value($taskType, $meta, 'pickup') === '10 Market St', 'geo summary values use address');
check_domain(request_summary_value($taskType, $meta, 'fragile') === 'Yes', 'boolean summary values are localized');

$invalidErrors = [];
coerce_metadata_from_post($taskType, [
    'pickup_address' => '',
    'quantity' => 'lots',
    'price_cents' => '12.345',
    'speed' => 'overnight',
    'notes' => '',
], [], $invalidErrors, []);
foreach (['pickup', 'quantity', 'price_cents', 'speed', 'notes', 'proof'] as $key) {
    check_domain(isset($invalidErrors[$key]), 'invalid schema metadata reports ' . $key);
}

$oneDegree = haversine_km(0.0, 0.0, 0.0, 1.0);
check_domain(abs($oneDegree - 111.19) < 0.5, 'haversine distance matches one degree at equator');
check_domain(abs($oneDegree - haversine_km(0.0, 1.0, 0.0, 0.0)) < 0.001, 'haversine distance is symmetrical');

$now = now_iso();
$insertUser = $pdo->prepare("INSERT INTO users (email, password_hash, display_name, created_at) VALUES (?, ?, ?, ?)");
$insertUser->execute(['ratee@example.test', password_hash('domain-test', PASSWORD_DEFAULT), 'Ratee', $now]);
$rateeId = (int)$pdo->lastInsertId();
$insertUser->execute(['rater1@example.test', password_hash('domain-test', PASSWORD_DEFAULT), 'Rater One', $now]);
$raterOneId = (int)$pdo->lastInsertId();
$insertUser->execute(['rater2@example.test', password_hash('domain-test', PASSWORD_DEFAULT), 'Rater Two', $now]);
$raterTwoId = (int)$pdo->lastInsertId();
$insertUser->execute(['empty@example.test', password_hash('domain-test', PASSWORD_DEFAULT), 'Empty', $now]);
$emptyId = (int)$pdo->lastInsertId();

$taskTypeId = (int)$pdo->query("SELECT id FROM task_types ORDER BY id LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO requests (requester_id, task_type_id, title, description, price_cents, fee_cents, status, runner_id, metadata, created_at, updated_at)
    VALUES (?, ?, ?, ?, 2000, 160, 'completed', ?, ?, ?, ?)")
    ->execute([$raterOneId, $taskTypeId, 'Rating fixture', 'Done', $rateeId, '{}', $now, $now]);
$requestId = (int)$pdo->lastInsertId();

$ratingInsert = $pdo->prepare("INSERT INTO ratings (request_id, rater_id, ratee_id, score, note, created_at) VALUES (?, ?, ?, ?, ?, ?)");
$ratingInsert->execute([$requestId, $raterOneId, $rateeId, 5, 'Great', $now]);
$ratingInsert->execute([$requestId, $raterTwoId, $rateeId, 3, 'Fine', $now]);

$summary = user_rating_summary($rateeId);
check_domain($summary['count'] === 2, 'rating summary counts ratings for the ratee');
check_domain(abs((float)$summary['avg'] - 4.0) < 0.001, 'rating summary averages scores');
$emptySummary = user_rating_summary($emptyId);
check_domain($emptySummary['count'] === 0 && $emptySummary['avg'] === null, 'rating summary returns null average with no ratings');
$recent = user_recent_ratings($rateeId, 1);
check_domain(count($recent) === 1 && (string)$recent[0]['request_title'] === 'Rating fixture', 'recent ratings include request context and obey limit');

rm_tree_domain_units($tmp);
echo "Domain unit tests passed ({$checks} checks).\n";
