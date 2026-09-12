<?php
declare(strict_types=1);

function envOrDefault(string $name, string $default): string
{
    $value = getenv($name);

    return $value === false || $value === '' ? $default : $value;
}

function safeValue(array $source, string $key, string $default = ''): string
{
    $value = $source[$key] ?? $default;

    return is_string($value) ? trim($value) : $default;
}

function columnExists(array $columns, string $name): bool
{
    return in_array($name, $columns, true);
}

function detectColumns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query(sprintf('SHOW COLUMNS FROM `%s`', str_replace('`', '', $table)));
    if (!$stmt) {
        return [];
    }

    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        if (isset($column['Field']) && is_string($column['Field'])) {
            $columns[] = $column['Field'];
        }
    }

    return $columns;
}

function toFloat(mixed $value): float
{
    return is_numeric($value) ? (float) $value : 0.0;
}

function toInt(mixed $value): int
{
    return is_numeric($value) ? (int) $value : 0;
}

function scoreOption(array $row, array $columns, string $destination, float $budget, int $travelers, int $days, array $interestTokens): float
{
    $score = 0.0;

    if ($destination !== '') {
        $target = strtolower($destination);
        $destinationFields = array_filter([
            columnExists($columns, 'destination') ? (string) ($row['destination'] ?? '') : '',
            columnExists($columns, 'city') ? (string) ($row['city'] ?? '') : '',
            columnExists($columns, 'country') ? (string) ($row['country'] ?? '') : '',
            columnExists($columns, 'title') ? (string) ($row['title'] ?? '') : '',
        ]);

        foreach ($destinationFields as $field) {
            $fieldLower = strtolower($field);
            if ($fieldLower === $target) {
                $score += 40;
                break;
            }

            if (str_contains($fieldLower, $target)) {
                $score += 30;
                break;
            }
        }
    }

    $price = columnExists($columns, 'price') ? toFloat($row['price'] ?? 0) : 0.0;
    if ($budget > 0 && $price > 0) {
        $budgetGap = abs($budget - $price);
        $score += max(0, 35 - ($budgetGap / max(1, $budget)) * 35);

        if ($price <= $budget) {
            $score += 10;
        }
    }

    if ($travelers > 0 && columnExists($columns, 'max_people')) {
        $maxPeople = toInt($row['max_people'] ?? 0);
        if ($maxPeople >= $travelers) {
            $score += 10;
        }
    }

    if ($days > 0) {
        if (columnExists($columns, 'days')) {
            $packageDays = toInt($row['days'] ?? 0);
            if ($packageDays > 0) {
                $dayGap = abs($days - $packageDays);
                $score += max(0, 10 - $dayGap * 2);
            }
        } elseif (columnExists($columns, 'nights')) {
            $packageDays = toInt($row['nights'] ?? 0) + 1;
            $dayGap = abs($days - $packageDays);
            $score += max(0, 10 - $dayGap * 2);
        }
    }

    if ($interestTokens !== []) {
        $interestFields = array_filter([
            columnExists($columns, 'tags') ? (string) ($row['tags'] ?? '') : '',
            columnExists($columns, 'category') ? (string) ($row['category'] ?? '') : '',
            columnExists($columns, 'description') ? (string) ($row['description'] ?? '') : '',
            columnExists($columns, 'title') ? (string) ($row['title'] ?? '') : '',
        ]);

        if ($interestFields !== []) {
            $combined = strtolower(implode(' ', $interestFields));
            foreach ($interestTokens as $token) {
                if ($token !== '' && str_contains($combined, $token)) {
                    $score += 6;
                }
            }
        }
    }

    if (columnExists($columns, 'rating')) {
        $rating = toFloat($row['rating'] ?? 0);
        if ($rating > 0) {
            $score += min(5, $rating);
        }
    }

    return round($score, 2);
}

function getDisplayName(array $row, array $columns): string
{
    $candidates = ['name', 'title', 'package_name', 'destination'];
    foreach ($candidates as $key) {
        if (columnExists($columns, $key) && isset($row[$key]) && $row[$key] !== '') {
            return (string) $row[$key];
        }
    }

    return 'Travel Option';
}

$destination = safeValue($_POST, 'destination');
$budget = max(0.0, (float) safeValue($_POST, 'budget', '0'));
$travelers = max(0, (int) safeValue($_POST, 'travelers', '0'));
$days = max(0, (int) safeValue($_POST, 'days', '0'));
$interests = safeValue($_POST, 'interests');
$interestTokens = array_values(array_filter(array_map(
    static fn(string $token): string => strtolower(trim($token)),
    preg_split('/[\s,]+/', $interests) ?: []
)));

$results = [];
$errors = [];
$searched = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($searched) {
    try {
        $dbHost = envOrDefault('DB_HOST', '127.0.0.1');
        $dbName = envOrDefault('DB_NAME', 'goglobal');
        $dbUser = envOrDefault('DB_USER', 'root');
        $dbPass = envOrDefault('DB_PASS', '');
        $dbPort = envOrDefault('DB_PORT', '3306');
        $dbTable = envOrDefault('DB_TABLE', 'packages');

        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName),
            $dbUser,
            $dbPass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        $columns = detectColumns($pdo, $dbTable);
        if ($columns === []) {
            throw new RuntimeException('No columns found in configured table.');
        }

        $selectColumns = array_values(array_intersect(
            ['id', 'name', 'title', 'package_name', 'destination', 'city', 'country', 'description', 'price', 'currency', 'days', 'nights', 'max_people', 'rating', 'tags', 'category'],
            $columns
        ));

        if ($selectColumns === []) {
            $selectColumns = ['*'];
        }

        $where = [];
        $params = [];

        if ($destination !== '') {
            $searchableColumns = array_values(array_intersect(['destination', 'city', 'country', 'title', 'name'], $columns));
            if ($searchableColumns !== []) {
                $segments = [];
                foreach ($searchableColumns as $idx => $col) {
                    $key = ':destination' . $idx;
                    $segments[] = sprintf('`%s` LIKE %s', $col, $key);
                    $params[$key] = '%' . $destination . '%';
                }
                $where[] = '(' . implode(' OR ', $segments) . ')';
            }
        }

        if ($budget > 0 && columnExists($columns, 'price')) {
            $where[] = '`price` <= :max_budget';
            $params[':max_budget'] = $budget * 1.4;
        }

        $sql = sprintf('SELECT %s FROM `%s`', implode(', ', array_map(static fn(string $col): string => $col === '*' ? '*' : '`' . $col . '`', $selectColumns)), str_replace('`', '', $dbTable));
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        if (columnExists($columns, 'rating')) {
            $sql .= ' ORDER BY `rating` DESC';
        } elseif (columnExists($columns, 'price')) {
            $sql .= ' ORDER BY `price` ASC';
        }

        $sql .= ' LIMIT 80';

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $row['_score'] = scoreOption($row, $columns, $destination, $budget, $travelers, $days, $interestTokens);
            $results[] = $row;
        }

        usort($results, static fn(array $a, array $b): int => ($b['_score'] <=> $a['_score']));
        $results = array_slice($results, 0, 5);
    } catch (Throwable $exception) {
        $errors[] = 'Unable to fetch travel recommendations. Please verify your database settings and table schema.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>GoGlobal AI Travel Bot</title>
    <style>
        :root { color-scheme: light dark; }
        body { font-family: Arial, sans-serif; margin: 2rem auto; max-width: 880px; padding: 0 1rem; }
        form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        form label { display: grid; gap: 6px; font-size: 0.95rem; }
        form textarea, form input, button { padding: 10px; font: inherit; }
        form .full { grid-column: 1 / -1; }
        button { cursor: pointer; }
        .card { border: 1px solid #bbb; border-radius: 8px; padding: 12px; margin-top: 12px; }
        .best { border-color: #2ca58d; box-shadow: 0 0 0 1px #2ca58d inset; }
        .error { color: #c53030; margin-top: 10px; }
        .muted { opacity: 0.8; }
    </style>
</head>
<body>
    <h1>GoGlobal AI Travel Bot</h1>
    <p class="muted">Tell the bot where you want to go, budget, and preferences. It ranks the best package and provides alternatives.</p>

    <form method="post">
        <label>
            Where to go?
            <input type="text" name="destination" value="<?= htmlspecialchars($destination, ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. Istanbul">
        </label>
        <label>
            Budget (PKR)
            <input type="number" name="budget" min="0" step="1000" value="<?= htmlspecialchars((string) ($budget > 0 ? $budget : ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. 250000">
        </label>
        <label>
            Travelers
            <input type="number" name="travelers" min="1" value="<?= htmlspecialchars((string) ($travelers > 0 ? $travelers : ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. 2">
        </label>
        <label>
            Days
            <input type="number" name="days" min="1" value="<?= htmlspecialchars((string) ($days > 0 ? $days : ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. 5">
        </label>
        <label class="full">
            Interests (comma or space separated)
            <textarea class="full" name="interests" rows="3" placeholder="e.g. beach, family, shopping"><?= htmlspecialchars($interests, ENT_QUOTES, 'UTF-8') ?></textarea>
        </label>
        <button class="full" type="submit">Get AI Recommendation</button>
    </form>

    <?php foreach ($errors as $error): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endforeach; ?>

    <?php if ($searched && $errors === []): ?>
        <h2>Recommendations</h2>
        <?php if ($results === []): ?>
            <p>No matching options found. Try widening budget or destination preferences.</p>
        <?php endif; ?>

        <?php foreach ($results as $index => $item): ?>
            <section class="card <?= $index === 0 ? 'best' : '' ?>">
                <h3>
                    <?= $index === 0 ? 'Best Match: ' : 'Alternative: ' ?>
                    <?= htmlspecialchars(getDisplayName($item, array_keys($item)), ENT_QUOTES, 'UTF-8') ?>
                </h3>
                <?php if (isset($item['destination'])): ?>
                    <p><strong>Destination:</strong> <?= htmlspecialchars((string) $item['destination'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <?php if (isset($item['price'])): ?>
                    <p><strong>Price:</strong> <?= htmlspecialchars((string) $item['price'], ENT_QUOTES, 'UTF-8') ?> <?= isset($item['currency']) ? htmlspecialchars((string) $item['currency'], ENT_QUOTES, 'UTF-8') : 'PKR' ?></p>
                <?php endif; ?>
                <?php if (isset($item['days']) || isset($item['nights'])): ?>
                    <p><strong>Duration:</strong> <?= htmlspecialchars((string) ($item['days'] ?? ((int) $item['nights'] + 1)), ENT_QUOTES, 'UTF-8') ?> days</p>
                <?php endif; ?>
                <?php if (isset($item['description']) && trim((string) $item['description']) !== ''): ?>
                    <p><?= htmlspecialchars((string) $item['description'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <p><strong>AI score:</strong> <?= htmlspecialchars((string) ($item['_score'] ?? 0), ENT_QUOTES, 'UTF-8') ?></p>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
