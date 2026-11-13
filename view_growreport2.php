<?php
session_start();

require($_SERVER['DOCUMENT_ROOT'] . '/../secure/db_config.php');

$growreport_id = (int)($_GET['id'] ?? $_POST['report_id'] ?? 0);
$action = $_GET['action'] ?? '';

if ($action !== '') {
    ini_set('display_errors', 0);
} else {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
}
error_reporting(E_ALL);

function vg2_json($data) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit();
}

function vg2_substr($text, $start, $length) {
    $text = (string)$text;
    return function_exists('mb_substr') ? mb_substr($text, $start, $length, 'UTF-8') : substr($text, $start, $length);
}

function vg2_humidity_color($value) {
    if ($value === null || trim((string)$value) === '' || !is_numeric($value)) return '#1565C0';
    $n = (float)$value;
    if ($n < 30) return '#F57C00';
    if ($n <= 80) return '#1565C0';
    return '#C62828';
}

/**
 * CO2 display status is recalculated from stored readings so colors persist
 * after reload. The unspecified 1201–1500 interval keeps the previous red-text,
 * gray-stripe appearance.
 */
function vg2_co2_status($value1, $value2) {
    $values = [];
    foreach ([$value1, $value2] as $value) {
        if ($value !== null && trim((string)$value) !== '' && is_numeric($value)) {
            $values[] = (float)$value;
        }
    }
    if (!$values) return ['stripe' => false, 'color' => '#c62828'];
    foreach ($values as $value) {
        if ($value > 1500) return ['stripe' => true, 'color' => '#1565c0'];
    }
    foreach ($values as $value) {
        if ($value >= 600 && $value <= 1200) return ['stripe' => true, 'color' => '#757575'];
    }
    if (max($values) < 600) return ['stripe' => false, 'color' => '#c62828'];
    return ['stripe' => true, 'color' => '#c62828'];
}

/**
 * Normalizes saved fertilizer data and totals liquid milliliters separately
 * from dry fertilizer scoops. One scoop represents 50 ml; old dry records
 * that stored only `ml` are converted to the closest number of scoops.
 */

/** Vrátí barvy pruhů podle vybraných světel. Žlutá skupina sdílí jeden pruh. */
function vg2_lighting_stripe_colors(array $lights): array {
    $colors = [];
    $yellowStripeAdded = false;
    $yellowNames = ['CMH/LEC', 'LED', 'CFL', 'T5', 'Plazmová výbojka'];

    foreach ($lights as $light) {
        if (!is_array($light)) continue;
        $name = trim((string)($light['name'] ?? ''));

        if ($name === 'HPS') {
            $colors[] = '#f57c00'; // oranžová
        } elseif ($name === 'MH') {
            $colors[] = '#81d4fa'; // světle modrá
        } elseif ($name === 'UVA/UVB') {
            $colors[] = '#ab47bc'; // fialová
        } elseif (in_array($name, $yellowNames, true) && !$yellowStripeAdded) {
            $watts = $name === 'LED' ? ($light['max_watts'] ?? 0) : ($light['watts'] ?? 0);
            $watts = is_numeric($watts) ? (float)$watts : 0;
            if ($watts <= 150) {
                $colors[] = '#fff59d'; // světle žlutá
            } elseif ($watts <= 300) {
                $colors[] = '#ffeb3b'; // žlutá
            } else {
                $colors[] = '#f9a825'; // tmavě žlutá
            }
            $yellowStripeAdded = true;
        }
    }
    return $colors;
}

function vg2_fertilizer_summary(array $items, array $knownDryNames = []) {
    $clean = [];
    $totalMl = 0;
    $totalScoops = 0;
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $name = trim(strip_tags((string)($item['name'] ?? '')));
        if ($name === '') continue;
        $rawKind = strtolower((string)($item['kind'] ?? ''));
        $isDry = $rawKind === 'dry' || array_key_exists('scoops', $item) || ($rawKind === '' && in_array($name, $knownDryNames, true));
        if ($isDry) {
            if (isset($item['scoops']) && filter_var($item['scoops'], FILTER_VALIDATE_INT) !== false) {
                $scoops = (int)$item['scoops'];
            } else {
                $legacyMl = (int)($item['ml'] ?? 50);
                $scoops = (int)round($legacyMl / 50);
            }
            $scoops = max(1, min(40, $scoops));
            $clean[] = ['name' => vg2_substr($name, 0, 80), 'kind' => 'dry', 'scoops' => $scoops];
            $totalScoops += $scoops;
        } else {
            if (mb_strtolower($name, 'UTF-8') === mb_strtolower('Melasový čaj', 'UTF-8')) {
                // Starší záznamy mohly obsahovat jen ml; převedeme je na počet lžiček.
                $waterL = (isset($item['water_l']) && is_numeric($item['water_l']))
                    ? max(0.5, min(20, round((float)$item['water_l'] * 2) / 2))
                    : 0;
                $teaspoons = isset($item['teaspoons']) && is_numeric($item['teaspoons'])
                    ? max(1, min(40, (int)round((float)$item['teaspoons'])))
                    : max(1, min(40, (int)round(((float)($item['ml'] ?? 5)) / 5)));
                $clean[] = ['name' => vg2_substr($name, 0, 80), 'kind' => 'liquid', 'ml' => 5 * $teaspoons, 'water_l' => $waterL, 'teaspoons' => $teaspoons];
                continue;
            }
            $ml = filter_var($item['ml'] ?? null, FILTER_VALIDATE_INT);
            if ($ml === false || $ml === null) continue;
            $ml = max(1, min(500, (int)$ml));
            $clean[] = ['name' => vg2_substr($name, 0, 80), 'kind' => 'liquid', 'ml' => $ml];
            $totalMl += $ml;
        }
    }
    return ['items' => $clean, 'total_ml' => $totalMl, 'total_scoops' => $totalScoops];
}

/** Sestaví čitelný denní plán pouze z uložených údajů zalévání a hnojiv. */
function vg2_daily_task_text(int $globalDay, array $watering, array $fertilizerItems, bool $fertilized, array $potNames = []): string {
    $lines = ['Den ' . $globalDay . '.'];
    $waterParts = [];
    ksort($watering, SORT_NUMERIC);
    foreach ($watering as $potNumber => $amount) {
        $amount = is_numeric($amount) ? (float)$amount : 0;
        if ($amount <= 0) continue;
        $amountText = ($amount == (int)$amount) ? (string)(int)$amount : number_format($amount, 1, ',', '');
        $potNumberInt = (int)$potNumber;
        $potName = trim((string)($potNames[(string)$potNumberInt] ?? ''));
        if ($potName === '') $potName = 'Květináč ' . $potNumberInt;
        $waterParts[] = $potName . ' = ' . $amountText . ' L';
    }
    if ($waterParts) $lines[] = 'Zalito: ' . implode(', ', $waterParts);

    $fertilizerParts = [];
    foreach ($fertilizerItems as $item) {
        if (!is_array($item)) continue;
        $name = trim((string)($item['name'] ?? ''));
        if ($name === '') continue;
        $kind = strtolower((string)($item['kind'] ?? ''));
        if ($kind === 'dry' || array_key_exists('scoops', $item)) {
            $scoops = max(0, (int)($item['scoops'] ?? 0));
            if ($scoops < 1) continue;
            $unit = $scoops === 1 ? 'odměrka' : (($scoops >= 2 && $scoops <= 4) ? 'odměrky' : 'odměrek');
            $fertilizerParts[] = $name . ' = ' . $scoops . ' ' . $unit;
        } else {
            if (mb_strtolower($name, 'UTF-8') === mb_strtolower('Melasový čaj', 'UTF-8') && isset($item['water_l'], $item['teaspoons'])) {
                $waterText = number_format((float)$item['water_l'], 1, ',', '');
                $spoons = (int)$item['teaspoons'];
                $tablespoons = (int)floor((float)$item['water_l'] / 2);
                $remainingTeaspoons = max(0, $spoons - ($tablespoons * 4));
                $spoonText = ($tablespoons > 0 ? $tablespoons . ' polévková lžíce' . ($tablespoons === 1 ? '' : 'e') : '') . ($remainingTeaspoons > 0 ? (($tablespoons > 0 ? ' + ' : '') . $remainingTeaspoons . ' čajové lžičky') : '');
                $fertilizerParts[] = $name . ' = ' . $waterText . ' L vody, ' . $spoonText;
                continue;
            }
            $ml = max(0, (int)($item['ml'] ?? 0));
            if ($ml < 1) continue;
            if ($ml >= 100 && $ml % 100 === 0) {
                $fertilizerParts[] = $name . ' = ' . (int)($ml / 100) . ' deci';
            } else {
                $fertilizerParts[] = $name . ' = ' . $ml . ' ml';
            }
        }
    }
    if ($fertilizerParts) $lines[] = 'Hnojiva: ' . implode(', ', $fertilizerParts);
    elseif ($fertilized) $lines[] = 'Hnojiva: záznam označen jako zahnojeno, bez rozepsaných položek.';
    return implode("\n", $lines);
}

// Older versions used user_id/kind/slot_number/name; newer versions use JSON columns.
/** Sanitizuje nastavení automatického hnojení s oddělenou recepturou pro růst a květ. */
function vg2_auto_fertilizer_clean_recipe_items($rawItems): array {
    if (!is_array($rawItems)) throw new InvalidArgumentException('Seznam hnojiv v receptuře nemá správný formát.');
    $items = []; $seen = [];
    foreach ($rawItems as $item) {
        if (!is_array($item)) continue;
        $itemName = vg2_substr(trim(strip_tags((string)($item['name'] ?? ''))), 0, 80);
        $kind = strtolower((string)($item['kind'] ?? ''));
        if ($itemName === '' || !in_array($kind, ['liquid', 'dry'], true)) {
            throw new InvalidArgumentException('Každé hnojivo musí mít název a platný druh.');
        }
        $key = $kind . '|' . mb_strtolower($itemName, 'UTF-8');
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        if ($kind === 'dry') {
            $base = filter_var($item['base_scoops'] ?? null, FILTER_VALIDATE_INT);
            if ($base === false || $base < 1 || $base > 40) throw new InvalidArgumentException('Dávka suchého hnojiva musí být 1 až 40 odměrek.');
            $items[] = ['name' => $itemName, 'kind' => 'dry', 'base_scoops' => (int)$base];
        } else {
            $base = filter_var($item['base_ml'] ?? null, FILTER_VALIDATE_INT);
            if ($base === false || $base < 1 || $base > 500) throw new InvalidArgumentException('Dávka tekutého hnojiva musí být 1 až 500 ml.');
            $items[] = ['name' => $itemName, 'kind' => 'liquid', 'base_ml' => (int)$base];
        }
    }
    return $items;
}

/** Sanitizuje automatické hnojení: dvě receptury konkrétního growreportu a týdenní dávky. */
function vg2_auto_fertilizer_clean_settings(array $input, int $maxWeek): array {
    $frequency = filter_var($input['frequency'] ?? null, FILTER_VALIDATE_INT);
    if ($frequency === false || !in_array((int)$frequency, [2, 3, 4, 5], true)) {
        throw new InvalidArgumentException('Vyber interval mezi zálivkami 2, 3, 4 nebo 5 dní.');
    }
    $maxWeek = max(1, min(200, $maxWeek));
    $allowedIncreases = array_merge([0], range(10, 100, 10), range(200, 1000, 100));

    // V konkrétním growreportu se ukládají pouze receptury RŮST a KVĚT.
    // Samostatných pět receptur je uloženo zvlášť podle user_id.
    $rawRecipes = $input['recipes'] ?? null;
    if (!is_array($rawRecipes)) {
        $rawRecipes = [
            ['name' => 'RŮST', 'items' => $input['growth_items'] ?? $input['items'] ?? []],
            ['name' => 'KVĚT', 'items' => $input['flower_items'] ?? $input['items'] ?? []]
        ];
    }
    $recipes = [];
    for ($slot = 1; $slot <= 2; $slot++) {
        $raw = isset($rawRecipes[$slot - 1]) && is_array($rawRecipes[$slot - 1]) ? $rawRecipes[$slot - 1] : [];
        $items = vg2_auto_fertilizer_clean_recipe_items($raw['items'] ?? []);
        $recipes[] = ['name' => $slot === 1 ? 'RŮST' : 'KVĚT', 'items' => $items];
    }

    $rawIncreases = is_array($input['week_increases'] ?? null) ? $input['week_increases'] : [];
    $rawWeekRecipes = is_array($input['week_recipes'] ?? null) ? $input['week_recipes'] : [];
    $rawWeekDoses = is_array($input['week_doses'] ?? null) ? $input['week_doses'] : [];
    $weekIncreases = [];
    $weekRecipes = [];
    $weekDoses = [];
    for ($week = 1; $week <= $maxWeek; $week++) {
        $percentRaw = $rawIncreases[(string)$week] ?? $rawIncreases[$week] ?? 0;
        $percent = filter_var($percentRaw, FILTER_VALIDATE_INT);
        if ($percent === false || !in_array((int)$percent, $allowedIncreases, true)) {
            throw new InvalidArgumentException('Navýšení musí být 0 %, 10–100 % po deseti procentech nebo 200–1000 % po stovkách.');
        }
        $defaultSlot = $week === 1 ? 1 : 2;
        $slot = filter_var($rawWeekRecipes[(string)$week] ?? $rawWeekRecipes[$week] ?? $defaultSlot, FILTER_VALIDATE_INT);
        if ($slot === false || $slot < 1 || $slot > 7) {
            throw new InvalidArgumentException('Vyber platnou recepturu pro každý týden.');
        }
        $weekIncreases[(string)$week] = (int)$percent;
        $weekRecipes[(string)$week] = (int)$slot;

        $rawWeekDoseItems = $rawWeekDoses[(string)$week] ?? $rawWeekDoses[$week] ?? [];
        if (!is_array($rawWeekDoseItems)) continue;
        foreach ($rawWeekDoseItems as $itemKey => $rawDose) {
            $itemKey = (string)$itemKey;
            $separator = strpos($itemKey, '|');
            if ($separator === false) continue;
            $kind = substr($itemKey, 0, $separator);
            $itemName = vg2_substr(trim(strip_tags(substr($itemKey, $separator + 1))), 0, 80);
            if ($itemName === '' || !in_array($kind, ['liquid', 'dry'], true)) continue;
            $dose = filter_var($rawDose, FILTER_VALIDATE_INT);
            $maxDose = $kind === 'dry' ? 40 : 500;
            if ($dose === false || $dose < 1 || $dose > $maxDose) {
                throw new InvalidArgumentException('Upravená dávka hnojiva „' . $itemName . '“ v týdnu ' . $week . ' musí být 1 až ' . $maxDose . ($kind === 'dry' ? ' odměrek.' : ' ml.'));
            }
            $weekDoses[(string)$week][$kind . '|' . $itemName] = (int)$dose;
        }
    }
    return [
        'frequency' => (int)$frequency,
        'recipes' => $recipes,
        'week_recipes' => $weekRecipes,
        'week_increases' => $weekIncreases,
        'week_doses' => $weekDoses
    ];
}
function vg2_custom_fertilizer_schema(PDO $pdo) {
    $st = $pdo->query('SHOW COLUMNS FROM growreport_custom_fertilizers2');
    $columns = $st->fetchAll(PDO::FETCH_COLUMN, 0);
    $has = array_fill_keys($columns, true);
    if (isset($has['report_id'], $has['liquid_json'], $has['dry_json'])) return ['layout' => 'json', 'columns' => $columns];
    if (isset($has['user_id'], $has['kind'], $has['slot_number'], $has['name'])) return ['layout' => 'slots', 'columns' => $columns];
    return ['layout' => 'unknown', 'columns' => $columns];
}

// ========== AUTO-CREATE TABULKY PRO CO2 ==========
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_co2_2 (
        id INT AUTO_INCREMENT PRIMARY KEY,
        week_id INT NOT NULL,
        day_number TINYINT NOT NULL,
        label1 VARCHAR(20) NOT NULL DEFAULT 'Ráno',
        value1 SMALLINT UNSIGNED DEFAULT NULL,
        label2 VARCHAR(20) NOT NULL DEFAULT 'Večer',
        value2 SMALLINT UNSIGNED DEFAULT NULL,
        UNIQUE KEY unique_week_day (week_id, day_number),
        FOREIGN KEY (week_id) REFERENCES growreport_weeks2(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_co2_2 (
            id INT AUTO_INCREMENT PRIMARY KEY,
            week_id INT NOT NULL,
            day_number TINYINT NOT NULL,
            label1 VARCHAR(20) NOT NULL DEFAULT 'Ráno',
            value1 SMALLINT UNSIGNED DEFAULT NULL,
            label2 VARCHAR(20) NOT NULL DEFAULT 'Večer',
            value2 SMALLINT UNSIGNED DEFAULT NULL,
            UNIQUE KEY unique_week_day (week_id, day_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e2) {
        error_log("CO2 tabulka: " . $e2->getMessage());
    }
}

// ========== AUTO-CREATE TABULKY PRO TEPLOTU ==========
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_temperature_2 (
        id INT AUTO_INCREMENT PRIMARY KEY,
        week_id INT NOT NULL,
        day_number TINYINT NOT NULL,
        label1 VARCHAR(20) NOT NULL DEFAULT 'Ráno',
        value1 DECIMAL(4,1) DEFAULT NULL,
        label2 VARCHAR(20) NOT NULL DEFAULT 'Večer',
        value2 DECIMAL(4,1) DEFAULT NULL,
        UNIQUE KEY unique_week_day (week_id, day_number),
        FOREIGN KEY (week_id) REFERENCES growreport_weeks2(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_temperature_2 (
            id INT AUTO_INCREMENT PRIMARY KEY,
            week_id INT NOT NULL,
            day_number TINYINT NOT NULL,
            label1 VARCHAR(20) NOT NULL DEFAULT 'Ráno',
            value1 DECIMAL(4,1) DEFAULT NULL,
            label2 VARCHAR(20) NOT NULL DEFAULT 'Večer',
            value2 DECIMAL(4,1) DEFAULT NULL,
            UNIQUE KEY unique_week_day (week_id, day_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e2) {
        error_log("Teplota tabulka: " . $e2->getMessage());
    }
}

// ========== AUTO-CREATE TABULKY PRO KVĚTINÁČE A ZALÉVÁNÍ ==========
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_pots2 (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_id INT NOT NULL,
        pot_number TINYINT NOT NULL,
        volume DECIMAL(5,1) NOT NULL,
        UNIQUE KEY unique_report_pot (report_id, pot_number),
        FOREIGN KEY (report_id) REFERENCES growreports2(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_pots2 (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_id INT NOT NULL,
            pot_number TINYINT NOT NULL,
            volume DECIMAL(5,1) NOT NULL,
            UNIQUE KEY unique_report_pot (report_id, pot_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e2) {
        error_log("Pots tabulka: " . $e2->getMessage());
    }
}

// Vlastní názvy květináčů jsou v samostatné tabulce, aby nebylo třeba měnit staré schéma growreport_pots2.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_pot_names2 (
        report_id INT NOT NULL,
        pot_number TINYINT NOT NULL,
        pot_name VARCHAR(100) NOT NULL DEFAULT '',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (report_id, pot_number),
        KEY idx_growreport_pot_names_report (report_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    error_log('Tabulka vlastních názvů květináčů: ' . $e->getMessage());
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_watering2 (
        id INT AUTO_INCREMENT PRIMARY KEY,
        week_id INT NOT NULL,
        day_number TINYINT NOT NULL,
        pot_number TINYINT NOT NULL,
        amount DECIMAL(7,2) NOT NULL DEFAULT 0,
        auto_generated TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY unique_week_day_pot (week_id, day_number, pot_number),
        FOREIGN KEY (week_id) REFERENCES growreport_weeks2(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_watering2 (
            id INT AUTO_INCREMENT PRIMARY KEY,
            week_id INT NOT NULL,
            day_number TINYINT NOT NULL,
            pot_number TINYINT NOT NULL,
            amount DECIMAL(7,2) NOT NULL DEFAULT 0,
            auto_generated TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY unique_week_day_pot (week_id, day_number, pot_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e2) {
        error_log("Watering tabulka: " . $e2->getMessage());
    }
}

// Označení automaticky vytvořených zálivek, aby šel rozpis bezpečně obnovit bez mazání jiných dní.
$wateringAutoSchemaError = null;
try {
    $wateringColumns = $pdo->query('SHOW COLUMNS FROM growreport_watering2')->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('auto_generated', $wateringColumns, true)) {
        $pdo->exec('ALTER TABLE growreport_watering2 ADD COLUMN auto_generated TINYINT(1) NOT NULL DEFAULT 0');
    }
} catch (Throwable $e) {
    $wateringAutoSchemaError = $e->getMessage();
    error_log('Označení automatického zalévání: ' . $e->getMessage());
}

// Automatický rozpis ukládá stejné desetinné dávky, jaké jsou zobrazené v kalkulační tabulce.
try {
    $amountColumn = $pdo->query("SHOW COLUMNS FROM growreport_watering2 LIKE 'amount'")->fetch(PDO::FETCH_ASSOC);
    if ($amountColumn && stripos((string)$amountColumn['Type'], 'decimal(7,2)') !== 0) {
        $pdo->exec('ALTER TABLE growreport_watering2 MODIFY amount DECIMAL(7,2) NOT NULL DEFAULT 0');
    }
} catch (Throwable $e) {
    error_log('Změna přesnosti dávek zalévání: ' . $e->getMessage());
}

// pH zalévací vody: jedna společná hodnota pro všechny květináče daného dne.
$wateringPhSchemaError = null;
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_watering_ph2 (
        id INT AUTO_INCREMENT PRIMARY KEY,
        week_id INT NOT NULL,
        day_number TINYINT NOT NULL,
        ph_value DECIMAL(3,1) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_watering_ph_week_day (week_id, day_number),
        KEY idx_watering_ph_week (week_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    $wateringPhSchemaError = $e->getMessage();
    error_log('Tabulka pH zalévání: ' . $e->getMessage());
}

// pH výtokové vody zpod květináče: také jeden záznam pro den, společný pro všechny květináče.
$wateringRunoffPhSchemaError = null;
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_watering_runoff_ph2 (
        id INT AUTO_INCREMENT PRIMARY KEY,
        week_id INT NOT NULL,
        day_number TINYINT NOT NULL,
        ph_value DECIMAL(3,1) NULL DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_watering_runoff_ph_week_day (week_id, day_number),
        KEY idx_watering_runoff_ph_week (week_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    $wateringRunoffPhSchemaError = $e->getMessage();
    error_log('Tabulka pH výtoku zalévání: ' . $e->getMessage());
}
try {
    $phColumn = $pdo->query("SHOW COLUMNS FROM growreport_watering_ph2 LIKE 'ph_value'")->fetch(PDO::FETCH_ASSOC);
    if ($phColumn && stripos((string)$phColumn['Type'], 'decimal(3,1)') !== 0) {
        $pdo->exec('ALTER TABLE growreport_watering_ph2 MODIFY ph_value DECIMAL(3,1) NOT NULL');
    }
    $runoffPhColumn = $pdo->query("SHOW COLUMNS FROM growreport_watering_runoff_ph2 LIKE 'ph_value'")->fetch(PDO::FETCH_ASSOC);
    if ($runoffPhColumn && (stripos((string)$runoffPhColumn['Type'], 'decimal(3,1)') !== 0 || strtoupper((string)($runoffPhColumn['Null'] ?? 'NO')) !== 'YES')) {
        $pdo->exec('ALTER TABLE growreport_watering_runoff_ph2 MODIFY ph_value DECIMAL(3,1) NULL DEFAULT NULL');
    }
    $runoffLitersColumn = $pdo->query("SHOW COLUMNS FROM growreport_watering_runoff_ph2 LIKE 'runoff_liters'")->fetch(PDO::FETCH_ASSOC);
    if (!$runoffLitersColumn) {
        $pdo->exec('ALTER TABLE growreport_watering_runoff_ph2 ADD COLUMN runoff_liters DECIMAL(4,1) NULL DEFAULT NULL AFTER ph_value');
    }
} catch (Throwable $e) {
    $wateringRunoffPhSchemaError = $wateringRunoffPhSchemaError ?: $e->getMessage();
    error_log('Rozšíření přesnosti pH a objemu výtoku: ' . $e->getMessage());
}

// ========== AUTO-CREATE TABULKY PRO TEPLOTU ==========
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_temperature_2 (
        id INT AUTO_INCREMENT PRIMARY KEY,
        week_id INT NOT NULL,
        day_number TINYINT NOT NULL,
        label1 VARCHAR(20) NOT NULL DEFAULT 'Ráno',
        value1 DECIMAL(4,1) DEFAULT NULL,
        label2 VARCHAR(20) NOT NULL DEFAULT 'Večer',
        value2 DECIMAL(4,1) DEFAULT NULL,
        UNIQUE KEY unique_week_day (week_id, day_number),
        FOREIGN KEY (week_id) REFERENCES growreport_weeks2(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_temperature_2 (
            id INT AUTO_INCREMENT PRIMARY KEY,
            week_id INT NOT NULL,
            day_number TINYINT NOT NULL,
            label1 VARCHAR(20) NOT NULL DEFAULT 'Ráno',
            value1 DECIMAL(4,1) DEFAULT NULL,
            label2 VARCHAR(20) NOT NULL DEFAULT 'Večer',
            value2 DECIMAL(4,1) DEFAULT NULL,
            UNIQUE KEY unique_week_day (week_id, day_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e2) {
        error_log("Temperature tabulka: " . $e2->getMessage());
    }
}


// Vlhkost: vlastní tabulka, aby se hodnoty ukládaly odděleně od teploty.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_humidity_2 (
        id INT AUTO_INCREMENT PRIMARY KEY,
        week_id INT NOT NULL,
        day_number TINYINT NOT NULL,
        label1 VARCHAR(20) NOT NULL DEFAULT 'Ráno',
        value1 DECIMAL(4,1) DEFAULT NULL,
        label2 VARCHAR(20) NOT NULL DEFAULT 'Večer',
        value2 DECIMAL(4,1) DEFAULT NULL,
        UNIQUE KEY unique_week_day (week_id, day_number),
        KEY idx_humidity_week (week_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    error_log("Vlhkost tabulka: " . $e->getMessage());
}

// Světla: ukládají se pouze změny od konkrétního dne; další dny a týdny je přebírají.
$lightsSchemaError = null;
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_lights2 (
        id INT AUTO_INCREMENT PRIMARY KEY,
        week_id INT NOT NULL,
        day_number TINYINT NOT NULL DEFAULT 1,
        settings_json LONGTEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_growreport_lights_week_day (week_id, day_number),
        KEY idx_growreport_lights_week (week_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Migrace starší tabulky: dosavadní týdenní nastavení se považuje za změnu od 1. dne.
    $lightColumns = $pdo->query('SHOW COLUMNS FROM growreport_lights2')->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('day_number', $lightColumns, true)) {
        $pdo->exec('ALTER TABLE growreport_lights2 ADD COLUMN day_number TINYINT NOT NULL DEFAULT 1 AFTER week_id');
    }

    // Odstranit starý UNIQUE(week_id), který by nedovoloval ukládat více změn v jednom týdnu.
    $lightIndexes = $pdo->query('SHOW INDEX FROM growreport_lights2')->fetchAll(PDO::FETCH_ASSOC);
    $indexesByName = [];
    foreach ($lightIndexes as $indexRow) {
        $indexesByName[$indexRow['Key_name']]['unique'] = ((int)$indexRow['Non_unique'] === 0);
        $indexesByName[$indexRow['Key_name']]['columns'][(int)$indexRow['Seq_in_index']] = $indexRow['Column_name'];
    }
    foreach ($indexesByName as $indexName => $indexInfo) {
        $columns = $indexInfo['columns'] ?? [];
        ksort($columns);
        if ($indexName !== 'PRIMARY' && !empty($indexInfo['unique']) && array_values($columns) === ['week_id']) {
            $safeIndexName = str_replace('`', '', $indexName);
            $pdo->exec('ALTER TABLE growreport_lights2 DROP INDEX `' . $safeIndexName . '`');
        }
    }
    $lightIndexes = $pdo->query('SHOW INDEX FROM growreport_lights2')->fetchAll(PDO::FETCH_ASSOC);
    $hasWeekDayUnique = false;
    foreach ($lightIndexes as $indexRow) {
        if ($indexRow['Key_name'] === 'uq_growreport_lights_week_day') $hasWeekDayUnique = true;
    }
    if (!$hasWeekDayUnique) {
        $pdo->exec('ALTER TABLE growreport_lights2 ADD UNIQUE KEY uq_growreport_lights_week_day (week_id, day_number)');
    }
} catch (Throwable $e) {
    $lightsSchemaError = $e->getMessage();
    error_log("Světla tabulka/migrace: " . $e->getMessage());
}

// Větráky a odtahy: ukládá se pouze změna od konkrétního dne, další dny/týdny nastavení přebírají.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_airflow2 (
        id INT AUTO_INCREMENT PRIMARY KEY,
        week_id INT NOT NULL,
        day_number TINYINT NOT NULL,
        settings_json LONGTEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_growreport_airflow_week_day (week_id, day_number),
        KEY idx_growreport_airflow_week (week_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    error_log("Větráky/odtahy tabulka: " . $e->getMessage());
}

// Problém: označení platí pouze pro vybraný den; nepřenáší se do jiných dnů/týdnů.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_problem_marks2 (
        id INT AUTO_INCREMENT PRIMARY KEY,
        week_id INT NOT NULL,
        day_number TINYINT NOT NULL,
        marked TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY uq_problem_week_day (week_id, day_number),
        KEY idx_problem_week (week_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    error_log("Problém – tabulka: " . $e->getMessage());
}

// Galerie: až 10 obrázků/videí pro každý týden.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_gallery2 (
        id INT AUTO_INCREMENT PRIMARY KEY,
        week_id INT NOT NULL,
        file_path VARCHAR(512) NOT NULL,
        media_type VARCHAR(12) NOT NULL DEFAULT 'image',
        original_name VARCHAR(255) NOT NULL DEFAULT '',
        sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_growreport_gallery_week (week_id),
        KEY idx_growreport_gallery_order (week_id, sort_order, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    error_log('Galerie growreportu: ' . $e->getMessage());
}

// Druhá fotografie ve sloupci Sekce 3 se ukládá zvlášť, aby se nemusela měnit původní tabulka týdnů.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_section3_photo2 (
        id INT AUTO_INCREMENT PRIMARY KEY,
        week_id INT NOT NULL,
        file_path VARCHAR(512) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_growreport_section3_photo2_week (week_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    error_log('Druhá fotografie sekce 3: ' . $e->getMessage());
}

// Kalendář growreportu: první den týdne a skutečné datum prvního dne (pro výpočet úplňků).
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_calendar_settings2 (
        report_id INT NOT NULL PRIMARY KEY,
        first_weekday VARCHAR(16) NOT NULL DEFAULT 'pondělí',
        start_date DATE DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    error_log('Kalendář growreportu: ' . $e->getMessage());
}

// Úplňky spravuje administrátor centrálně; datum může být v libovolném roce.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_full_moons2 (
        id INT AUTO_INCREMENT PRIMARY KEY,
        moon_date DATE NOT NULL UNIQUE,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_growreport_full_moon_date (moon_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    error_log('Kalendář úplňků: ' . $e->getMessage());
}

// Záznamy pěstitelských zásahů (zastřižení, FIM, LST) pro konkrétní datum growreportu.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_pruning_actions2 (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_id INT NOT NULL,
        week_number INT NOT NULL,
        day_number TINYINT NOT NULL,
        record_date DATE NOT NULL,
        action_type TINYINT NOT NULL,
        completed TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_pruning_report_date_type (report_id, record_date, action_type),
        KEY idx_pruning_report_date (report_id, record_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Jeden den může mít libovolný počet samostatných zásahů, i opakovaně stejného typu.
    $idxRows = $pdo->query('SHOW INDEX FROM growreport_pruning_actions2')->fetchAll(PDO::FETCH_ASSOC);
    $uniqueKeys = [];
    foreach ($idxRows as $r) if ((int)$r['Non_unique'] === 0 && (string)$r['Key_name'] !== 'PRIMARY') $uniqueKeys[(string)$r['Key_name']] = true;
    foreach (array_keys($uniqueKeys) as $key) $pdo->exec('ALTER TABLE growreport_pruning_actions2 DROP INDEX `' . str_replace('`', '``', $key) . '`');
} catch (Throwable $e) {
    error_log('Growreport zásahy/migrace: ' . $e->getMessage());
}

// Tři uživatelské funkce: vlastní názvy/barvy a samostatné záznamy podle data.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_custom_functions2 (
        report_id INT NOT NULL,
        slot_number TINYINT NOT NULL,
        function_name VARCHAR(100) NOT NULL DEFAULT '',
        color VARCHAR(20) NOT NULL DEFAULT '#ff9100',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (report_id, slot_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_custom_function_marks2 (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_id INT NOT NULL,
        slot_number TINYINT NOT NULL,
        week_number INT NOT NULL,
        day_number TINYINT NOT NULL,
        record_date DATE NOT NULL,
        completed TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_custom_function_report_slot_date (report_id, slot_number, record_date),
        KEY idx_custom_function_report_date (report_id, record_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    error_log('Tabulky vlastních funkcí T: ' . $e->getMessage());
}

// Cena elektřiny pro energetický přehled E.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_electricity2 (
        report_id INT NOT NULL PRIMARY KEY,
        price_per_kwh DECIMAL(10,4) NOT NULL DEFAULT 0,
        extra_device_watts DECIMAL(10,2) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) { error_log('Tabulka elektřiny E: ' . $e->getMessage()); }
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_electricity_price_history2 (id INT AUTO_INCREMENT PRIMARY KEY, report_id INT NOT NULL, effective_date DATE NOT NULL, price_per_kwh DECIMAL(10,4) NOT NULL, day_price_per_kwh DECIMAL(10,4) NOT NULL DEFAULT 0, night_price_per_kwh DECIMAL(10,4) NOT NULL DEFAULT 0, day_hours DECIMAL(5,2) NOT NULL DEFAULT 24, night_hours DECIMAL(5,2) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_electricity_price_date (report_id,effective_date), KEY idx_electricity_price_lookup (report_id,effective_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) { error_log('Historie cen elektřiny E: '.$e->getMessage()); }
// Rozšíření nastavení E o dva tarify a délku denního/nočního tarifu.
foreach ([
    'day_price_per_kwh' => 'DECIMAL(10,4) NOT NULL DEFAULT 0',
    'night_price_per_kwh' => 'DECIMAL(10,4) NOT NULL DEFAULT 0',
    'day_hours' => 'DECIMAL(5,2) NOT NULL DEFAULT 16',
    'night_hours' => 'DECIMAL(5,2) NOT NULL DEFAULT 8'
] as $column => $definition) {
    try { $pdo->exec("ALTER TABLE growreport_electricity2 ADD COLUMN {$column} {$definition}"); }
    catch (Throwable $e) { /* sloupec již existuje nebo migrace není dostupná */ }
}

// Hnojiva: bez cizích klíčů, aby vytvoření tabulek nezáviselo na přesném typu ID v existujících tabulkách.
// Hnojiva DEBUG
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_fertilizers2 (
        id INT AUTO_INCREMENT PRIMARY KEY,
        week_id INT NOT NULL,
        day_number TINYINT NOT NULL,
        mode VARCHAR(16) NOT NULL DEFAULT 'preset',
        items_json LONGTEXT NOT NULL,
        total_ml SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        auto_generated TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_fertilizer_week_day (week_id, day_number),
        KEY idx_fertilizer_week (week_id),
        KEY idx_fertilizer_auto (auto_generated)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Kompatibilní migrace: označení automaticky vytvořených záznamů pro bezpečnou obnovu plánu.
    $fertilizerColumns = $pdo->query('SHOW COLUMNS FROM growreport_fertilizers2')->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('auto_generated', $fertilizerColumns, true)) {
        $pdo->exec('ALTER TABLE growreport_fertilizers2 ADD COLUMN auto_generated TINYINT(1) NOT NULL DEFAULT 0 AFTER total_ml');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_custom_fertilizers2 (
        report_id INT NOT NULL PRIMARY KEY,
        liquid_json LONGTEXT NOT NULL,
        dry_json LONGTEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

} catch (Throwable $e) {
    error_log('Growreport fertilizer tables: ' . $e->getMessage());
    if ($action !== '') {
        http_response_code(500);
        vg2_json(['success' => false, 'message' => 'Chyba databáze při přípravě tabulek hnojiv: ' . $e->getMessage()]);
    }
    http_response_code(500);
    die('Nepodařilo se připravit tabulky hnojiv. Zkontroluj PHP error log.');
}

// Stav potvrzení denního plánu zalití/hnojení se ukládá samostatně.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_daily_tasks2 (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_id INT NOT NULL,
        week_id INT NOT NULL,
        day_number TINYINT NOT NULL,
        completed TINYINT(1) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_daily_task_week_day (week_id, day_number),
        KEY idx_daily_task_report (report_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

} catch (Throwable $e) {
    error_log('Tabulka potvrzení denních úkonů: ' . $e->getMessage());
}

// Nastavení automatického hnojení je oddělené od běžných denních záznamů hnojiv.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_auto_fertilizer_settings2 (
        report_id INT NOT NULL PRIMARY KEY,
        settings_json LONGTEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Společná knihovna pěti samostatných receptur pro všechny Growreporty jednoho uživatele.
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_user_fertilizer_recipes2 (
        user_id INT NOT NULL,
        slot_number TINYINT UNSIGNED NOT NULL,
        name VARCHAR(80) NOT NULL,
        items_json LONGTEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, slot_number),
        KEY idx_user_fertilizer_recipes_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Až deset pojmenovaných uložených receptur pro každou fázi (RŮST/KVĚT).
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_fertilizer_recipe_library2 (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        phase VARCHAR(10) NOT NULL,
        recipe_name VARCHAR(80) NOT NULL,
        items_json LONGTEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_fertilizer_recipe_library_user_phase (user_id, phase)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    error_log('Nastavení automatického hnojení: ' . $e->getMessage());
}

// Evidence postřiků: jeden záznam může obsahovat až čtyři zvolené přípravky.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_spray_actions2 (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_id INT NOT NULL,
        week_id INT NOT NULL,
        week_number INT NOT NULL,
        day_number TINYINT NOT NULL,
        record_date DATE NOT NULL,
        sprays_json LONGTEXT NOT NULL,
        completed TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_spray_report_date (report_id, record_date),
        KEY idx_spray_week_day (week_id, day_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    error_log('Tabulka postřiků: ' . $e->getMessage());
}

// Označení dne, kdy je nasazena síť.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_net_marks2 (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_id INT NOT NULL,
        week_id INT NOT NULL,
        week_number INT NOT NULL,
        day_number TINYINT NOT NULL,
        record_date DATE NOT NULL,
        marked TINYINT(1) NOT NULL DEFAULT 1,
        completed TINYINT(1) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_net_week_day (week_id, day_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // I síť lze zaznamenat vícekrát ve stejném dni jako samostatné položky.
    $idxRows = $pdo->query('SHOW INDEX FROM growreport_net_marks2')->fetchAll(PDO::FETCH_ASSOC);
    $uniqueKeys = [];
    foreach ($idxRows as $r) if ((int)$r['Non_unique'] === 0 && (string)$r['Key_name'] !== 'PRIMARY') $uniqueKeys[(string)$r['Key_name']] = true;
    foreach (array_keys($uniqueKeys) as $key) $pdo->exec('ALTER TABLE growreport_net_marks2 DROP INDEX `' . str_replace('`', '``', $key) . '`');
} catch (Throwable $e) {
    error_log('Tabulka označení sítě: ' . $e->getMessage());
}

// Evidence vaničky / zvlhčovače pro jednotlivé dny growreportu.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_vz_records2 (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_id INT NOT NULL,
        week_number INT NOT NULL,
        day_number TINYINT NOT NULL,
        record_date DATE NOT NULL,
        record_type VARCHAR(20) NOT NULL,
        runtime_hours DECIMAL(4,1) DEFAULT NULL,
        anchor_future TINYINT(1) NOT NULL DEFAULT 0,
        auto_propagated TINYINT(1) NOT NULL DEFAULT 0,
        confirmed_copy TINYINT(1) NOT NULL DEFAULT 0,
        source_anchor_date DATE DEFAULT NULL,
        completed TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_vz_report_date_type (report_id, record_date, record_type),
        KEY idx_vz_report_week_day (report_id, week_number, day_number),
        KEY idx_vz_source_anchor (report_id, source_anchor_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Každé uložení V/Z je samostatný záznam, i když jde o stejný typ a datum.
    $idxRows = $pdo->query('SHOW INDEX FROM growreport_vz_records2')->fetchAll(PDO::FETCH_ASSOC);
    $uniqueKeys = [];
    foreach ($idxRows as $r) if ((int)$r['Non_unique'] === 0 && (string)$r['Key_name'] !== 'PRIMARY') $uniqueKeys[(string)$r['Key_name']] = true;
    foreach (array_keys($uniqueKeys) as $key) $pdo->exec('ALTER TABLE growreport_vz_records2 DROP INDEX `' . str_replace('`', '``', $key) . '`');
    $vzColumns = $pdo->query('SHOW COLUMNS FROM growreport_vz_records2')->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('anchor_future', $vzColumns, true)) $pdo->exec('ALTER TABLE growreport_vz_records2 ADD COLUMN anchor_future TINYINT(1) NOT NULL DEFAULT 0 AFTER runtime_hours');
    if (!in_array('auto_propagated', $vzColumns, true)) $pdo->exec('ALTER TABLE growreport_vz_records2 ADD COLUMN auto_propagated TINYINT(1) NOT NULL DEFAULT 0 AFTER anchor_future');
    if (!in_array('confirmed_copy', $vzColumns, true)) $pdo->exec('ALTER TABLE growreport_vz_records2 ADD COLUMN confirmed_copy TINYINT(1) NOT NULL DEFAULT 0 AFTER auto_propagated');
    if (!in_array('source_anchor_date', $vzColumns, true)) $pdo->exec('ALTER TABLE growreport_vz_records2 ADD COLUMN source_anchor_date DATE DEFAULT NULL AFTER confirmed_copy');
    // Odstraníme staré automatické předvyplnění všech budoucích dnů z předchozí verze.
    // Nově zůstávají pouze budoucí dny, které uživatel výslovně uložil.
    $pdo->exec('DELETE FROM growreport_vz_records2 WHERE auto_propagated=1 AND confirmed_copy=0');
} catch (Throwable $e) {
    error_log('Tabulka evidence V/Z: ' . $e->getMessage());
}

// Stav Splněno je sloupec přímo v tabulce každého konkrétního úkonu.
// Každá migrace je samostatná, aby chyba jedné tabulky nezablokovala ostatní.
$actionCompletionTables = [
    'growreport_pruning_actions2',
    'growreport_vz_records2',
    'growreport_spray_actions2',
    'growreport_net_marks2',
    'growreport_custom_function_marks2'
];
foreach ($actionCompletionTables as $completionTable) {
    try {
        $columns = $pdo->query('SHOW COLUMNS FROM `' . $completionTable . '`')->fetchAll(PDO::FETCH_COLUMN, 0);
        if (!in_array('completed', $columns, true)) {
            $pdo->exec('ALTER TABLE `' . $completionTable . '` ADD COLUMN completed TINYINT(1) NOT NULL DEFAULT 0');
        }
    } catch (Throwable $e) {
        error_log('Migrace completed v ' . $completionTable . ': ' . $e->getMessage());
        if ($action !== '') {
            http_response_code(500);
            vg2_json(['success'=>false, 'message'=>'Nelze připravit sloupec completed v tabulce ' . $completionTable . ': ' . $e->getMessage()]);
        }
    }
}

// Splnění se ukládá výhradně do completed ve zdrojových tabulkách jednotlivých záznamů.


// Audit světla a proudění vzduchu – samostatná data pro každý Growreport.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_audit2 (
        report_id INT NOT NULL PRIMARY KEY,
        settings_json LONGTEXT NOT NULL,
        results_json LONGTEXT DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_growreport_audit_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) { error_log('Growreport audit tabulka: ' . $e->getMessage()); }

if ($growreport_id === 0) {
    if ($action !== '') vg2_json(['success' => false, 'message' => 'ID growreportu není nastaveno.']);
    die("ID growreportu není nastaveno.");
}

$sql = "SELECT g.*, u.username AS author
        FROM growreports2 g
        JOIN uzivatele u ON g.user_id = u.id
        WHERE g.id = :id";
$stmt = $pdo->prepare($sql);
$stmt->execute(['id' => $growreport_id]);
$growreport = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$growreport) {
    if ($action !== '') vg2_json(['success' => false, 'message' => 'Growreport nebyl nalezen.']);
    die("Growreport nebyl nalezen.");
}

$isOwner = isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$growreport['user_id'];
// Odkaz z veřejné galerie index.php musí být pouze pro prohlížení i pro samotného vlastníka.
$isPublicView = isset($_GET['public_view']) && (string)$_GET['public_view'] === '1';
$canEdit = $isOwner && !$isPublicView;
$isAdmin = false;
if (isset($_SESSION['user_id'])) {
    try {
        $adminCheck = $pdo->prepare('SELECT is_admin FROM uzivatele WHERE id=:uid LIMIT 1');
        $adminCheck->execute(['uid' => (int)$_SESSION['user_id']]);
        $isAdmin = ((int)$adminCheck->fetchColumn() === 1);
    } catch (Throwable $e) {
        error_log('Ověření administrátora pro úplňky: ' . $e->getMessage());
        $isAdmin = false;
    }
}

if (!$isOwner && empty($growreport['share_enabled']) && !$isAdmin) {
    if ($action !== '') vg2_json(['success' => false, 'message' => 'Nemáte oprávnění.']);
    die("Tento growreport není sdílený.");
}

// Names are used only to recognize dry fertilizers saved by older versions
// that did not store the `kind` field. New records always save `kind` explicitly.
$knownDryFertilizerNames = ['Green Crack', 'Popel', 'Vápník-Křemík', 'Popel z trávy', 'Micorize'];
try {
    $customSchemaForDryNames = vg2_custom_fertilizer_schema($pdo);
    if ($customSchemaForDryNames['layout'] === 'json') {
        $dryNamesStmt = $pdo->prepare('SELECT dry_json FROM growreport_custom_fertilizers2 WHERE report_id=:rid LIMIT 1');
        $dryNamesStmt->execute(['rid' => $growreport_id]);
        $dryNamesJson = $dryNamesStmt->fetchColumn();
        $dryNames = is_string($dryNamesJson) ? json_decode($dryNamesJson, true) : [];
        if (is_array($dryNames)) $knownDryFertilizerNames = array_values(array_unique(array_merge($knownDryFertilizerNames, array_filter(array_map('trim', $dryNames), 'strlen'))));
    } elseif ($customSchemaForDryNames['layout'] === 'slots') {
        $dryNamesStmt = $pdo->prepare("SELECT name FROM growreport_custom_fertilizers2 WHERE user_id=:uid AND kind='dry'");
        $dryNamesStmt->execute(['uid' => (int)$growreport['user_id']]);
        $dryNames = $dryNamesStmt->fetchAll(PDO::FETCH_COLUMN, 0);
        if (is_array($dryNames)) $knownDryFertilizerNames = array_values(array_unique(array_merge($knownDryFertilizerNames, array_filter(array_map('trim', $dryNames), 'strlen'))));
    }
} catch (Throwable $e) {
    error_log('Načtení názvů suchých hnojiv pro kompatibilitu: ' . $e->getMessage());
}

if ($action !== '') {
    // Veřejný náhled smí pouze načíst galerii. Všechny ostatní zápisové akce
    // jsou zablokované i na serveru, včetně případu, kdy si vlastník otevře
    // vlastní report přes odkaz z veřejné stránky index.php.
    if (in_array($action, ['gallery_list', 'action_task_states', 'watering_profiles_list', 'watering_profiles_save'], true)) {
        if (!$isOwner && empty($growreport['share_enabled']) && !$isAdmin) {
            vg2_json(['success' => false, 'message' => 'Tento growreport není veřejně dostupný.']);
        }
        if ($action === 'watering_profiles_save' && !$isAdmin) {
            vg2_json(['success' => false, 'message' => 'Do globálního seznamu historií může ukládat pouze administrátor. Ostatní uživatelé mohou uložené historie načítat a používat.']);
        }
        if ($isPublicView && $action === 'watering_profiles_save') {
            vg2_json(['success' => false, 'message' => 'Veřejný náhled dovoluje pouze načítat společné historie.']);
        }
    } else {
        if ($isPublicView) {
            vg2_json(['success' => false, 'message' => 'Veřejný náhled dovoluje pouze prohlížení.']);
        }
        $adminOnlyActions = ['full_moon_add', 'full_moon_delete'];
        if (in_array($action, $adminOnlyActions, true)) {
            if (!$isAdmin) vg2_json(['success' => false, 'message' => 'Tuto akci může provést pouze administrátor.']);
        } elseif (!$isOwner) {
            vg2_json(['success' => false, 'message' => 'Nemáte oprávnění upravovat tento growreport.']);
        }
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') vg2_json(['success' => false, 'message' => 'Neplatný požadavek.']);

    $json = json_decode(file_get_contents('php://input'), true) ?: [];

    $getWeekId = function ($wn) use ($pdo, $growreport_id) {
        $st = $pdo->prepare("SELECT id FROM growreport_weeks2 WHERE report_id=:rid AND week_number=:wn LIMIT 1");
        $st->execute(['rid' => $growreport_id, 'wn' => $wn]);
        return (int)$st->fetchColumn();
    };

    try {
    // Společná databázová knihovna uložených historií zalévání, dostupná všem přihlášeným uživatelům.
    if (in_array($action, ['watering_profiles_list', 'watering_profiles_save'], true)) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS growreport_watering_history_library2 (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(160) NOT NULL,
            source_report_id INT NOT NULL DEFAULT 0,
            created_by INT NOT NULL DEFAULT 0,
            payload_json LONGTEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_watering_history_library_created (created_at),
            KEY idx_watering_history_library_source (source_report_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        if ($action === 'watering_profiles_list') {
            $profilesStmt = $pdo->query('SELECT id, name, source_report_id, payload_json, created_at FROM growreport_watering_history_library2 ORDER BY created_at DESC, id DESC LIMIT 300');
            $profiles = [];
            foreach ($profilesStmt->fetchAll(PDO::FETCH_ASSOC) as $profileRow) {
                $payload = json_decode((string)$profileRow['payload_json'], true);
                if (!is_array($payload) || !isset($payload['data'], $payload['baseline'])) continue;
                $profiles[] = [
                    'id' => (string)$profileRow['id'],
                    'name' => (string)$profileRow['name'],
                    'sourceReportId' => (int)$profileRow['source_report_id'],
                    'savedAt' => (string)$profileRow['created_at'],
                    'data' => $payload['data'],
                    'baseline' => $payload['baseline']
                ];
            }
            vg2_json(['success' => true, 'profiles' => $profiles]);
        }

        $profileName = trim(strip_tags((string)($json['name'] ?? '')));
        $profileData = $json['data'] ?? null;
        $profileBaseline = $json['baseline'] ?? null;
        if ($profileName === '' || !is_array($profileData) || !is_array($profileBaseline)) {
            vg2_json(['success' => false, 'message' => 'Název, historie nebo výpočtový základ nejsou platné.']);
        }
        $profileName = function_exists('mb_substr') ? mb_substr($profileName, 0, 160, 'UTF-8') : substr($profileName, 0, 160);
        $payloadJson = json_encode(['data' => $profileData, 'baseline' => $profileBaseline], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($payloadJson) || strlen($payloadJson) > 5000000) {
            vg2_json(['success' => false, 'message' => 'Ukládaná historie je příliš velká nebo ji nelze zpracovat.']);
        }
        $saveProfileStmt = $pdo->prepare('INSERT INTO growreport_watering_history_library2 (name, source_report_id, created_by, payload_json) VALUES (:name, :source_report_id, :created_by, :payload_json)');
        $saveProfileStmt->execute([
            'name' => $profileName,
            'source_report_id' => $growreport_id,
            'created_by' => (int)($_SESSION['user_id'] ?? 0),
            'payload_json' => $payloadJson
        ]);
        vg2_json(['success' => true, 'id' => (string)$pdo->lastInsertId(), 'name' => $profileName]);
    }

    // Načíst skutečnou historii zalévání právě otevřeného Growreportu.
    if ($action === 'watering_history') {
        $weeksStmt = $pdo->prepare('SELECT id, week_number FROM growreport_weeks2 WHERE report_id=:rid AND week_number>=1 ORDER BY week_number ASC');
        $weeksStmt->execute(['rid' => $growreport_id]);
        $weekRows = $weeksStmt->fetchAll(PDO::FETCH_ASSOC);
        $weekMap = [];
        foreach ($weekRows as $weekRow) $weekMap[(int)$weekRow['id']] = (int)$weekRow['week_number'];

        $potsStmt = $pdo->prepare('SELECT pot_number, volume FROM growreport_pots2 WHERE report_id=:rid ORDER BY pot_number ASC');
        $potsStmt->execute(['rid' => $growreport_id]);
        $pots = $potsStmt->fetchAll(PDO::FETCH_ASSOC);
        $potVolumes = [];
        $substrateTotal = 0.0;
        foreach ($pots as $potRow) {
            $potNumber = (int)$potRow['pot_number'];
            $volume = (float)$potRow['volume'];
            $potVolumes[$potNumber] = $volume;
            $substrateTotal += $volume;
        }

        $calendarStmt = $pdo->prepare('SELECT start_date FROM growreport_calendar_settings2 WHERE report_id=:rid LIMIT 1');
        $calendarStmt->execute(['rid' => $growreport_id]);
        $startDate = (string)($calendarStmt->fetchColumn() ?: '');
        $waterStmt = $pdo->prepare('SELECT wa.week_id, wa.day_number, wa.pot_number, wa.amount, w.week_number
            FROM growreport_watering2 wa
            INNER JOIN growreport_weeks2 w ON w.id=wa.week_id
            WHERE w.report_id=:rid AND w.week_number>=1
            ORDER BY w.week_number ASC, wa.day_number ASC, wa.pot_number ASC');
        $waterStmt->execute(['rid' => $growreport_id]);
        $waterRows = $waterStmt->fetchAll(PDO::FETCH_ASSOC);
        $events = [];
        $potTotals = [];
        $totalWater = 0.0;
        foreach ($waterRows as $waterRow) {
            $weekNumber = (int)$waterRow['week_number'];
            $dayNumber = (int)$waterRow['day_number'];
            $potNumber = (int)$waterRow['pot_number'];
            $amount = (float)$waterRow['amount'];
            $key = $weekNumber . ':' . $dayNumber;
            if (!isset($events[$key])) {
                $globalDay = (($weekNumber - 1) * 7) + $dayNumber - 1;
                $date = '';
                if ($startDate !== '') {
                    try { $date = (new DateTimeImmutable($startDate))->modify('+' . $globalDay . ' days')->format('Y-m-d'); } catch (Throwable $ignored) { $date = ''; }
                }
                $events[$key] = ['week' => $weekNumber, 'day' => $dayNumber, 'date' => $date, 'amounts' => [], 'total' => 0.0];
            }
            $events[$key]['amounts'][$potNumber] = round($amount, 2);
            $events[$key]['total'] += $amount;
            $potTotals[$potNumber] = ($potTotals[$potNumber] ?? 0) + $amount;
            $totalWater += $amount;
        }
        $events = array_values($events);
        foreach ($events as &$eventRow) $eventRow['total'] = round($eventRow['total'], 2);
        unset($eventRow);
        $globalDays = [];
        foreach ($events as $eventRow) $globalDays[] = (($eventRow['week'] - 1) * 7) + $eventRow['day'];
        $intervals = [];
        for ($i=1; $i<count($globalDays); $i++) $intervals[] = $globalDays[$i] - $globalDays[$i-1];
        $averageInterval = $intervals ? round(array_sum($intervals) / count($intervals), 2) : null;
        $weekly = [];
        foreach ($events as $eventRow) {
            $wn = (int)$eventRow['week'];
            if (!isset($weekly[$wn])) $weekly[$wn] = ['week' => $wn, 'events' => 0, 'total' => 0.0];
            $weekly[$wn]['events']++;
            $weekly[$wn]['total'] += (float)$eventRow['total'];
        }
        foreach ($weekly as &$weeklyRow) $weeklyRow['total'] = round($weeklyRow['total'], 2);
        unset($weeklyRow);
        $potTotalsRounded = [];
        foreach ($potTotals as $pn => $sum) $potTotalsRounded[$pn] = round($sum, 2);
        vg2_json([
            'success' => true,
            'report_id' => $growreport_id,
            'start_date' => $startDate,
            'pots' => array_map(function($row) { return ['pot_number'=>(int)$row['pot_number'], 'volume'=>(float)$row['volume']]; }, $pots),
            'substrate_total_l' => round($substrateTotal, 2),
            'total_water_l' => round($totalWater, 2),
            'water_per_substrate_l' => $substrateTotal > 0 ? round($totalWater / $substrateTotal, 3) : null,
            'average_interval_days' => $averageInterval,
            'watering_count' => count($events),
            'pot_totals' => $potTotalsRounded,
            'weekly' => array_values($weekly),
            'events' => $events
        ]);
    }

    // Načíst galerii týdne.
    if ($action === 'gallery_list') {
        $wn = (int)($json['week'] ?? 0);
        $weekId = $getWeekId($wn);
        if ($wn < 0 || !$weekId) vg2_json(['success' => false, 'message' => 'Týden galerie nebyl nalezen.']);
        $st = $pdo->prepare('SELECT id, file_path, media_type, original_name, created_at FROM growreport_gallery2 WHERE week_id=:wid ORDER BY sort_order ASC, id ASC LIMIT 10');
        $st->execute(['wid' => $weekId]);
        vg2_json(['success' => true, 'week' => $wn, 'items' => $st->fetchAll(PDO::FETCH_ASSOC), 'limit' => 10]);
    }

    // Nahrát až 10 obrázků/videí do galerie týdne. Tento endpoint používá multipart/form-data.
    if ($action === 'gallery_upload') {
        $wn = (int)($_POST['week'] ?? $json['week'] ?? 0);
        $weekId = $getWeekId($wn);
        if ($wn < 0 || !$weekId) vg2_json(['success' => false, 'message' => 'Týden galerie nebyl nalezen.']);
        if (empty($_FILES['gallery_files'])) vg2_json(['success' => false, 'message' => 'Nebyl vybrán žádný soubor.']);

        $existingStmt = $pdo->prepare('SELECT COUNT(*) FROM growreport_gallery2 WHERE week_id=:wid');
        $existingStmt->execute(['wid' => $weekId]);
        $existingCount = (int)$existingStmt->fetchColumn();
        $available = max(0, 10 - $existingCount);
        if ($available < 1) vg2_json(['success' => false, 'message' => 'Galerie už obsahuje všech 10 souborů. Nejdříve některý soubor smaž.']);

        $f = $_FILES['gallery_files'];
        $fileEntries = [];
        if (is_array($f['name'] ?? null)) {
            foreach ($f['name'] as $i => $name) {
                $fileEntries[] = [
                    'name' => (string)$name,
                    'type' => (string)($f['type'][$i] ?? ''),
                    'tmp_name' => (string)($f['tmp_name'][$i] ?? ''),
                    'error' => (int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                    'size' => (int)($f['size'][$i] ?? 0)
                ];
            }
        } else {
            $fileEntries[] = [
                'name' => (string)($f['name'] ?? ''), 'type' => (string)($f['type'] ?? ''),
                'tmp_name' => (string)($f['tmp_name'] ?? ''), 'error' => (int)($f['error'] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int)($f['size'] ?? 0)
            ];
        }
        $fileEntries = array_values(array_filter($fileEntries, function ($entry) { return $entry['error'] !== UPLOAD_ERR_NO_FILE; }));
        if (!$fileEntries) vg2_json(['success' => false, 'message' => 'Vyber alespoň jeden obrázek nebo video.']);
        if (count($fileEntries) > $available) vg2_json(['success' => false, 'message' => 'V galerii je místo už jen pro ' . $available . ' další soubor(y).']);

        $imageExts = ['jpg','jpeg','png','gif','webp','avif'];
        $videoExts = ['mp4','webm','mov','m4v'];
        $validated = [];
        foreach ($fileEntries as $entry) {
            if ($entry['error'] !== UPLOAD_ERR_OK) vg2_json(['success' => false, 'message' => 'Nahrávání souboru „' . vg2_substr($entry['name'], 0, 100) . '“ selhalo (kód ' . $entry['error'] . ').']);
            if ($entry['size'] <= 0 || $entry['size'] > 100 * 1024 * 1024) vg2_json(['success' => false, 'message' => 'Každý soubor musí mít maximálně 100 MB.']);
            $ext = strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION));
            $mediaType = '';
            if (in_array($ext, $imageExts, true)) {
                if (@getimagesize($entry['tmp_name']) === false) vg2_json(['success' => false, 'message' => 'Soubor „' . vg2_substr($entry['name'], 0, 100) . '“ není platný obrázek.']);
                $mediaType = 'image';
            } elseif (in_array($ext, $videoExts, true)) {
                $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
                $mime = $finfo ? (string)finfo_file($finfo, $entry['tmp_name']) : '';
                if ($finfo) finfo_close($finfo);
                $allowedVideoMimes = ['video/mp4','video/webm','video/quicktime','video/x-m4v','application/octet-stream'];
                if ($mime !== '' && !in_array($mime, $allowedVideoMimes, true)) vg2_json(['success' => false, 'message' => 'Soubor „' . vg2_substr($entry['name'], 0, 100) . '“ nemá podporovaný video formát.']);
                $mediaType = 'video';
            } else {
                vg2_json(['success' => false, 'message' => 'Podporované jsou obrázky JPG, PNG, GIF, WEBP, AVIF a videa MP4, WEBM, MOV, M4V.']);
            }
            if (!is_uploaded_file($entry['tmp_name'])) vg2_json(['success' => false, 'message' => 'Nahrávání souboru nebylo ověřeno. Zkus to znovu.']);
            $entry['ext'] = $ext;
            $entry['media_type'] = $mediaType;
            $validated[] = $entry;
        }

        $relativeDir = 'uploads/growreports2/gallery/';
        $absoluteDir = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
            vg2_json(['success' => false, 'message' => 'Nepodařilo se vytvořit složku galerie: ' . $absoluteDir]);
        }
        $nextOrderStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) FROM growreport_gallery2 WHERE week_id=:wid');
        $nextOrderStmt->execute(['wid' => $weekId]);
        $sortOrder = (int)$nextOrderStmt->fetchColumn();
        $insertGallery = $pdo->prepare('INSERT INTO growreport_gallery2 (week_id,file_path,media_type,original_name,sort_order) VALUES (:wid,:path,:type,:name,:sort)');
        $uploadedCount = 0;
        foreach ($validated as $entry) {
            $fileName = 'gallery_' . $growreport_id . '_' . $weekId . '_' . bin2hex(random_bytes(6)) . '.' . $entry['ext'];
            $absolutePath = $absoluteDir . $fileName;
            if (!move_uploaded_file($entry['tmp_name'], $absolutePath)) {
                vg2_json(['success' => false, 'message' => 'Nepodařilo se uložit soubor „' . vg2_substr($entry['name'], 0, 100) . '“. Zkontroluj práva složky galerie.']);
            }
            try {
                $insertGallery->execute([
                    'wid' => $weekId, 'path' => $relativeDir . $fileName, 'type' => $entry['media_type'],
                    'name' => vg2_substr($entry['name'], 0, 255), 'sort' => ++$sortOrder
                ]);
                $uploadedCount++;
            } catch (Throwable $e) {
                @unlink($absolutePath);
                throw $e;
            }
        }
        $listStmt = $pdo->prepare('SELECT id, file_path, media_type, original_name, created_at FROM growreport_gallery2 WHERE week_id=:wid ORDER BY sort_order ASC, id ASC LIMIT 10');
        $listStmt->execute(['wid' => $weekId]);
        vg2_json(['success' => true, 'message' => 'Nahráno souborů: ' . $uploadedCount . '.', 'items' => $listStmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'gallery_delete') {
        $wn = (int)($json['week'] ?? 0);
        $itemId = (int)($json['item_id'] ?? 0);
        $weekId = $getWeekId($wn);
        if (!$weekId || $itemId < 1) vg2_json(['success' => false, 'message' => 'Neplatný týden nebo soubor galerie.']);
        $st = $pdo->prepare('SELECT file_path FROM growreport_gallery2 WHERE id=:id AND week_id=:wid LIMIT 1');
        $st->execute(['id' => $itemId, 'wid' => $weekId]);
        $path = $st->fetchColumn();
        if (!$path) vg2_json(['success' => false, 'message' => 'Soubor galerie nebyl nalezen.']);
        $pdo->prepare('DELETE FROM growreport_gallery2 WHERE id=:id AND week_id=:wid')->execute(['id' => $itemId, 'wid' => $weekId]);
        $galleryRoot = realpath(__DIR__ . '/uploads/growreports2/gallery');
        $realPath = realpath(__DIR__ . '/' . ltrim((string)$path, '/'));
        if ($galleryRoot && $realPath && strpos($realPath, $galleryRoot . DIRECTORY_SEPARATOR) === 0 && is_file($realPath)) @unlink($realPath);
        vg2_json(['success' => true, 'message' => 'Soubor byl odstraněn.']);
    }

    if ($action === 'set_problem_mark') {
        $wn = (int)($json['week'] ?? 0);
        $day = (int)($json['day'] ?? 0);
        $marked = !empty($json['marked']) ? 1 : 0;
        if ($wn < 0 || $day < 1 || $day > 7) {
            vg2_json(['success' => false, 'message' => 'Neplatný týden nebo den.']);
        }
        $weekId = $getWeekId($wn);
        if (!$weekId) vg2_json(['success' => false, 'message' => 'Týden nebyl nalezen.']);
        if ($marked) {
            $st = $pdo->prepare("INSERT INTO growreport_problem_marks2 (week_id, day_number, marked) VALUES (:wid, :day, 1) ON DUPLICATE KEY UPDATE marked=1");
            $st->execute(['wid' => $weekId, 'day' => $day]);
        } else {
            $pdo->prepare("DELETE FROM growreport_problem_marks2 WHERE week_id=:wid AND day_number=:day")
                ->execute(['wid' => $weekId, 'day' => $day]);
        }
        vg2_json(['success' => true, 'marked' => (bool)$marked, 'week' => $wn, 'day' => $day]);
    }

    if ($action === 'daily_task_toggle') {
        $wn = (int)($json['week'] ?? 0);
        $day = (int)($json['day'] ?? 0);
        $completed = !empty($json['completed']) ? 1 : 0;
        if ($wn < 0 || $day < 1 || $day > 7) vg2_json(['success' => false, 'message' => 'Neplatný týden nebo den.']);
        $weekId = $getWeekId($wn);
        if (!$weekId) vg2_json(['success' => false, 'message' => 'Týden nebyl nalezen.']);
        $st = $pdo->prepare("INSERT INTO growreport_daily_tasks2 (report_id, week_id, day_number, completed) VALUES (:rid, :wid, :day, :completed) ON DUPLICATE KEY UPDATE completed=VALUES(completed)");
        $st->execute(['rid' => $growreport_id, 'wid' => $weekId, 'day' => $day, 'completed' => $completed]);
        vg2_json(['success' => true, 'completed' => (bool)$completed, 'week' => $wn, 'day' => $day]);
    }


    if ($action === 'audit_get') {
        $st=$pdo->prepare('SELECT settings_json,results_json,updated_at FROM growreport_audit2 WHERE report_id=:rid LIMIT 1');
        $st->execute(['rid'=>$growreport_id]); $row=$st->fetch(PDO::FETCH_ASSOC);
        vg2_json(['success'=>true,'settings'=>$row?(json_decode((string)$row['settings_json'],true)?:null):null,'results'=>$row&&$row['results_json']?(json_decode((string)$row['results_json'],true)?:null):null,'updated_at'=>$row['updated_at']??null]);
    }
    if ($action === 'audit_save') {
        $settings=$json['settings']??null; $results=$json['results']??null;
        if(!is_array($settings)||!is_array($results)) vg2_json(['success'=>false,'message'=>'Neplatná data auditu.']);
        $sj=json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE); $rj=json_encode($results,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        if(!$sj||!$rj||strlen($sj)>150000||strlen($rj)>150000) vg2_json(['success'=>false,'message'=>'Data auditu jsou příliš rozsáhlá nebo neplatná.']);
        $st=$pdo->prepare('INSERT INTO growreport_audit2(report_id,settings_json,results_json) VALUES(:rid,:s,:r) ON DUPLICATE KEY UPDATE settings_json=VALUES(settings_json),results_json=VALUES(results_json)');
        $st->execute(['rid'=>$growreport_id,'s'=>$sj,'r'=>$rj]); vg2_json(['success'=>true]);
    }
    if ($action === 'audit_delete') {
        $pdo->prepare('DELETE FROM growreport_audit2 WHERE report_id=:rid')->execute(['rid'=>$growreport_id]); vg2_json(['success'=>true]);
    }

    if ($action === 'electricity_price_history_get') {
        $st=$pdo->prepare('SELECT effective_date,price_per_kwh,day_price_per_kwh,night_price_per_kwh,day_hours,night_hours FROM growreport_electricity_price_history2 WHERE report_id=:rid ORDER BY effective_date ASC');
        $st->execute(['rid'=>$growreport_id]);
        vg2_json(['success'=>true,'history'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($action === 'electricity_get') {
        $st = $pdo->prepare('SELECT price_per_kwh, extra_device_watts, day_price_per_kwh, night_price_per_kwh, day_hours, night_hours FROM growreport_electricity2 WHERE report_id=:rid LIMIT 1');
        $st->execute(['rid'=>$growreport_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['price_per_kwh'=>0,'extra_device_watts'=>0,'day_price_per_kwh'=>0,'night_price_per_kwh'=>0,'day_hours'=>16,'night_hours'=>8];
        vg2_json(['success'=>true,'price_per_kwh'=>(float)$row['price_per_kwh'],'extra_device_watts'=>(float)$row['extra_device_watts'],'day_price_per_kwh'=>(float)$row['day_price_per_kwh'],'night_price_per_kwh'=>(float)$row['night_price_per_kwh'],'day_hours'=>(float)$row['day_hours'],'night_hours'=>(float)$row['night_hours']]);
    }
    if ($action === 'electricity_save') {
        $singlePrice = str_replace(',', '.', trim((string)($json['price_per_kwh'] ?? '0')));
        $dayPrice = str_replace(',', '.', trim((string)($json['day_price_per_kwh'] ?? '0')));
        $nightPrice = str_replace(',', '.', trim((string)($json['night_price_per_kwh'] ?? '0')));
        $dayHours = str_replace(',', '.', trim((string)($json['day_hours'] ?? '24')));
        $nightHours = str_replace(',', '.', trim((string)($json['night_hours'] ?? '0')));
        $extra = str_replace(',', '.', trim((string)($json['extra_device_watts'] ?? '0')));
        $splitMode = (float)$dayPrice > 0 && (float)$nightPrice > 0;
        $validSingle = is_numeric($singlePrice) && (float)$singlePrice > 0 && (float)$singlePrice <= 10000;
        $validSplit = is_numeric($dayPrice) && (float)$dayPrice > 0 && (float)$dayPrice <= 10000 && is_numeric($nightPrice) && (float)$nightPrice > 0 && (float)$nightPrice <= 10000 && is_numeric($dayHours) && is_numeric($nightHours) && (float)$dayHours >= 0 && (float)$nightHours >= 0 && abs((float)$dayHours + (float)$nightHours - 24) <= 0.01;
        if ((!$splitMode && !$validSingle) || ($splitMode && !$validSplit) || !is_numeric($extra) || (float)$extra < 0 || (float)$extra > 20000) {
            vg2_json(['success'=>false,'message'=>'Zadej platnou cenu proudu, případně obě ceny tarifů a součet hodin přesně 24.']);
        }
        if (!$splitMode) { $dayPrice = '0'; $nightPrice = '0'; $dayHours = '24'; $nightHours = '0'; }
        $storedPrice = $splitMode ? (float)$dayPrice : (float)$singlePrice;
        $effectiveDate = trim((string)($json['effective_date'] ?? date('Y-m-d')));
        $dateObj = DateTime::createFromFormat('!Y-m-d', $effectiveDate);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $effectiveDate) vg2_json(['success'=>false,'message'=>'Zadej platné datum, od kterého má nová cena platit.']);
        $st = $pdo->prepare('INSERT INTO growreport_electricity2 (report_id,price_per_kwh,extra_device_watts,day_price_per_kwh,night_price_per_kwh,day_hours,night_hours) VALUES (:rid,:price,:extra,:dayprice,:nightprice,:dayhours,:nighthours) ON DUPLICATE KEY UPDATE price_per_kwh=VALUES(price_per_kwh), extra_device_watts=VALUES(extra_device_watts), day_price_per_kwh=VALUES(day_price_per_kwh), night_price_per_kwh=VALUES(night_price_per_kwh), day_hours=VALUES(day_hours), night_hours=VALUES(night_hours)');
        $st->execute(['rid'=>$growreport_id,'price'=>$storedPrice,'extra'=>(float)$extra,'dayprice'=>(float)$dayPrice,'nightprice'=>(float)$nightPrice,'dayhours'=>(float)$dayHours,'nighthours'=>(float)$nightHours]);
        $hist=$pdo->prepare('INSERT INTO growreport_electricity_price_history2 (report_id,effective_date,price_per_kwh,day_price_per_kwh,night_price_per_kwh,day_hours,night_hours) VALUES (:rid,:date,:price,:dayprice,:nightprice,:dayhours,:nighthours) ON DUPLICATE KEY UPDATE price_per_kwh=VALUES(price_per_kwh),day_price_per_kwh=VALUES(day_price_per_kwh),night_price_per_kwh=VALUES(night_price_per_kwh),day_hours=VALUES(day_hours),night_hours=VALUES(night_hours)');
        $hist->execute(['rid'=>$growreport_id,'date'=>$effectiveDate,'price'=>$storedPrice,'dayprice'=>(float)$dayPrice,'nightprice'=>(float)$nightPrice,'dayhours'=>(float)$dayHours,'nighthours'=>(float)$nightHours]);
        vg2_json(['success'=>true,'price_per_kwh'=>$storedPrice,'extra_device_watts'=>(float)$extra,'day_price_per_kwh'=>(float)$dayPrice,'night_price_per_kwh'=>(float)$nightPrice,'day_hours'=>(float)$dayHours,'night_hours'=>(float)$nightHours]);
    }

    // Načtení stavu Splněno přímo ze zdrojových tabulek jednotlivých úkonů.
    if ($action === 'action_task_states') {
        $sourceTables = [
            'pruning'=>'growreport_pruning_actions2',
            'vz'=>'growreport_vz_records2',
            'spray'=>'growreport_spray_actions2',
            'net'=>'growreport_net_marks2',
            'custom_function'=>'growreport_custom_function_marks2'
        ];
        $items = [];
        foreach ($sourceTables as $type => $table) {
            $st = $pdo->prepare('SELECT id, completed FROM ' . $table . ' WHERE report_id=:rid');
            $st->execute(['rid'=>$growreport_id]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $items[] = ['task_type'=>$type, 'source_id'=>(int)$row['id'], 'completed'=>(int)$row['completed']];
            }
        }
        vg2_json(['success'=>true, 'items'=>$items, 'storage'=>'source_records']);
    }

    // Diagnostika čte pouze samotné záznamy; pomocná tabulka splnění se nepoužívá.
    if ($action === 'action_task_debug') {
        $debug = ['sources'=>[]];
        $debugTables = ['pruning'=>'growreport_pruning_actions2','vz'=>'growreport_vz_records2','spray'=>'growreport_spray_actions2','net'=>'growreport_net_marks2','custom_function'=>'growreport_custom_function_marks2'];
        foreach ($debugTables as $type => $table) {
            $st = $pdo->prepare('SELECT id, record_date, completed FROM ' . $table . ' WHERE report_id=:rid ORDER BY record_date DESC, id DESC LIMIT 100');
            $st->execute(['rid'=>$growreport_id]);
            $debug['sources'][$type] = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        vg2_json(['success'=>true, 'debug'=>$debug, 'storage'=>'source_records']);
    }

    // Uložení Splněno přímo do completed u konkrétního zdrojového záznamu.
    if ($action === 'action_task_toggle') {
        $taskType = (string)($json['task_type'] ?? '');
        $sourceId = (int)($json['source_id'] ?? 0);
        $completed = !empty($json['completed']) ? 1 : 0;
        $sourceTables = ['pruning'=>'growreport_pruning_actions2','vz'=>'growreport_vz_records2','spray'=>'growreport_spray_actions2','net'=>'growreport_net_marks2','custom_function'=>'growreport_custom_function_marks2'];
        if (!isset($sourceTables[$taskType]) || $sourceId < 1) {
            vg2_json(['success'=>false,'message'=>'Neplatný úkon.','debug'=>['task_type'=>$taskType,'source_id'=>$sourceId]]);
        }
        $sourceTable = $sourceTables[$taskType];
        try {
            $save = $pdo->prepare('UPDATE ' . $sourceTable . ' SET completed=:completed WHERE id=:id AND report_id=:rid');
            $save->execute(['completed'=>$completed,'id'=>$sourceId,'rid'=>$growreport_id]);
            // rowCount může být nula, pokud už záznam obsahoval požadovanou hodnotu.
            $verify = $pdo->prepare('SELECT completed FROM ' . $sourceTable . ' WHERE id=:id AND report_id=:rid LIMIT 1');
            $verify->execute(['id'=>$sourceId,'rid'=>$growreport_id]);
            $savedValue = $verify->fetchColumn();
            if ($savedValue === false) throw new RuntimeException('Zdrojový záznam úkonu nebyl nalezen. Obnov stránku a zkus to znovu.');
            if ((int)$savedValue !== $completed) throw new RuntimeException('Kontrolní čtení nepotvrdilo uložení přímo v záznamu úkonu.');
            vg2_json(['success'=>true,'completed'=>(bool)$completed,'verified'=>true,'storage'=>$sourceTable.'.completed','task_type'=>$taskType,'source_id'=>$sourceId]);
        } catch (Throwable $e) {
            error_log('Growreport action task save failed: report='.$growreport_id.'; type='.$taskType.'; source='.$sourceId.'; error='.$e->getMessage());
            vg2_json(['success'=>false,'message'=>'Databáze neuložila stav přímo do záznamu úkonu: '.$e->getMessage(),'debug'=>['task_type'=>$taskType,'source_id'=>$sourceId,'source_table'=>$sourceTable,'report_id'=>(int)$growreport_id,'storage'=>$sourceTable.'.completed']]);
        }
    }

    if ($action === 'save_calendar_settings') {
        $weekday = mb_strtolower(trim((string)($json['first_weekday'] ?? '')), 'UTF-8');
        $startDate = trim((string)($json['start_date'] ?? ''));
        $weekdayNumbers = ['pondělí' => 1, 'úterý' => 2, 'středa' => 3, 'čtvrtek' => 4, 'pátek' => 5, 'sobota' => 6, 'neděle' => 7];
        if (!isset($weekdayNumbers[$weekday])) {
            vg2_json(['success' => false, 'message' => 'Vyber počáteční den týdne.']);
        }
        $dateObj = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$dateObj || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count'])) || $dateObj->format('Y-m-d') !== $startDate) {
            vg2_json(['success' => false, 'message' => 'Vyber platné počáteční datum.']);
        }
        if ((int)$dateObj->format('N') !== $weekdayNumbers[$weekday]) {
            vg2_json(['success' => false, 'message' => 'Vybraný den týdne neodpovídá datu. Po výběru data se den týdne nastaví automaticky; zkontroluj ho prosím.']);
        }
        $st = $pdo->prepare("INSERT INTO growreport_calendar_settings2 (report_id, first_weekday, start_date) VALUES (:rid, :weekday, :start_date) ON DUPLICATE KEY UPDATE first_weekday=VALUES(first_weekday), start_date=VALUES(start_date)");
        $st->execute(['rid' => $growreport_id, 'weekday' => $weekday, 'start_date' => $startDate]);
        // Zachovat kompatibilitu se stávajícím vykreslením dnů a zajistit stejné pořadí ve všech týdnech.
        $pdo->prepare('UPDATE growreport_weeks2 SET start_day=:weekday WHERE report_id=:rid')
            ->execute(['weekday' => $weekday, 'rid' => $growreport_id]);
        vg2_json(['success' => true, 'first_weekday' => $weekday, 'start_date' => $startDate]);
    }

    if ($action === 'pruning_list') {
        $st = $pdo->prepare('SELECT id, week_number, day_number, record_date, action_type, completed, created_at FROM growreport_pruning_actions2 WHERE report_id=:rid ORDER BY record_date DESC, id DESC');
        $st->execute(['rid' => $growreport_id]);
        vg2_json(['success' => true, 'items' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'pruning_save') {
        $week = (int)($json['week'] ?? 0);
        $day = (int)($json['day'] ?? 0);
        $recordDate = trim((string)($json['record_date'] ?? ''));
        $actionType = (int)($json['action_type'] ?? 0);
        $dateObj = DateTimeImmutable::createFromFormat('!Y-m-d', $recordDate);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($week < 0 || $day < 1 || $day > 7 || !in_array($actionType, [1,2,3,4,5,6,8,9,10], true) || !$dateObj || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count'])) || $dateObj->format('Y-m-d') !== $recordDate) {
            vg2_json(['success' => false, 'message' => 'Vyber platné datum a provedený úkon.']);
        }
        $weekId = $getWeekId($week);
        if (!$weekId) vg2_json(['success' => false, 'message' => 'Vybraný týden nebyl nalezen.']);
        // Stejný úkon lze pro jeden report a datum uložit pouze jednou.
        // Starší duplicitní záznamy zůstávají v historii beze změny.
        $duplicate = $pdo->prepare('SELECT id FROM growreport_pruning_actions2 WHERE report_id=:rid AND record_date=:date AND action_type=:type LIMIT 1');
        $duplicate->execute(['rid'=>$growreport_id, 'date'=>$recordDate, 'type'=>$actionType]);
        if ($duplicate->fetchColumn()) {
            vg2_json(['success' => false, 'message' => 'Tento úkon už je pro vybraný den uložen. Stejný úkon nelze přidat vícekrát.']);
        }
        $st = $pdo->prepare('INSERT INTO growreport_pruning_actions2 (report_id, week_number, day_number, record_date, action_type) VALUES (:rid,:week,:day,:date,:type)');
        $st->execute(['rid'=>$growreport_id, 'week'=>$week, 'day'=>$day, 'date'=>$recordDate, 'type'=>$actionType]);
        $list = $pdo->prepare('SELECT id, week_number, day_number, record_date, action_type, completed, created_at FROM growreport_pruning_actions2 WHERE report_id=:rid ORDER BY record_date DESC, id DESC');
        $list->execute(['rid'=>$growreport_id]);
        vg2_json(['success'=>true, 'items'=>$list->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'pruning_delete') {
        $id = (int)($json['id'] ?? 0);
        if ($id < 1) vg2_json(['success' => false, 'message' => 'Neplatné ID záznamu.']);
        $st = $pdo->prepare('DELETE FROM growreport_pruning_actions2 WHERE id=:id AND report_id=:rid');
        $st->execute(['id' => $id, 'rid' => $growreport_id]);
        if ($st->rowCount() < 1) vg2_json(['success' => false, 'message' => 'Záznam nebyl nalezen.']);
        $list = $pdo->prepare('SELECT id, week_number, day_number, record_date, action_type, completed, created_at FROM growreport_pruning_actions2 WHERE report_id=:rid ORDER BY record_date DESC, id DESC');
        $list->execute(['rid' => $growreport_id]);
        vg2_json(['success' => true, 'items' => $list->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'custom_functions_get') {
        $cfg = $pdo->prepare('SELECT slot_number, function_name, color FROM growreport_custom_functions2 WHERE report_id=:rid ORDER BY slot_number');
        $cfg->execute(['rid' => $growreport_id]);
        $marks = $pdo->prepare('SELECT id, slot_number, week_number, day_number, record_date, completed, created_at FROM growreport_custom_function_marks2 WHERE report_id=:rid ORDER BY record_date DESC, id DESC');
        $marks->execute(['rid' => $growreport_id]);
        vg2_json(['success' => true, 'settings' => $cfg->fetchAll(PDO::FETCH_ASSOC), 'items' => $marks->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'custom_functions_save_settings') {
        $settings = $json['settings'] ?? [];
        if (!is_array($settings)) vg2_json(['success' => false, 'message' => 'Neplatné nastavení funkcí.']);
        $allowedColors = ['#ff9100', '#ffea00', '#00b0ff'];
        $up = $pdo->prepare('INSERT INTO growreport_custom_functions2 (report_id, slot_number, function_name, color) VALUES (:rid,:slot,:name,:color) ON DUPLICATE KEY UPDATE function_name=VALUES(function_name), color=VALUES(color)');
        for ($slot = 1; $slot <= 3; $slot++) {
            $row = isset($settings[$slot - 1]) && is_array($settings[$slot - 1]) ? $settings[$slot - 1] : [];
            $name = vg2_substr(trim(strip_tags((string)($row['name'] ?? ''))), 0, 100);
            $color = strtolower(trim((string)($row['color'] ?? '#ff9100')));
            if (!in_array($color, $allowedColors, true)) vg2_json(['success' => false, 'message' => 'Vyber jednu z nabízených barev.']);
            $up->execute(['rid' => $growreport_id, 'slot' => $slot, 'name' => $name, 'color' => $color]);
        }
        vg2_json(['success' => true]);
    }

    if ($action === 'custom_function_weeks_generate') {
        $growthWeeks = filter_var($json['growth_weeks'] ?? null, FILTER_VALIDATE_INT);
        $flowerWeeks = filter_var($json['flower_weeks'] ?? null, FILTER_VALIDATE_INT);
        if ($growthWeeks === false || $flowerWeeks === false || $growthWeeks < 0 || $flowerWeeks < 0 || $growthWeeks > 52 || $flowerWeeks > 52 || ($growthWeeks + $flowerWeeks) < 1 || ($growthWeeks + $flowerWeeks) > 104) {
            vg2_json(['success' => false, 'message' => 'Zadej počet týdnů růstu a květu v rozsahu 0 až 52; součet musí být alespoň 1 a nejvýše 104.']);
        }

        $totalWeeks = $growthWeeks + $flowerWeeks;
        $maxStmt = $pdo->prepare('SELECT COALESCE(MAX(week_number),0) FROM growreport_weeks2 WHERE report_id=:rid AND week_number>=1');
        $maxStmt->execute(['rid' => $growreport_id]);
        $existingMaxWeek = (int)$maxStmt->fetchColumn();
        if ($existingMaxWeek > $totalWeeks) {
            vg2_json(['success' => false, 'message' => 'Growreport už obsahuje ' . $existingMaxWeek . ' týdnů. Požadovaný počet ' . $totalWeeks . ' je nižší. Žádný týden jsem nesmazal; zadej alespoň ' . $existingMaxWeek . ' týdnů.']);
        }

        $startDayStmt = $pdo->prepare('SELECT start_day FROM growreport_weeks2 WHERE report_id=:rid AND week_number=1 LIMIT 1');
        $startDayStmt->execute(['rid' => $growreport_id]);
        $startDay = $startDayStmt->fetchColumn();
        if (!$startDay) {
            $lastStartStmt = $pdo->prepare('SELECT start_day FROM growreport_weeks2 WHERE report_id=:rid ORDER BY week_number DESC LIMIT 1');
            $lastStartStmt->execute(['rid' => $growreport_id]);
            $startDay = $lastStartStmt->fetchColumn();
        }

        $pdo->beginTransaction();
        try {
            $existsStmt = $pdo->prepare('SELECT id FROM growreport_weeks2 WHERE report_id=:rid AND week_number=:wn LIMIT 1');
            $insertStmt = $pdo->prepare("INSERT INTO growreport_weeks2 (report_id, week_number, text_content, start_day) VALUES (:rid, :wn, '', :sd)");
            $phaseStmt = $pdo->prepare('UPDATE growreport_weeks2 SET phase=:phase, phase_color=:color WHERE report_id=:rid AND week_number=:wn');
            for ($weekNumber = 1; $weekNumber <= $totalWeeks; $weekNumber++) {
                $existsStmt->execute(['rid' => $growreport_id, 'wn' => $weekNumber]);
                if (!$existsStmt->fetchColumn()) {
                    $insertStmt->execute(['rid' => $growreport_id, 'wn' => $weekNumber, 'sd' => $startDay ?: null]);
                }
                $isGrowth = $weekNumber <= $growthWeeks;
                $phaseStmt->execute([
                    'phase' => $isGrowth ? 'RŮST' : 'KVĚT',
                    'color' => $isGrowth ? 'green' : 'red',
                    'rid' => $growreport_id,
                    'wn' => $weekNumber
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        vg2_json(['success' => true, 'growth_weeks' => $growthWeeks, 'flower_weeks' => $flowerWeeks, 'total_weeks' => $totalWeeks]);
    }

    if ($action === 'custom_function_mark_save') {
        $slot = (int)($json['slot'] ?? 0);
        $week = (int)($json['week'] ?? -1);
        $day = (int)($json['day'] ?? 0);
        $recordDate = trim((string)($json['record_date'] ?? ''));
        $dateObj = DateTimeImmutable::createFromFormat('!Y-m-d', $recordDate);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($slot < 1 || $slot > 3 || $week < 0 || $day < 1 || $day > 7 || !$dateObj || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count'])) || $dateObj->format('Y-m-d') !== $recordDate) {
            vg2_json(['success' => false, 'message' => 'Vyber platnou funkci a datum.']);
        }
        $weekId = $getWeekId($week);
        if (!$weekId) vg2_json(['success' => false, 'message' => 'Vybraný týden nebyl nalezen.']);
        $check = $pdo->prepare('SELECT COUNT(*) FROM growreport_custom_functions2 WHERE report_id=:rid AND slot_number=:slot');
        $check->execute(['rid' => $growreport_id, 'slot' => $slot]);
        if ((int)$check->fetchColumn() < 1) vg2_json(['success' => false, 'message' => 'Nejdříve ulož nastavení tří funkcí.']);
        $st = $pdo->prepare('INSERT INTO growreport_custom_function_marks2 (report_id, slot_number, week_number, day_number, record_date) VALUES (:rid,:slot,:week,:day,:date) ON DUPLICATE KEY UPDATE week_number=VALUES(week_number), day_number=VALUES(day_number), created_at=CURRENT_TIMESTAMP');
        $st->execute(['rid' => $growreport_id, 'slot' => $slot, 'week' => $week, 'day' => $day, 'date' => $recordDate]);
        $list = $pdo->prepare('SELECT id, slot_number, week_number, day_number, record_date, completed, created_at FROM growreport_custom_function_marks2 WHERE report_id=:rid ORDER BY record_date DESC, id DESC');
        $list->execute(['rid' => $growreport_id]);
        vg2_json(['success' => true, 'items' => $list->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'custom_function_mark_delete') {
        $id = (int)($json['id'] ?? 0);
        if ($id < 1) vg2_json(['success' => false, 'message' => 'Neplatný záznam.']);
        $st = $pdo->prepare('DELETE FROM growreport_custom_function_marks2 WHERE id=:id AND report_id=:rid');
        $st->execute(['id' => $id, 'rid' => $growreport_id]);
        $list = $pdo->prepare('SELECT id, slot_number, week_number, day_number, record_date, completed, created_at FROM growreport_custom_function_marks2 WHERE report_id=:rid ORDER BY record_date DESC, id DESC');
        $list->execute(['rid' => $growreport_id]);
        vg2_json(['success' => true, 'items' => $list->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'spray_list') {
        $st = $pdo->prepare('SELECT id, week_id, week_number, day_number, record_date, sprays_json, completed, created_at FROM growreport_spray_actions2 WHERE report_id=:rid ORDER BY record_date DESC, id DESC');
        $st->execute(['rid' => $growreport_id]);
        $items = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as &$sprayRow) {
            $decoded = json_decode((string)($sprayRow['sprays_json'] ?? '[]'), true);
            $sprayRow['sprays'] = is_array($decoded) ? $decoded : [];
            unset($sprayRow['sprays_json']);
        }
        unset($sprayRow);
        vg2_json(['success' => true, 'items' => $items]);
    }

    if ($action === 'spray_save') {
        $week = (int)($json['week'] ?? -1);
        $day = (int)($json['day'] ?? 0);
        $recordDate = trim((string)($json['record_date'] ?? ''));
        $sprays = $json['sprays'] ?? [];
        $dateObj = DateTimeImmutable::createFromFormat('!Y-m-d', $recordDate);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($week < 0 || $day < 1 || $day > 7 || !$dateObj || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count'])) || $dateObj->format('Y-m-d') !== $recordDate) {
            vg2_json(['success' => false, 'message' => 'Vyber platný týden, den a datum postřiku.']);
        }
        if (!is_array($sprays)) vg2_json(['success' => false, 'message' => 'Seznam postřiků není platný.']);
        if (count($sprays) > 2) vg2_json(['success' => false, 'message' => 'V jednom záznamu lze uložit nejvýše dva postřiky.']);
        $allowedTypes = ['custom' => ''];
        $cleanSprays = [];
        foreach ($sprays as $spray) {
            if (!is_array($spray)) continue;
            $type = (string)($spray['type'] ?? '');
            if (!isset($allowedTypes[$type])) continue;
            $name = $type === 'custom' ? trim(strip_tags((string)($spray['name'] ?? ''))) : $allowedTypes[$type];
            $name = vg2_substr($name, 0, 100);
            $ml = filter_var($spray['ml'] ?? null, FILTER_VALIDATE_INT);
            if ($name === '' || $ml === false || $ml === null || (int)$ml < 1 || (int)$ml > 10000) continue;
            $cleanSprays[] = ['type' => $type, 'name' => $name, 'ml' => (int)$ml];
        }
        if (!$cleanSprays) vg2_json(['success' => false, 'message' => 'Vyber alespoň jeden postřik a zadej jeho množství v ml.']);
        $weekId = $getWeekId($week);
        if (!$weekId) vg2_json(['success' => false, 'message' => 'Vybraný týden nebyl nalezen.']);
        $st = $pdo->prepare('INSERT INTO growreport_spray_actions2 (report_id,week_id,week_number,day_number,record_date,sprays_json) VALUES (:rid,:wid,:week,:day,:date,:sprays)');
        $st->execute(['rid'=>$growreport_id, 'wid'=>$weekId, 'week'=>$week, 'day'=>$day, 'date'=>$recordDate, 'sprays'=>json_encode($cleanSprays, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
        $list = $pdo->prepare('SELECT id, week_id, week_number, day_number, record_date, sprays_json, completed, created_at FROM growreport_spray_actions2 WHERE report_id=:rid ORDER BY record_date DESC, id DESC');
        $list->execute(['rid'=>$growreport_id]);
        $items = $list->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as &$sprayRow) { $decoded=json_decode((string)($sprayRow['sprays_json'] ?? '[]'), true); $sprayRow['sprays']=is_array($decoded)?$decoded:[]; unset($sprayRow['sprays_json']); }
        unset($sprayRow);
        vg2_json(['success'=>true, 'items'=>$items]);
    }

    if ($action === 'spray_delete') {
        $id = (int)($json['id'] ?? 0);
        if ($id < 1) vg2_json(['success' => false, 'message' => 'Neplatné ID postřiku.']);
        $st = $pdo->prepare('DELETE FROM growreport_spray_actions2 WHERE id=:id AND report_id=:rid');
        $st->execute(['id'=>$id, 'rid'=>$growreport_id]);
        if ($st->rowCount() < 1) vg2_json(['success'=>false, 'message'=>'Záznam postřiku nebyl nalezen.']);
        vg2_json(['success'=>true]);
    }

    if ($action === 'net_list') {
        $st = $pdo->prepare('SELECT id, week_id, week_number, day_number, record_date, marked, completed, updated_at FROM growreport_net_marks2 WHERE report_id=:rid AND marked=1 ORDER BY record_date DESC, id DESC');
        $st->execute(['rid'=>$growreport_id]);
        vg2_json(['success'=>true, 'items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'net_save') {
        $week = (int)($json['week'] ?? -1);
        $day = (int)($json['day'] ?? 0);
        $recordDate = trim((string)($json['record_date'] ?? ''));
        $marked = !empty($json['marked']) ? 1 : 0;
        $dateObj = DateTimeImmutable::createFromFormat('!Y-m-d', $recordDate);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($week < 0 || $day < 1 || $day > 7 || !$dateObj || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count'])) || $dateObj->format('Y-m-d') !== $recordDate) {
            vg2_json(['success'=>false, 'message'=>'Vyber platný týden, den a datum pro síť.']);
        }
        $weekId = $getWeekId($week);
        if (!$weekId) vg2_json(['success'=>false, 'message'=>'Vybraný týden nebyl nalezen.']);
        if ($marked) {
            $st = $pdo->prepare('INSERT INTO growreport_net_marks2 (report_id,week_id,week_number,day_number,record_date,marked) VALUES (:rid,:wid,:week,:day,:date,1)');
            $st->execute(['rid'=>$growreport_id, 'wid'=>$weekId, 'week'=>$week, 'day'=>$day, 'date'=>$recordDate]);
        } else {
            $pdo->prepare('DELETE FROM growreport_net_marks2 WHERE id=(SELECT id FROM (SELECT id FROM growreport_net_marks2 WHERE report_id=:rid AND record_date=:date ORDER BY id DESC LIMIT 1) AS latest_net)')->execute(['rid'=>$growreport_id, 'date'=>$recordDate]);
        }
        $st = $pdo->prepare('SELECT id, week_id, week_number, day_number, record_date, marked, completed, updated_at FROM growreport_net_marks2 WHERE report_id=:rid AND marked=1 ORDER BY record_date DESC, id DESC');
        $st->execute(['rid'=>$growreport_id]);
        vg2_json(['success'=>true, 'marked'=>(bool)$marked, 'items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'net_delete') {
        $id = (int)($json['id'] ?? 0);
        if ($id < 1) vg2_json(['success'=>false, 'message'=>'Neplatné ID označení sítě.']);
        $st = $pdo->prepare('DELETE FROM growreport_net_marks2 WHERE id=:id AND report_id=:rid');
        $st->execute(['id'=>$id, 'rid'=>$growreport_id]);
        if ($st->rowCount() < 1) vg2_json(['success'=>false, 'message'=>'Záznam sítě nebyl nalezen.']);
        vg2_json(['success'=>true]);
    }

    if ($action === 'vz_list') {
        $st = $pdo->prepare('SELECT id, week_number, day_number, record_date, record_type, runtime_hours, anchor_future, auto_propagated, source_anchor_date, completed, created_at, updated_at FROM growreport_vz_records2 WHERE report_id=:rid ORDER BY record_date DESC, id DESC');
        $st->execute(['rid'=>$growreport_id]);
        vg2_json(['success'=>true, 'items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'vz_save') {
        $week = (int)($json['week'] ?? -1);
        $day = (int)($json['day'] ?? 0);
        $recordDate = trim((string)($json['record_date'] ?? ''));
        $type = (string)($json['record_type'] ?? '');
        $hoursRaw = $json['runtime_hours'] ?? null;
        $anchorFuture = !empty($json['anchor_future']);
        $dateObj = DateTimeImmutable::createFromFormat('!Y-m-d', $recordDate);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($week < 1 || $day < 1 || $day > 7 || !$dateObj || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count'])) || $dateObj->format('Y-m-d') !== $recordDate) {
            vg2_json(['success'=>false, 'message'=>'Vyber platný týden, den a datum.']);
        }
        if (!in_array($type, ['vanicka', 'zvlhcovac'], true)) {
            vg2_json(['success'=>false, 'message'=>'Vyber vaničku nebo zvlhčovač.']);
        }
        $hours = null;
        if ($type === 'zvlhcovac') {
            if (!is_numeric($hoursRaw) || (float)$hoursRaw < 0 || (float)$hoursRaw > 24) {
                vg2_json(['success'=>false, 'message'=>'Doba chodu zvlhčovače musí být 0 až 24 hodin.']);
            }
            $hours = round((float)$hoursRaw * 2) / 2;
        }
        $weekId = $getWeekId($week);
        if (!$weekId) vg2_json(['success'=>false, 'message'=>'Vybraný týden nebyl nalezen.']);

        // Ukotvení slouží jen jako předloha pro budoucí den, který uživatel výslovně vybere.
        // Budoucí záznam se do databáze zapíše až po stisku tlačítka Uložit záznam.
        $sourceAnchorDate = null;
        $autoPropagated = 0;
        $requestedSourceDate = trim((string)($json['source_anchor_date'] ?? ''));
        if (!$anchorFuture && $requestedSourceDate !== '' && $requestedSourceDate < $recordDate) {
            $sourceDateObj = DateTimeImmutable::createFromFormat('!Y-m-d', $requestedSourceDate);
            $sourceDateErrors = DateTimeImmutable::getLastErrors();
            if ($sourceDateObj && (!$sourceDateErrors || (!$sourceDateErrors['warning_count'] && !$sourceDateErrors['error_count'])) && $sourceDateObj->format('Y-m-d') === $requestedSourceDate) {
                $anchorCheck = $pdo->prepare('SELECT record_date FROM growreport_vz_records2 WHERE report_id=:rid AND record_date=:source_date AND anchor_future=1 LIMIT 1');
                $anchorCheck->execute(['rid'=>$growreport_id, 'source_date'=>$requestedSourceDate]);
                if ($anchorCheck->fetchColumn()) {
                    $sourceAnchorDate = $requestedSourceDate;
                    $autoPropagated = 1;
                }
            }
        }

        $st = $pdo->prepare('INSERT INTO growreport_vz_records2 (report_id,week_number,day_number,record_date,record_type,runtime_hours,anchor_future,auto_propagated,confirmed_copy,source_anchor_date) VALUES (:rid,:week,:day,:date,:type,:hours,:anchor,:auto,:confirmed,:source_date)');
        $st->execute([
            'rid'=>$growreport_id, 'week'=>$week, 'day'=>$day, 'date'=>$recordDate,
            'type'=>$type, 'hours'=>$hours, 'anchor'=>$anchorFuture ? 1 : 0,
            'auto'=>$autoPropagated, 'confirmed'=>$autoPropagated ? 1 : 0, 'source_date'=>$sourceAnchorDate
        ]);
        $list = $pdo->prepare('SELECT id, week_number, day_number, record_date, record_type, runtime_hours, anchor_future, auto_propagated, source_anchor_date, completed, created_at, updated_at FROM growreport_vz_records2 WHERE report_id=:rid ORDER BY record_date DESC, id DESC');
        $list->execute(['rid'=>$growreport_id]);
        vg2_json(['success'=>true, 'items'=>$list->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'vz_delete') {
        $id = (int)($json['id'] ?? 0);
        if ($id < 1) vg2_json(['success'=>false, 'message'=>'Neplatné ID záznamu V/Z.']);
        $st = $pdo->prepare('SELECT record_date, anchor_future FROM growreport_vz_records2 WHERE id=:id AND report_id=:rid LIMIT 1');
        $st->execute(['id'=>$id, 'rid'=>$growreport_id]);
        $deletedRow = $st->fetch(PDO::FETCH_ASSOC);
        if (!$deletedRow) vg2_json(['success'=>false, 'message'=>'Záznam V/Z nebyl nalezen.']);
        // Již uložené budoucí dny jsou samostatné záznamy; smazání předlohy je nemaže.
        $pdo->prepare('DELETE FROM growreport_vz_records2 WHERE id=:id AND report_id=:rid')->execute(['id'=>$id, 'rid'=>$growreport_id]);
        $list = $pdo->prepare('SELECT id, week_number, day_number, record_date, record_type, runtime_hours, anchor_future, auto_propagated, source_anchor_date, completed, created_at, updated_at FROM growreport_vz_records2 WHERE report_id=:rid ORDER BY record_date DESC, id DESC');
        $list->execute(['rid'=>$growreport_id]);
        vg2_json(['success'=>true, 'items'=>$list->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'full_moon_add') {
        if (!$isAdmin) vg2_json(['success' => false, 'message' => 'Úplňky může spravovat pouze administrátor.']);
        $moonDate = trim((string)($json['moon_date'] ?? ''));
        $dateObj = DateTimeImmutable::createFromFormat('!Y-m-d', $moonDate);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$dateObj || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count'])) || $dateObj->format('Y-m-d') !== $moonDate) {
            vg2_json(['success' => false, 'message' => 'Vyber platné datum úplňku.']);
        }
        $st = $pdo->prepare('INSERT IGNORE INTO growreport_full_moons2 (moon_date, created_by) VALUES (:date, :uid)');
        $st->execute(['date' => $moonDate, 'uid' => (int)($_SESSION['user_id'] ?? 0)]);
        vg2_json(['success' => true, 'already_exists' => $st->rowCount() === 0]);
    }

    if ($action === 'full_moon_delete') {
        if (!$isAdmin) vg2_json(['success' => false, 'message' => 'Úplňky může spravovat pouze administrátor.']);
        $moonId = (int)($json['id'] ?? 0);
        if ($moonId < 1) vg2_json(['success' => false, 'message' => 'Neplatné ID úplňku.']);
        $pdo->prepare('DELETE FROM growreport_full_moons2 WHERE id=:id')->execute(['id' => $moonId]);
        vg2_json(['success' => true]);
    }

    if ($action === 'add_week_zero') {
        $existingZero = $pdo->prepare('SELECT id FROM growreport_weeks2 WHERE report_id=:rid AND week_number=0 LIMIT 1');
        $existingZero->execute(['rid' => $growreport_id]);
        if ($existingZero->fetchColumn()) {
            vg2_json(['success' => true, 'already_exists' => true, 'week_number' => 0]);
        }

        $startDayStmt = $pdo->prepare('SELECT start_day FROM growreport_weeks2 WHERE report_id=:rid AND week_number=1 LIMIT 1');
        $startDayStmt->execute(['rid' => $growreport_id]);
        $weekOneStartDay = $startDayStmt->fetchColumn();

        $insertZero = $pdo->prepare("INSERT INTO growreport_weeks2 (report_id, week_number, text_content, start_day) VALUES (:rid, 0, '', :sd)");
        $insertZero->execute(['rid' => $growreport_id, 'sd' => $weekOneStartDay ?: null]);
        vg2_json(['success' => true, 'already_exists' => false, 'week_number' => 0]);
    }

    if ($action === 'add_week') {
        $st = $pdo->prepare("SELECT COALESCE(MAX(week_number),0) FROM growreport_weeks2 WHERE report_id=:rid");
        $st->execute(['rid' => $growreport_id]);
        $next = (int)$st->fetchColumn() + 1;

        $st = $pdo->prepare("SELECT start_day FROM growreport_weeks2 WHERE report_id=:rid ORDER BY week_number DESC LIMIT 1");
        $st->execute(['rid' => $growreport_id]);
        $lastStart = $st->fetchColumn();

        $st = $pdo->prepare("INSERT INTO growreport_weeks2 (report_id, week_number, text_content, start_day) VALUES (:rid, :wn, '', :sd)");
        $st->execute(['rid' => $growreport_id, 'wn' => $next, 'sd' => $lastStart ?: null]);
        vg2_json(['success' => true, 'week_number' => $next]);
    }

    if ($action === 'delete_week') {
        $wn = (int)($json['week'] ?? 0);
        if ($wn < 0 || $wn === 1) {
            vg2_json(['success' => false, 'message' => 'Týden 1 nelze smazat.']);
        }

        $step = 'načtení týdne';
        try {
            $st = $pdo->prepare("SELECT id, photo1, photo2, photo3 FROM growreport_weeks2 WHERE report_id=:rid AND week_number=:wn LIMIT 1");
            $st->execute(['rid' => $growreport_id, 'wn' => $wn]);
            $row = $st->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                vg2_json(['success' => false, 'message' => "Týden $wn nebyl nalezen."]);
            }
            $row['photo4'] = null;
            try {
                $photo4Stmt = $pdo->prepare('SELECT file_path FROM growreport_section3_photo2 WHERE week_id=:wid LIMIT 1');
                $photo4Stmt->execute(['wid' => $row['id']]);
                $row['photo4'] = $photo4Stmt->fetchColumn() ?: null;
            } catch (Throwable $e) { /* tabulka druhé fotografie sekce 3 může chybět ve staré instalaci */ }
            $galleryPathsToDelete = [];
            try {
                $galleryPathsStmt = $pdo->prepare('SELECT file_path FROM growreport_gallery2 WHERE week_id=:wid');
                $galleryPathsStmt->execute(['wid' => $row['id']]);
                $galleryPathsToDelete = $galleryPathsStmt->fetchAll(PDO::FETCH_COLUMN, 0);
            } catch (Throwable $e) { /* galerie nemusí být vytvořená */ }

            $step = 'začátek transakce';
            $pdo->beginTransaction();

            $step = 'mazání dnů';
            $pdo->prepare("DELETE FROM growreport_days2 WHERE week_id=:wid")->execute(['wid' => $row['id']]);

            $step = 'mazání CO2';
            $pdo->prepare("DELETE FROM growreport_co2_2 WHERE week_id=:wid")->execute(['wid' => $row['id']]);

             $step = 'mazání teploty';
            $pdo->prepare("DELETE FROM growreport_temperature_2 WHERE week_id=:wid")->execute(['wid' => $row['id']]);

            $step = 'mazání vlhkosti';
            try {
                $pdo->prepare("DELETE FROM growreport_humidity_2 WHERE week_id=:wid")->execute(['wid' => $row['id']]);
            } catch (Throwable $e) { /* tabulka vlhkosti nemusí být dostupná */ }

            $step = 'mazání světel';
            try { $pdo->prepare("DELETE FROM growreport_lights2 WHERE week_id=:wid")->execute(['wid' => $row['id']]); } catch (Throwable $e) { /* starší tabulka může chybět */ }

            $step = 'mazání větráků a odtahů';
            try { $pdo->prepare("DELETE FROM growreport_airflow2 WHERE week_id=:wid")->execute(['wid' => $row['id']]); } catch (Throwable $e) { /* tabulka vzduchotechniky nemusí být vytvořena */ }

            $step = 'mazání označení problémů';
            try { $pdo->prepare("DELETE FROM growreport_problem_marks2 WHERE week_id=:wid")->execute(['wid' => $row['id']]); } catch (Throwable $e) { /* tabulka označení problémů nemusí být vytvořena */ }
            try { $pdo->prepare("DELETE FROM growreport_daily_tasks2 WHERE week_id=:wid")->execute(['wid' => $row['id']]); } catch (Throwable $e) { /* tabulka potvrzení denního plánu nemusí být dostupná */ }
            try { $pdo->prepare("DELETE FROM growreport_spray_actions2 WHERE week_id=:wid")->execute(['wid' => $row['id']]); } catch (Throwable $e) { /* tabulka postřiků může chybět ve starší instalaci */ }
            try { $pdo->prepare("DELETE FROM growreport_net_marks2 WHERE week_id=:wid")->execute(['wid' => $row['id']]); } catch (Throwable $e) { /* tabulka sítě může chybět ve starší instalaci */ }

            $step = 'mazání galerie';
            try { $pdo->prepare('DELETE FROM growreport_gallery2 WHERE week_id=:wid')->execute(['wid' => $row['id']]); } catch (Throwable $e) { /* galerie může chybět */ }
            try { $pdo->prepare('DELETE FROM growreport_section3_photo2 WHERE week_id=:wid')->execute(['wid' => $row['id']]); } catch (Throwable $e) { /* druhá fotografie může chybět */ }

            $step = 'mazání zalévání';
            try {
                $pdo->prepare("DELETE FROM growreport_watering2 WHERE week_id=:wid")->execute(['wid' => $row['id']]);
            } catch (Throwable $e) { /* tabulka nemusí existovat */ }

            $step = 'mazání týdne';
            $pdo->prepare("DELETE FROM growreport_weeks2 WHERE id=:id")->execute(['id' => $row['id']]);

            $step = 'přečíslování';
            // Týden 0 je před týdnem 1; jeho smazání nesmí měnit číslování běžných týdnů.
            if ($wn > 0) {
                $pdo->prepare("UPDATE growreport_weeks2 SET week_number = week_number - 1 WHERE report_id=:rid AND week_number > :wn")
                    ->execute(['rid' => $growreport_id, 'wn' => $wn]);
                try { $pdo->prepare("UPDATE growreport_spray_actions2 SET week_number=week_number-1 WHERE report_id=:rid AND week_number > :wn")->execute(['rid'=>$growreport_id, 'wn'=>$wn]); } catch (Throwable $e) { /* zpětná kompatibilita */ }
                try { $pdo->prepare("UPDATE growreport_net_marks2 SET week_number=week_number-1 WHERE report_id=:rid AND week_number > :wn")->execute(['rid'=>$growreport_id, 'wn'=>$wn]); } catch (Throwable $e) { /* zpětná kompatibilita */ }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("delete_week chyba [$step]: " . $e->getMessage());
            vg2_json(['success' => false, 'message' => "Chyba při kroku: $step\n" . $e->getMessage()]);
        }

        $baseDir = realpath('uploads/growreports2');
        foreach (['photo1', 'photo2', 'photo3', 'photo4'] as $col) {
            if (!empty($row[$col])) {
                $real = realpath($row[$col]);
                if ($baseDir && $real && strpos($real, $baseDir . DIRECTORY_SEPARATOR) === 0 && is_file($real)) {
                    @unlink($real);
                }
            }
        }
        $galleryDir = realpath(__DIR__ . '/uploads/growreports2/gallery');
        foreach ($galleryPathsToDelete as $galleryPath) {
            $real = realpath(__DIR__ . '/' . ltrim((string)$galleryPath, '/'));
            if ($galleryDir && $real && strpos($real, $galleryDir . DIRECTORY_SEPARATOR) === 0 && is_file($real)) @unlink($real);
        }

        vg2_json(['success' => true]);
    }

    if ($action === 'save') {
        $weeks = $_POST['weeks'] ?? [];
        $uploadDir = 'uploads/growreports2/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        foreach ($weeks as $idx => $week) {
            $wn = (int)($week['week_number'] ?? 0);
            if ($wn < 0) continue;

            $text = (string)($week['text'] ?? '');
            $startDay = trim((string)($week['start_day'] ?? ''));

            $photos = [1 => null, 2 => null, 3 => null, 4 => null];
            for ($p = 1; $p <= 4; $p++) {
                if (isset($_FILES['weeks']['error'][$idx]["photo{$p}"]) &&
                    $_FILES['weeks']['error'][$idx]["photo{$p}"] === UPLOAD_ERR_OK) {
                    $origName = $_FILES['weeks']['name'][$idx]["photo{$p}"];
                    $tmp = $_FILES['weeks']['tmp_name'][$idx]["photo{$p}"];
                    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowedExt, true)) continue;
                    if (@getimagesize($tmp) === false) continue;
                    $fn = uniqid('gr2_') . '.' . $ext;
                    if (move_uploaded_file($tmp, $uploadDir . $fn)) {
                        $photos[$p] = $uploadDir . $fn;
                    }
                }
            }

            $weekId = $getWeekId($wn);
            if ($weekId) {
                $st = $pdo->prepare("SELECT photo1, photo2, photo3 FROM growreport_weeks2 WHERE id=:id");
                $st->execute(['id' => $weekId]);
                $row = $st->fetch(PDO::FETCH_ASSOC);

                $st = $pdo->prepare("UPDATE growreport_weeks2
                                     SET text_content=:txt, photo1=:p1, photo2=:p2, photo3=:p3, start_day=:sd
                                     WHERE id=:id");
                $st->execute([
                    'txt' => $text,
                    'p1' => $photos[1] ?? $row['photo1'],
                    'p2' => $photos[2] ?? $row['photo2'],
                    'p3' => $photos[3] ?? $row['photo3'],
                    'sd' => $startDay !== '' ? $startDay : null,
                    'id' => $weekId
                ]);
                if (!empty($photos[4])) {
                    $photo4Stmt = $pdo->prepare('INSERT INTO growreport_section3_photo2 (week_id,file_path) VALUES (:wid,:path) ON DUPLICATE KEY UPDATE file_path=VALUES(file_path)');
                    $photo4Stmt->execute(['wid' => $weekId, 'path' => $photos[4]]);
                }
            } else {
                $st = $pdo->prepare("INSERT INTO growreport_weeks2 (report_id, week_number, text_content, photo1, photo2, photo3, start_day)
                                     VALUES (:rid, :wn, :txt, :p1, :p2, :p3, :sd)");
                $st->execute([
                    'rid' => $growreport_id,
                    'wn' => $wn,
                    'txt' => $text,
                    'p1' => $photos[1],
                    'p2' => $photos[2],
                    'p3' => $photos[3],
                    'sd' => $startDay !== '' ? $startDay : null
                ]);
                $weekId = (int)$pdo->lastInsertId();
                if (!empty($photos[4])) {
                    $photo4Stmt = $pdo->prepare('INSERT INTO growreport_section3_photo2 (week_id,file_path) VALUES (:wid,:path) ON DUPLICATE KEY UPDATE file_path=VALUES(file_path)');
                    $photo4Stmt->execute(['wid' => $weekId, 'path' => $photos[4]]);
                }
            }

            $hnojeno = array_filter(array_map('intval', explode(',', (string)($week['hnojeno'] ?? ''))));

            $pdo->prepare("DELETE FROM growreport_days2 WHERE week_id=:wid")->execute(['wid' => $weekId]);
            $ins = $pdo->prepare("INSERT INTO growreport_days2 (week_id, day_number, zalito, hnojeno) VALUES (:wid, :dn, 0, :h)");
            for ($d = 1; $d <= 7; $d++) {
                $h = in_array($d, $hnojeno, true) ? 1 : 0;
                if ($h) {
                    $ins->execute(['wid' => $weekId, 'dn' => $d, 'h' => $h]);
                }
            }
        }

        vg2_json(['success' => true, 'report_id' => $growreport_id]);
    }

    if ($action === 'save_text') {
        $wn = (int)($json['week'] ?? 0);
        $text = (string)($json['text'] ?? '');
        $st = $pdo->prepare("UPDATE growreport_weeks2 SET text_content=:t WHERE report_id=:rid AND week_number=:wn");
        $st->execute(['t' => $text, 'rid' => $growreport_id, 'wn' => $wn]);
        vg2_json(['success' => true]);
    }

    if ($action === 'phase') {
        $wn = (int)($json['week'] ?? 0);
        $phase = trim((string)($json['phase'] ?? ''));
        $color = trim((string)($json['color'] ?? ''));
        if (!in_array($color, ['green', 'red', 'pink'], true)) {
            vg2_json(['success' => false, 'message' => 'Neplatná barva.']);
        }
        $st = $pdo->prepare("UPDATE growreport_weeks2 SET phase=:p, phase_color=:c WHERE report_id=:rid AND week_number=:wn");
        $st->execute(['p' => $phase, 'c' => $color, 'rid' => $growreport_id, 'wn' => $wn]);
        vg2_json(['success' => true]);
    }

    if ($action === 'start_day') {
        $wn = (int)($json['week'] ?? 0);
        $sd = trim((string)($json['start_day'] ?? ''));
        $st = $pdo->prepare("UPDATE growreport_weeks2 SET start_day=:sd WHERE report_id=:rid AND week_number=:wn");
        $st->execute(['sd' => $sd, 'rid' => $growreport_id, 'wn' => $wn]);
        vg2_json(['success' => true]);
    }

    if ($action === 'toggle_day') {
        $wn = (int)($json['week'] ?? 0);
        $day = (int)($json['day'] ?? 0);
        $field = (string)($json['type'] ?? '');
        $value = !empty($json['checked']) ? 1 : 0;

        if (!in_array($field, ['hnojeno'], true) || $day < 1 || $day > 7) {
            vg2_json(['success' => false, 'message' => 'Neplatná data.']);
        }

        $weekId = $getWeekId($wn);
        if (!$weekId) vg2_json(['success' => false, 'message' => 'Týden nebyl nalezen.']);

        $st = $pdo->prepare("INSERT INTO growreport_days2 (week_id, day_number, {$field}) VALUES (:wid, :dn, :val)
                             ON DUPLICATE KEY UPDATE {$field}=VALUES({$field})");
        $st->execute(['wid' => $weekId, 'dn' => $day, 'val' => $value]);
        vg2_json(['success' => true]);
    }

    if ($action === 'custom_fertilizers_get') {
        $schema = vg2_custom_fertilizer_schema($pdo);
        if ($schema['layout'] === 'json') {
            $st = $pdo->prepare("SELECT liquid_json, dry_json FROM growreport_custom_fertilizers2 WHERE report_id=:rid LIMIT 1");
            $st->execute(['rid' => $growreport_id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $liquid = $row ? json_decode($row['liquid_json'], true) : null;
            $dry = $row ? json_decode($row['dry_json'], true) : null;
            if (!is_array($liquid)) $liquid = array_fill(0, 20, '');
            if (!is_array($dry)) $dry = array_fill(0, 10, '');
            vg2_json(['success' => true, 'liquid' => array_slice(array_pad($liquid, 20, ''), 0, 20), 'dry' => array_slice(array_pad($dry, 10, ''), 0, 10)]);
        }
        if ($schema['layout'] === 'slots') {
            $liquid = array_fill(0, 20, '');
            $dry = array_fill(0, 10, '');
            $uid = (int)$growreport['user_id'];
            $st = $pdo->prepare("SELECT kind, slot_number, name FROM growreport_custom_fertilizers2 WHERE user_id=:uid ORDER BY kind, slot_number");
            $st->execute(['uid' => $uid]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $slot = (int)$row['slot_number'] - 1;
                if ($row['kind'] === 'liquid' && $slot >= 0 && $slot < 20) $liquid[$slot] = (string)$row['name'];
                if ($row['kind'] === 'dry' && $slot >= 0 && $slot < 10) $dry[$slot] = (string)$row['name'];
            }
            vg2_json(['success' => true, 'liquid' => $liquid, 'dry' => $dry]);
        }
        vg2_json(['success' => false, 'message' => 'Tabulka vlastních hnojiv má neznámou strukturu. Sloupce: ' . implode(', ', $schema['columns'])]);
    }

    if ($action === 'custom_fertilizers_save') {
        $liquid = $json['liquid'] ?? [];
        $dry = $json['dry'] ?? [];
        if (!is_array($liquid) || !is_array($dry) || count($liquid) !== 20 || count($dry) !== 10) {
            vg2_json(['success' => false, 'message' => 'Vyplň 20 tekutých a 10 suchých polí.']);
        }
        $clean = function ($arr) { return array_map(function ($v) { return vg2_substr(trim(strip_tags((string)$v)), 0, 80); }, array_values($arr)); };
        $liquid = $clean($liquid);
        $dry = $clean($dry);
        $schema = vg2_custom_fertilizer_schema($pdo);
        if ($schema['layout'] === 'json') {
            $st = $pdo->prepare("INSERT INTO growreport_custom_fertilizers2 (report_id, liquid_json, dry_json) VALUES (:rid,:l,:d) ON DUPLICATE KEY UPDATE liquid_json=VALUES(liquid_json), dry_json=VALUES(dry_json)");
            $st->execute(['rid' => $growreport_id, 'l' => json_encode($liquid, JSON_UNESCAPED_UNICODE), 'd' => json_encode($dry, JSON_UNESCAPED_UNICODE)]);
        } elseif ($schema['layout'] === 'slots') {
            // Backwards compatible with the table created by the older Growreport version.
            $uid = (int)$growreport['user_id'];
            $st = $pdo->prepare("INSERT INTO growreport_custom_fertilizers2 (user_id, kind, slot_number, name) VALUES (:uid,:kind,:slot,:name) ON DUPLICATE KEY UPDATE name=VALUES(name)");
            foreach ($liquid as $i => $name) $st->execute(['uid' => $uid, 'kind' => 'liquid', 'slot' => $i + 1, 'name' => $name]);
            foreach ($dry as $i => $name) $st->execute(['uid' => $uid, 'kind' => 'dry', 'slot' => $i + 1, 'name' => $name]);
        } else {
            vg2_json(['success' => false, 'message' => 'Tabulka vlastních hnojiv má neznámou strukturu. Sloupce: ' . implode(', ', $schema['columns'])]);
        }
        vg2_json(['success' => true, 'liquid' => $liquid, 'dry' => $dry]);
    }

    // Načte skutečně uložené záznamy HNOJIV z Growreportu (nikoli údaje o zalévání)
    // a sestaví z nich samostatnou průměrnou recepturu pro RŮST a KVĚT.
    if ($action === 'auto_fertilizer_history_import') {
        $weekStmt = $pdo->prepare('SELECT id, week_number, phase, phase_color FROM growreport_weeks2 WHERE report_id=:rid AND week_number>=1 ORDER BY week_number ASC');
        $weekStmt->execute(['rid' => $growreport_id]);
        $phaseByWeek = [];
        foreach ($weekStmt->fetchAll(PDO::FETCH_ASSOC) as $weekRow) {
            $wn = (int)$weekRow['week_number'];
            $phaseText = mb_strtolower(trim((string)($weekRow['phase'] ?? '')), 'UTF-8');
            $phaseColor = strtolower(trim((string)($weekRow['phase_color'] ?? '')));
            if (strpos($phaseText, 'sušin') !== false || strpos($phaseText, 'susin') !== false || $phaseColor === 'pink') $phase = 'dry';
            elseif (strpos($phaseText, 'květ') !== false || strpos($phaseText, 'kvet') !== false || $phaseColor === 'red') $phase = 'flower';
            elseif (strpos($phaseText, 'růst') !== false || strpos($phaseText, 'rust') !== false || $phaseColor === 'green') $phase = 'growth';
            else $phase = $wn <= 9 ? 'growth' : 'flower';
            $phaseByWeek[$wn] = $phase;
        }

        $historyStmt = $pdo->prepare("SELECT w.week_number, f.day_number, f.items_json
            FROM growreport_fertilizers2 f
            INNER JOIN growreport_weeks2 w ON w.id=f.week_id
            WHERE w.report_id=:rid AND w.week_number>=1
            ORDER BY w.week_number ASC, f.day_number ASC");
        $historyStmt->execute(['rid' => $growreport_id]);
        $aggregates = ['growth' => [], 'flower' => []];
        $usedRecords = ['growth' => 0, 'flower' => 0];
        foreach ($historyStmt->fetchAll(PDO::FETCH_ASSOC) as $historyRow) {
            $weekNumber = (int)$historyRow['week_number'];
            $phase = $phaseByWeek[$weekNumber] ?? ($weekNumber <= 9 ? 'growth' : 'flower');
            if (!in_array($phase, ['growth', 'flower'], true)) continue;
            $decoded = json_decode((string)$historyRow['items_json'], true);
            if (!is_array($decoded) || !$decoded) continue;
            $summary = vg2_fertilizer_summary($decoded, $knownDryFertilizerNames);
            if (!$summary['items']) continue;
            $usedRecords[$phase]++;
            foreach ($summary['items'] as $item) {
                $name = trim((string)($item['name'] ?? ''));
                $kind = (string)($item['kind'] ?? 'liquid');
                if ($name === '' || !in_array($kind, ['liquid', 'dry'], true)) continue;
                $dose = $kind === 'dry' ? (int)($item['scoops'] ?? 0) : (int)($item['ml'] ?? 0);
                if ($dose < 1) continue;
                $key = $kind . '|' . mb_strtolower($name, 'UTF-8');
                if (!isset($aggregates[$phase][$key])) {
                    $aggregates[$phase][$key] = ['name' => $name, 'kind' => $kind, 'sum' => 0, 'count' => 0];
                }
                $aggregates[$phase][$key]['sum'] += $dose;
                $aggregates[$phase][$key]['count']++;
            }
        }

        $recipes = [];
        $tableRows = [];
        foreach (['growth' => 'RŮST', 'flower' => 'KVĚT'] as $phaseKey => $phaseName) {
            $items = [];
            foreach ($aggregates[$phaseKey] as $aggregate) {
                $average = (int)round($aggregate['sum'] / max(1, $aggregate['count']));
                $average = max(1, min($aggregate['kind'] === 'dry' ? 40 : 500, $average));
                if ($aggregate['kind'] === 'dry') {
                    $items[] = ['name' => $aggregate['name'], 'kind' => 'dry', 'base_scoops' => $average];
                    $unit = 'odměrek';
                } else {
                    $items[] = ['name' => $aggregate['name'], 'kind' => 'liquid', 'base_ml' => $average];
                    $unit = 'ml';
                }
                $tableRows[] = [
                    'phase' => $phaseName,
                    'name' => $aggregate['name'],
                    'kind' => $aggregate['kind'],
                    'average' => $average,
                    'unit' => $unit,
                    'count' => (int)$aggregate['count']
                ];
            }
            $recipes[] = ['name' => $phaseName, 'items' => $items];
        }
        $totalRecords = (int)$usedRecords['growth'] + (int)$usedRecords['flower'];
        vg2_json([
            'success' => true,
            'recipes' => $recipes,
            'rows' => $tableRows,
            'record_count' => $totalRecords,
            'phase_record_counts' => $usedRecords,
            'source' => 'growreport_fertilizers2'
        ]);
    }

    if ($action === 'auto_fertilizer_settings_get') {
        $weekStmt = $pdo->prepare('SELECT week_number, phase, phase_color FROM growreport_weeks2 WHERE report_id=:rid AND week_number>=1 ORDER BY week_number ASC');
        $weekStmt->execute(['rid' => $growreport_id]);
        $weekPhaseRows = $weekStmt->fetchAll(PDO::FETCH_ASSOC);
        $weekNumbers = array_map(static function($r) { return (int)$r['week_number']; }, $weekPhaseRows);
        $weekPhases = [];
        foreach ($weekPhaseRows as $phaseRow) {
            $wn = (int)$phaseRow['week_number'];
            $phaseText = mb_strtolower(trim((string)($phaseRow['phase'] ?? '')), 'UTF-8');
            $phaseColor = strtolower(trim((string)($phaseRow['phase_color'] ?? '')));
            if (strpos($phaseText, 'sušin') !== false || $phaseColor === 'pink') $phase = 'dry';
            elseif (strpos($phaseText, 'květ') !== false || strpos($phaseText, 'kvet') !== false || $phaseColor === 'red') $phase = 'flower';
            elseif (strpos($phaseText, 'růst') !== false || strpos($phaseText, 'rust') !== false || $phaseColor === 'green') $phase = 'growth';
            else $phase = $wn <= 9 ? 'growth' : 'flower';
            $weekPhases[(string)$wn] = $phase;
        }
        $maxWeek = $weekNumbers ? max($weekNumbers) : 1;
        $settingsStmt = $pdo->prepare('SELECT settings_json FROM growreport_auto_fertilizer_settings2 WHERE report_id=:rid LIMIT 1');
        $settingsStmt->execute(['rid' => $growreport_id]);
        $rawSettings = $settingsStmt->fetchColumn();
        $settings = is_string($rawSettings) ? json_decode($rawSettings, true) : null;
        if (!is_array($settings)) $settings = null;
        $globalRecipes = [];
        $globalStmt = $pdo->prepare('SELECT slot_number, name, items_json FROM growreport_user_fertilizer_recipes2 WHERE user_id=:uid ORDER BY slot_number');
        $globalStmt->execute(['uid' => (int)$growreport['user_id']]);
        foreach ($globalStmt->fetchAll(PDO::FETCH_ASSOC) as $globalRow) {
            $globalSlot = (int)$globalRow['slot_number'];
            if ($globalSlot < 1 || $globalSlot > 5) continue;
            $globalItems = json_decode((string)$globalRow['items_json'], true);
            if (!is_array($globalItems)) $globalItems = [];
            $globalRecipes[$globalSlot] = [
                'slot' => $globalSlot,
                'name' => vg2_substr(trim(strip_tags((string)$globalRow['name'])), 0, 80),
                'items' => $globalItems
            ];
        }
        for ($slot = 1; $slot <= 5; $slot++) {
            if (!isset($globalRecipes[$slot])) $globalRecipes[$slot] = ['slot' => $slot, 'name' => 'Samostatná receptura ' . $slot, 'items' => []];
        }
        ksort($globalRecipes);
        vg2_json(['success' => true, 'settings' => $settings, 'global_recipes' => array_values($globalRecipes), 'max_week' => $maxWeek, 'weeks' => $weekNumbers, 'week_phases' => $weekPhases]);
    }

    if ($action === 'auto_fertilizer_settings_save') {
        $weekStmt = $pdo->prepare('SELECT COALESCE(MAX(week_number),1) FROM growreport_weeks2 WHERE report_id=:rid AND week_number>=1');
        $weekStmt->execute(['rid' => $growreport_id]);
        $maxWeek = max(1, (int)$weekStmt->fetchColumn());
        try {
            $settings = vg2_auto_fertilizer_clean_settings($json['settings'] ?? $json, $maxWeek);
        } catch (InvalidArgumentException $e) {
            vg2_json(['success' => false, 'message' => $e->getMessage()]);
        }
        $settingsStmt = $pdo->prepare('INSERT INTO growreport_auto_fertilizer_settings2 (report_id,settings_json) VALUES (:rid,:settings) ON DUPLICATE KEY UPDATE settings_json=VALUES(settings_json)');
        $settingsStmt->execute(['rid' => $growreport_id, 'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
        vg2_json(['success' => true, 'settings' => $settings, 'max_week' => $maxWeek]);
    }

    if ($action === 'user_fertilizer_recipes_get') {
        $uid = (int)$growreport['user_id'];
        $recipes = [];
        for ($slot = 1; $slot <= 5; $slot++) {
            $recipes[$slot] = ['slot' => $slot, 'name' => 'Samostatná receptura ' . $slot, 'items' => []];
        }
        $st = $pdo->prepare('SELECT slot_number, name, items_json FROM growreport_user_fertilizer_recipes2 WHERE user_id=:uid ORDER BY slot_number');
        $st->execute(['uid' => $uid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $slot = (int)$row['slot_number'];
            if ($slot < 1 || $slot > 5) continue;
            $items = json_decode((string)$row['items_json'], true);
            if (!is_array($items)) $items = [];
            $recipes[$slot] = [
                'slot' => $slot,
                'name' => vg2_substr(trim(strip_tags((string)$row['name'])), 0, 80),
                'items' => $items
            ];
        }
        vg2_json(['success' => true, 'recipes' => array_values($recipes)]);
    }

    if ($action === 'user_fertilizer_recipe_save') {
        $slot = filter_var($json['slot'] ?? null, FILTER_VALIDATE_INT);
        if ($slot === false || $slot < 1 || $slot > 5) {
            vg2_json(['success' => false, 'message' => 'Vyber samostatnou recepturu 1 až 5.']);
        }
        $rawRecipe = is_array($json['recipe'] ?? null) ? $json['recipe'] : [];
        $name = vg2_substr(trim(strip_tags((string)($rawRecipe['name'] ?? ''))), 0, 80);
        if ($name === '') $name = 'Samostatná receptura ' . $slot;
        try {
            $items = vg2_auto_fertilizer_clean_recipe_items($rawRecipe['items'] ?? []);
        } catch (InvalidArgumentException $e) {
            vg2_json(['success' => false, 'message' => $e->getMessage()]);
        }
        if (!$items) {
            $delete = $pdo->prepare('DELETE FROM growreport_user_fertilizer_recipes2 WHERE user_id=:uid AND slot_number=:slot');
            $delete->execute(['uid' => (int)$growreport['user_id'], 'slot' => (int)$slot]);
            vg2_json(['success' => true, 'deleted' => true, 'slot' => (int)$slot]);
        }
        $st = $pdo->prepare('INSERT INTO growreport_user_fertilizer_recipes2 (user_id, slot_number, name, items_json) VALUES (:uid,:slot,:name,:items) ON DUPLICATE KEY UPDATE name=VALUES(name), items_json=VALUES(items_json)');
        $st->execute([
            'uid' => (int)$growreport['user_id'],
            'slot' => (int)$slot,
            'name' => $name,
            'items' => json_encode($items, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
        ]);
        vg2_json(['success' => true, 'recipe' => ['slot' => (int)$slot, 'name' => $name, 'items' => $items]]);
    }

    if ($action === 'fertilizer_recipe_library_get') {
        $phase = strtoupper(trim((string)($json['phase'] ?? '')));
        if (!in_array($phase, ['GROWTH', 'FLOWER'], true)) vg2_json(['success' => false, 'message' => 'Neplatná fáze receptury.']);
        $st = $pdo->prepare('SELECT id, recipe_name, items_json FROM growreport_fertilizer_recipe_library2 WHERE user_id=:uid AND phase=:phase ORDER BY created_at DESC, id DESC LIMIT 10');
        $st->execute(['uid' => (int)$growreport['user_id'], 'phase' => $phase]);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items = json_decode((string)$row['items_json'], true);
            $rows[] = ['id' => (int)$row['id'], 'name' => (string)$row['recipe_name'], 'items' => is_array($items) ? $items : []];
        }
        vg2_json(['success' => true, 'recipes' => $rows]);
    }

    if ($action === 'fertilizer_recipe_library_save') {
        $phase = strtoupper(trim((string)($json['phase'] ?? '')));
        $name = vg2_substr(trim(strip_tags((string)($json['name'] ?? ''))), 0, 80);
        if (!in_array($phase, ['GROWTH', 'FLOWER'], true) || $name === '') vg2_json(['success' => false, 'message' => 'Zadej název receptury.']);
        try {
            $items = vg2_auto_fertilizer_clean_recipe_items($json['items'] ?? []);
        } catch (InvalidArgumentException $e) {
            vg2_json(['success' => false, 'message' => $e->getMessage()]);
        }
        if (!$items) vg2_json(['success' => false, 'message' => 'Receptura neobsahuje žádné vybrané hnojivo.']);
        $uid = (int)$growreport['user_id'];
        $count = $pdo->prepare('SELECT COUNT(*) FROM growreport_fertilizer_recipe_library2 WHERE user_id=:uid AND phase=:phase');
        $count->execute(['uid' => $uid, 'phase' => $phase]);
        if ((int)$count->fetchColumn() >= 10) vg2_json(['success' => false, 'message' => 'Pro tuto fázi už je uloženo 10 receptur. Nejprve některou uvolni.']);
        $st = $pdo->prepare('INSERT INTO growreport_fertilizer_recipe_library2 (user_id, phase, recipe_name, items_json) VALUES (:uid,:phase,:name,:items)');
        $st->execute(['uid' => $uid, 'phase' => $phase, 'name' => $name, 'items' => json_encode($items, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
        vg2_json(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
    }

    if ($action === 'auto_fertilizer_apply') {
        $weekStmt = $pdo->prepare('SELECT id, week_number, phase, phase_color FROM growreport_weeks2 WHERE report_id=:rid AND week_number>=1 ORDER BY week_number ASC');
        $weekStmt->execute(['rid' => $growreport_id]);
        $weekRows = $weekStmt->fetchAll(PDO::FETCH_ASSOC);
        $weeksByNumber = []; $phaseByWeek = []; $maxWeek = 1;
        foreach ($weekRows as $weekRow) {
            $weekNumber = (int)$weekRow['week_number'];
            $weeksByNumber[$weekNumber] = (int)$weekRow['id'];
            $phaseText = mb_strtolower(trim((string)($weekRow['phase'] ?? '')), 'UTF-8');
            $phaseColor = strtolower(trim((string)($weekRow['phase_color'] ?? '')));
            if (strpos($phaseText, 'sušin') !== false || strpos($phaseText, 'susin') !== false || $phaseColor === 'pink') $phase = 'dry';
            elseif (strpos($phaseText, 'květ') !== false || strpos($phaseText, 'kvet') !== false || $phaseColor === 'red') $phase = 'flower';
            elseif (strpos($phaseText, 'růst') !== false || strpos($phaseText, 'rust') !== false || $phaseColor === 'green') $phase = 'growth';
            else $phase = $weekNumber <= 9 ? 'growth' : 'flower';
            $phaseByWeek[$weekNumber] = $phase;
            if ($weekNumber > $maxWeek) $maxWeek = $weekNumber;
        }
        try {
            $settings = vg2_auto_fertilizer_clean_settings($json['settings'] ?? $json, $maxWeek);
        } catch (InvalidArgumentException $e) {
            vg2_json(['success' => false, 'message' => $e->getMessage()]);
        }
        if (!$weeksByNumber) vg2_json(['success' => false, 'message' => 'Growreport zatím neobsahuje žádné týdny od týdne 1.']);

        // Načíst pět samostatných receptur z knihovny uživatele (sdílené mezi jeho growreporty).
        $globalRecipes = [];
        $globalRecipeStmt = $pdo->prepare('SELECT slot_number, name, items_json FROM growreport_user_fertilizer_recipes2 WHERE user_id=:uid');
        $globalRecipeStmt->execute(['uid' => (int)$growreport['user_id']]);
        foreach ($globalRecipeStmt->fetchAll(PDO::FETCH_ASSOC) as $globalRow) {
            $globalSlot = (int)$globalRow['slot_number'];
            if ($globalSlot < 1 || $globalSlot > 5) continue;
            $globalItems = json_decode((string)$globalRow['items_json'], true);
            if (!is_array($globalItems)) $globalItems = [];
            $globalRecipes[$globalSlot] = [
                'name' => vg2_substr(trim(strip_tags((string)$globalRow['name'])), 0, 80),
                'items' => vg2_auto_fertilizer_clean_recipe_items($globalItems)
            ];
        }

        $fertilizerColumns = $pdo->query('SHOW COLUMNS FROM growreport_fertilizers2')->fetchAll(PDO::FETCH_COLUMN, 0);
        if (!in_array('auto_generated', $fertilizerColumns, true)) {
            vg2_json(['success' => false, 'message' => 'Databáze nemá připravené označení automatických hnojiv. Obnov stránku nebo kontaktuj správce hostingu.']);
        }

        // Vygenerovat plán: 1. zálivka je s hnojivy, další dvě jen voda, čtvrtá znovu s hnojivy.
        // Interval mezi zálivkami je 2, 3, 4 nebo 5 dní; navýšení platí vůči základní receptuře daného týdne.
        $planRows = [];
        $maxGlobalDay = $maxWeek * 7;
        $frequency = (int)$settings['frequency'];
        for ($wateringIndex = 0, $globalDay = 1; $globalDay <= $maxGlobalDay; $wateringIndex++, $globalDay += $frequency) {
            if (($wateringIndex % 3) !== 0) continue; // po dvou zálivkách pouze vodou následuje hnojení
            $weekNumber = (int)floor(($globalDay - 1) / 7) + 1;
            $dayNumber = (($globalDay - 1) % 7) + 1;
            if (!isset($weeksByNumber[$weekNumber])) continue;
            $phase = $phaseByWeek[$weekNumber] ?? ($weekNumber <= 9 ? 'growth' : 'flower');
            if ($phase === 'dry') continue;
            $percent = (int)($settings['week_increases'][(string)$weekNumber] ?? 0);
            $recipeSlot = (int)($settings['week_recipes'][(string)$weekNumber] ?? ($phase === 'flower' ? 2 : 1));
            if ($recipeSlot <= 2) {
                $recipe = $settings['recipes'][$recipeSlot - 1] ?? null;
            } else {
                $recipe = $globalRecipes[$recipeSlot - 2] ?? null;
            }
            $recipeItems = is_array($recipe) ? ($recipe['items'] ?? []) : [];
            if (!$recipeItems) continue;
            $items = [];
            $totalMl = 0;
            foreach ($recipeItems as $baseItem) {
                $doseKey = $baseItem['kind'] . '|' . $baseItem['name'];
                $manualDose = $settings['week_doses'][(string)$weekNumber][$doseKey] ?? null;
                if ($baseItem['kind'] === 'dry') {
                    $dose = $manualDose !== null ? (int)$manualDose : (int)round((int)$baseItem['base_scoops'] * (100 + $percent) / 100);
                    if ($dose < 1 || $dose > 40) {
                        vg2_json(['success' => false, 'message' => 'Navýšení v týdnu ' . $weekNumber . ' (' . ($phase === 'flower' ? 'Květ' : 'Růst') . ') by u suchého hnojiva „' . $baseItem['name'] . '“ vytvořilo ' . $dose . ' odměrek. Povolený rozsah je 1–40; sniž základní dávku nebo procento navýšení.']);
                    }
                    $items[] = ['name' => $baseItem['name'], 'kind' => 'dry', 'scoops' => $dose];
                } else {
                    $dose = $manualDose !== null ? (int)$manualDose : (int)round((int)$baseItem['base_ml'] * (100 + $percent) / 100);
                    if ($dose < 1 || $dose > 500) {
                        vg2_json(['success' => false, 'message' => 'Navýšení v týdnu ' . $weekNumber . ' (' . ($phase === 'flower' ? 'Květ' : 'Růst') . ') by u tekutého hnojiva „' . $baseItem['name'] . '“ vytvořilo ' . $dose . ' ml. Povolený rozsah je 1–500 ml; sniž základní dávku nebo procento navýšení.']);
                    }
                    $items[] = ['name' => $baseItem['name'], 'kind' => 'liquid', 'ml' => $dose];
                    $totalMl += $dose;
                }
            }
            $planRows[] = [
                'week' => $weekNumber,
                'day' => $dayNumber,
                'week_id' => $weeksByNumber[$weekNumber],
                'phase' => $phase,
                'recipe_name' => (string)($recipe['name'] ?? ('Možnost ' . $recipeSlot)),
                'items' => $items,
                'total_ml' => $totalMl
            ];
        }
        if (!$planRows) vg2_json(['success' => false, 'message' => 'Z vybraného intervalu a nastavených fází se nepodařilo vytvořit žádný termín hnojení. Zkontroluj zvolenou možnost hnojení a označení týdnů.']);

        try {
            $pdo->beginTransaction();
            $settingsStmt = $pdo->prepare('INSERT INTO growreport_auto_fertilizer_settings2 (report_id,settings_json) VALUES (:rid,:settings) ON DUPLICATE KEY UPDATE settings_json=VALUES(settings_json)');
            $settingsStmt->execute(['rid' => $growreport_id, 'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);

            // Odstranit jen předchozí automaticky generované záznamy; ručně zadaná hnojiva mimo nové termíny zůstávají.
            $oldAutoStmt = $pdo->prepare('SELECT f.week_id, f.day_number FROM growreport_fertilizers2 f INNER JOIN growreport_weeks2 w ON w.id=f.week_id WHERE w.report_id=:rid AND w.week_number>=1 AND f.auto_generated=1');
            $oldAutoStmt->execute(['rid' => $growreport_id]);
            $oldAutoRows = $oldAutoStmt->fetchAll(PDO::FETCH_ASSOC);
            $pdo->prepare('DELETE f FROM growreport_fertilizers2 f INNER JOIN growreport_weeks2 w ON w.id=f.week_id WHERE w.report_id=:rid AND w.week_number>=1 AND f.auto_generated=1')
                ->execute(['rid' => $growreport_id]);
            $clearDay = $pdo->prepare('UPDATE growreport_days2 SET hnojeno=0 WHERE week_id=:wid AND day_number=:day');
            foreach ($oldAutoRows as $oldAuto) {
                $leftStmt = $pdo->prepare('SELECT COUNT(*) FROM growreport_fertilizers2 WHERE week_id=:wid AND day_number=:day');
                $leftStmt->execute(['wid' => (int)$oldAuto['week_id'], 'day' => (int)$oldAuto['day_number']]);
                if ((int)$leftStmt->fetchColumn() === 0) {
                    $clearDay->execute(['wid' => (int)$oldAuto['week_id'], 'day' => (int)$oldAuto['day_number']]);
                }
            }

            $saveAuto = $pdo->prepare('INSERT INTO growreport_fertilizers2 (week_id,day_number,mode,items_json,total_ml,auto_generated) VALUES (:wid,:day,:mode,:items,:total,1) ON DUPLICATE KEY UPDATE mode=VALUES(mode),items_json=VALUES(items_json),total_ml=VALUES(total_ml),auto_generated=1');
            $markFertilized = $pdo->prepare('INSERT INTO growreport_days2 (week_id,day_number,hnojeno) VALUES (:wid,:day,1) ON DUPLICATE KEY UPDATE hnojeno=1');
            foreach ($planRows as $planRow) {
                $saveAuto->execute([
                    'wid' => $planRow['week_id'], 'day' => $planRow['day'], 'mode' => 'preset',
                    'items' => json_encode($planRow['items'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                    'total' => $planRow['total_ml']
                ]);
                $markFertilized->execute(['wid' => $planRow['week_id'], 'day' => $planRow['day']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Automatické hnojení selhalo: ' . $e->getMessage());
            vg2_json(['success' => false, 'message' => 'Nepodařilo se uložit automatický plán hnojení: ' . $e->getMessage()]);
        }
        vg2_json(['success' => true, 'generated_count' => count($planRows), 'frequency' => $frequency, 'max_week' => $maxWeek, 'recipes' => $settings['recipes']]);
    }

    if ($action === 'fertilizer_get') {
        $wn = (int)($json['week'] ?? 0); $day = (int)($json['day'] ?? 0);
        if ($day < 1 || $day > 7) vg2_json(['success'=>false,'message'=>'Neplatný den.']);
        $weekId = $getWeekId($wn); if (!$weekId) vg2_json(['success'=>false,'message'=>'Týden nebyl nalezen.']);
        $st = $pdo->prepare("SELECT mode, items_json, total_ml, auto_generated FROM growreport_fertilizers2 WHERE week_id=:wid AND day_number=:dn LIMIT 1");
        $st->execute(['wid'=>$weekId, 'dn'=>$day]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) vg2_json(['success'=>true, 'fertilizer'=>null]);
        $decodedItems = json_decode((string)$row['items_json'], true);
        $summary = vg2_fertilizer_summary(is_array($decodedItems) ? $decodedItems : [], $knownDryFertilizerNames);
        vg2_json(['success'=>true, 'fertilizer'=>[
            'mode'=>$row['mode'], 'items'=>$summary['items'], 'auto_generated'=>(bool)($row['auto_generated'] ?? 0),
            'total_ml'=>$summary['total_ml'], 'total_scoops'=>$summary['total_scoops']
        ]]);
    }

    // Souhrn hnojiv je kumulativní pouze do vybraného týdne; budoucí týdny se nezapočítávají.
    if ($action === 'fertilizer_totals') {
        $maxWeek = array_key_exists('week', $json) ? (int)$json['week'] : null;
        $totalsSql = "SELECT f.items_json
            FROM growreport_fertilizers2 f
            INNER JOIN growreport_weeks2 w ON w.id=f.week_id
            WHERE w.report_id=:rid";
        $totalsParams = ['rid' => $growreport_id];
        if ($maxWeek !== null) {
            $totalsSql .= " AND w.week_number <= :max_week";
            $totalsParams['max_week'] = $maxWeek;
        }
        $totalsSql .= " ORDER BY w.week_number ASC, f.day_number ASC";
        $allFertilizersStmt = $pdo->prepare($totalsSql);
        $allFertilizersStmt->execute($totalsParams);
        $totalsByKey = [];
        foreach ($allFertilizersStmt->fetchAll(PDO::FETCH_COLUMN, 0) as $itemsJson) {
            $decoded = json_decode((string)$itemsJson, true);
            $summary = vg2_fertilizer_summary(is_array($decoded) ? $decoded : [], $knownDryFertilizerNames);
            foreach ($summary['items'] as $item) {
                $name = trim((string)($item['name'] ?? ''));
                $kind = (string)($item['kind'] ?? 'liquid');
                if ($name === '' || !in_array($kind, ['liquid', 'dry'], true)) continue;
                $key = $kind . '|' . $name;
                if (!isset($totalsByKey[$key])) {
                    $totalsByKey[$key] = ['name' => $name, 'kind' => $kind, 'total_ml' => 0, 'total_scoops' => 0, 'total_water_l' => 0, 'total_teaspoons' => 0];
                }
                if ($kind === 'dry') {
                    $totalsByKey[$key]['total_scoops'] += max(0, (int)($item['scoops'] ?? 0));
                } else {
                    if (isset($item['water_l'], $item['teaspoons']) && mb_strtolower($name, 'UTF-8') === mb_strtolower('Melasový čaj', 'UTF-8')) {
                        $totalsByKey[$key]['total_water_l'] += max(0, (float)$item['water_l']);
                        $totalsByKey[$key]['total_teaspoons'] += max(0, (int)$item['teaspoons']);
                    } else {
                        $totalsByKey[$key]['total_ml'] += max(0, (int)($item['ml'] ?? 0));
                    }
                }
            }
        }
        $totals = array_values($totalsByKey);
        usort($totals, function ($a, $b) {
            $kindOrder = ['liquid' => 0, 'dry' => 1];
            $cmp = ($kindOrder[$a['kind']] ?? 9) <=> ($kindOrder[$b['kind']] ?? 9);
            return $cmp !== 0 ? $cmp : strnatcasecmp((string)$a['name'], (string)$b['name']);
        });
        vg2_json(['success' => true, 'items' => $totals]);
    }

    if ($action === 'fertilizer_save') {
        $wn = (int)($json['week'] ?? 0);
        $day = (int)($json['day'] ?? 0);
        $mode = (string)($json['mode'] ?? 'preset');
        $items = $json['items'] ?? [];
        if ($day < 1 || $day > 7 || !in_array($mode, ['preset','custom'], true) || !is_array($items)) {
            vg2_json(['success'=>false,'message'=>'Neplatná data hnojiv.']);
        }
        $weekId = $getWeekId($wn);
        if (!$weekId) vg2_json(['success'=>false,'message'=>'Týden nebyl nalezen.']);

        $clean = [];
        $totalMl = 0;
        $totalScoops = 0;
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $name = trim(strip_tags((string)($it['name'] ?? '')));
            if ($name === '') vg2_json(['success'=>false,'message'=>'Každé hnojivo musí mít název.']);
            $rawKind = strtolower((string)($it['kind'] ?? ''));
            $kind = in_array($rawKind, ['liquid','dry'], true) ? $rawKind : (in_array($name, $knownDryFertilizerNames, true) ? 'dry' : 'liquid');
            if ($kind === 'dry') {
                $scoops = filter_var($it['scoops'] ?? null, FILTER_VALIDATE_INT);
                if ($scoops === false || $scoops < 1 || $scoops > 40) {
                    vg2_json(['success'=>false,'message'=>'Suché hnojivo musí mít 1 až 40 odměrek po 50 ml.']);
                }
                $clean[] = ['name'=>vg2_substr($name, 0, 80), 'kind'=>'dry', 'scoops'=>$scoops];
                $totalScoops += $scoops;
            } else {
                if (mb_strtolower($name, 'UTF-8') === mb_strtolower('Melasový čaj', 'UTF-8') && isset($it['water_l']) && is_numeric($it['water_l'])) {
                    $waterL = (float)$it['water_l'];
                    if (!is_finite($waterL) || $waterL < 0.5 || $waterL > 20 || abs($waterL * 2 - round($waterL * 2)) > 0.001) {
                        vg2_json(['success'=>false,'message'=>'Melasový čaj: voda musí být 0,5 až 20 litrů po půllitrech.']);
                    }
                    $waterL = round($waterL * 2) / 2;
                    $teaspoons = (int)round($waterL * 2);
                    $clean[] = ['name'=>vg2_substr($name, 0, 80), 'kind'=>'liquid', 'ml'=>5 * $teaspoons, 'water_l'=>$waterL, 'teaspoons'=>$teaspoons];
                    continue;
                }
                $ml = filter_var($it['ml'] ?? null, FILTER_VALIDATE_INT);
                if ($ml === false || $ml < 1 || $ml > 500) {
                    vg2_json(['success'=>false,'message'=>'Každé tekuté hnojivo musí mít 1 až 500 ml.']);
                }
                $clean[] = ['name'=>vg2_substr($name, 0, 80), 'kind'=>'liquid', 'ml'=>$ml];
                $totalMl += $ml;
            }
        }
        if (!$clean) {
            $pdo->prepare("DELETE FROM growreport_fertilizers2 WHERE week_id=:wid AND day_number=:dn")
                ->execute(['wid'=>$weekId, 'dn'=>$day]);
            $pdo->prepare("INSERT INTO growreport_days2 (week_id,day_number,hnojeno) VALUES (:wid,:dn,0) ON DUPLICATE KEY UPDATE hnojeno=0")
                ->execute(['wid'=>$weekId, 'dn'=>$day]);
            vg2_json(['success'=>true, 'total_ml'=>0, 'total_scoops'=>0, 'items'=>[]]);
        }
        if ($totalMl > 65535) vg2_json(['success'=>false,'message'=>'Součet ml je příliš vysoký.']);
        $st = $pdo->prepare("INSERT INTO growreport_fertilizers2 (week_id,day_number,mode,items_json,total_ml,auto_generated) VALUES (:wid,:dn,:mode,:items,:total,0) ON DUPLICATE KEY UPDATE mode=VALUES(mode),items_json=VALUES(items_json),total_ml=VALUES(total_ml),auto_generated=0");
        $st->execute(['wid'=>$weekId, 'dn'=>$day, 'mode'=>$mode, 'items'=>json_encode($clean, JSON_UNESCAPED_UNICODE), 'total'=>$totalMl]);
        $pdo->prepare("INSERT INTO growreport_days2 (week_id,day_number,hnojeno) VALUES (:wid,:dn,1) ON DUPLICATE KEY UPDATE hnojeno=1")
            ->execute(['wid'=>$weekId, 'dn'=>$day]);
        vg2_json(['success'=>true, 'total_ml'=>$totalMl, 'total_scoops'=>$totalScoops, 'items'=>$clean]);
    }

    // Vymazat všechna hnojiva pouze pro konkrétní týden a den.
    // Ostatní data dne (zejména zalévání, CO2, teplota a vlhkost) zůstávají beze změny.
    if ($action === 'fertilizer_clear') {
        $wn = (int)($json['week'] ?? 0);
        $day = (int)($json['day'] ?? 0);
        if ($day < 1 || $day > 7 || $wn < 0) {
            vg2_json(['success' => false, 'message' => 'Neplatný týden nebo den pro vymazání hnojiv.']);
        }
        $weekId = $getWeekId($wn);
        if (!$weekId) vg2_json(['success' => false, 'message' => 'Týden nebyl nalezen.']);

        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM growreport_fertilizers2 WHERE week_id=:wid AND day_number=:dn')
                ->execute(['wid' => $weekId, 'dn' => $day]);
            // Pouze vypne příznak hnojení; případný záznam o zalití zachová.
            $pdo->prepare('UPDATE growreport_days2 SET hnojeno=0 WHERE week_id=:wid AND day_number=:dn')
                ->execute(['wid' => $weekId, 'dn' => $day]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        vg2_json(['success' => true, 'total_ml' => 0, 'total_scoops' => 0, 'items' => []]);
    }

    // --- ULOŽIT CO2 (2 hodnoty PPM pro jeden den) ---
    if ($action === 'save_co2') {
        $wn = (int)($json['week'] ?? 0);
        $day = (int)($json['day'] ?? 0);
        if ($day < 1 || $day > 7) vg2_json(['success' => false, 'message' => 'Neplatný den.']);

        $weekId = $getWeekId($wn);
        if (!$weekId) vg2_json(['success' => false, 'message' => 'Týden nebyl nalezen.']);

        $label1 = mb_substr(trim((string)($json['label1'] ?? '')), 0, 20);
        $label2 = mb_substr(trim((string)($json['label2'] ?? '')), 0, 20);
        if ($label1 === '') $label1 = 'Ráno';
        if ($label2 === '') $label2 = 'Večer';

        $v1 = trim((string)($json['value1'] ?? ''));
        $v2 = trim((string)($json['value2'] ?? ''));
        if (($v1 !== '' && !preg_match('/^\d{1,4}$/', $v1)) || ($v2 !== '' && !preg_match('/^\d{1,4}$/', $v2))) {
            vg2_json(['success' => false, 'message' => 'Hodnota PPM musí být číslo s max. 4 číslicemi.']);
        }
        $v1 = $v1 === '' ? null : (int)$v1;
        $v2 = $v2 === '' ? null : (int)$v2;

        try {
            if ($v1 === null && $v2 === null && $label1 === 'Ráno' && $label2 === 'Večer') {
                $pdo->prepare("DELETE FROM growreport_co2_2 WHERE week_id=:wid AND day_number=:dn")
                    ->execute(['wid' => $weekId, 'dn' => $day]);
            } else {
                $st = $pdo->prepare("INSERT INTO growreport_co2_2 (week_id, day_number, label1, value1, label2, value2)
                                     VALUES (:wid, :dn, :l1, :v1, :l2, :v2)
                                     ON DUPLICATE KEY UPDATE label1=VALUES(label1), value1=VALUES(value1),
                                                             label2=VALUES(label2), value2=VALUES(value2)");
                $st->execute(['wid' => $weekId, 'dn' => $day, 'l1' => $label1, 'v1' => $v1, 'l2' => $label2, 'v2' => $v2]);
            }
        } catch (Throwable $e) {
            error_log("save_co2 chyba: " . $e->getMessage());
            vg2_json(['success' => false, 'message' => 'Chyba databáze při ukládání CO2: ' . $e->getMessage()]);
        }

        $co2Status = vg2_co2_status($v1, $v2);
        vg2_json(['success' => true, 'label1' => $label1, 'label2' => $label2, 'value1' => $v1, 'value2' => $v2,
                  'color' => $co2Status['color'], 'stripe' => $co2Status['stripe']]);
    }
if ($action === 'save_temperature') {
    $wn = (int)($json['week'] ?? 0);
    $day = (int)($json['day'] ?? 0);
    if ($day < 1 || $day > 7) vg2_json(['success' => false, 'message' => 'Neplatný den.']);

    $weekId = $getWeekId($wn);
    if (!$weekId) vg2_json(['success' => false, 'message' => 'Týden nebyl nalezen.']);

    $label1 = mb_substr(trim((string)($json['label1'] ?? '')), 0, 20);
    $label2 = mb_substr(trim((string)($json['label2'] ?? '')), 0, 20);
    if ($label1 === '') $label1 = 'Ráno';
    if ($label2 === '') $label2 = 'Večer';

    $v1raw = str_replace(',', '.', trim((string)($json['value1'] ?? '')));
    $v2raw = str_replace(',', '.', trim((string)($json['value2'] ?? '')));

    if (($v1raw !== '' && !preg_match('/^-?\d{1,2}(\.\d)?$/', $v1raw)) ||
        ($v2raw !== '' && !preg_match('/^-?\d{1,2}(\.\d)?$/', $v2raw))) {
        vg2_json(['success' => false, 'message' => 'Teplota musí být číslo (max 1 desetinné místo).']);
    }

    $v1 = $v1raw === '' ? null : (float)$v1raw;
    $v2 = $v2raw === '' ? null : (float)$v2raw;

    if (($v1 !== null && ($v1 < -30 || $v1 > 60)) || ($v2 !== null && ($v2 < -30 || $v2 > 60))) {
        vg2_json(['success' => false, 'message' => 'Teplota musí být v rozsahu -30 až 60 °C.']);
    }

    try {
        if ($v1 === null && $v2 === null && $label1 === 'Ráno' && $label2 === 'Večer') {
            $pdo->prepare("DELETE FROM growreport_temperature_2 WHERE week_id=:wid AND day_number=:dn")
                ->execute(['wid' => $weekId, 'dn' => $day]);
        } else {
            $st = $pdo->prepare("INSERT INTO growreport_temperature_2 (week_id, day_number, label1, value1, label2, value2)
                                 VALUES (:wid, :dn, :l1, :v1, :l2, :v2)
                                 ON DUPLICATE KEY UPDATE label1=VALUES(label1), value1=VALUES(value1),
                                                         label2=VALUES(label2), value2=VALUES(value2)");
            $st->execute(['wid' => $weekId, 'dn' => $day, 'l1' => $label1, 'v1' => $v1, 'l2' => $label2, 'v2' => $v2]);
        }
    } catch (Throwable $e) {
        error_log("save_temperature chyba: " . $e->getMessage());
        vg2_json(['success' => false, 'message' => 'Chyba databáze při ukládání teploty: ' . $e->getMessage()]);
    }

    vg2_json(['success' => true, 'label1' => $label1, 'label2' => $label2, 'value1' => $v1, 'value2' => $v2]);
}

// =========================
// 2) SAVE TEPLOTA ACTION
// (vložit do stejné části jako save_co2)
// =========================
if ($action === 'save_temperature') {
    $wn  = (int)($json['week'] ?? 0);
    $day = (int)($json['day'] ?? 0);
    if ($day < 1 || $day > 7) vg2_json(['success' => false, 'message' => 'Neplatný den.']);

    $weekId = $getWeekId($wn);
    if (!$weekId) vg2_json(['success' => false, 'message' => 'Týden nebyl nalezen.']);

    $label1 = mb_substr(trim((string)($json['label1'] ?? '')), 0, 20);
    $label2 = mb_substr(trim((string)($json['label2'] ?? '')), 0, 20);
    if ($label1 === '') $label1 = 'Ráno';
    if ($label2 === '') $label2 = 'Večer';

    $v1raw = str_replace(',', '.', trim((string)($json['value1'] ?? '')));
    $v2raw = str_replace(',', '.', trim((string)($json['value2'] ?? '')));

    if (($v1raw !== '' && !preg_match('/^-?\d{1,2}(\.\d)?$/', $v1raw)) ||
        ($v2raw !== '' && !preg_match('/^-?\d{1,2}(\.\d)?$/', $v2raw))) {
        vg2_json(['success' => false, 'message' => 'Teplota musí být číslo (max 1 desetinné místo).']);
    }

    $v1 = $v1raw === '' ? null : (float)$v1raw;
    $v2 = $v2raw === '' ? null : (float)$v2raw;

    if (($v1 !== null && ($v1 < -30 || $v1 > 60)) || ($v2 !== null && ($v2 < -30 || $v2 > 60))) {
        vg2_json(['success' => false, 'message' => 'Teplota musí být v rozsahu -30 až 60 °C.']);
    }

    try {
        if ($v1 === null && $v2 === null && $label1 === 'Ráno' && $label2 === 'Večer') {
            $pdo->prepare("DELETE FROM growreport_temperature_2 WHERE week_id=:wid AND day_number=:dn")
                ->execute(['wid' => $weekId, 'dn' => $day]);
        } else {
            $st = $pdo->prepare("INSERT INTO growreport_temperature_2 (week_id, day_number, label1, value1, label2, value2)
                                 VALUES (:wid, :dn, :l1, :v1, :l2, :v2)
                                 ON DUPLICATE KEY UPDATE label1=VALUES(label1), value1=VALUES(value1),
                                                         label2=VALUES(label2), value2=VALUES(value2)");
            $st->execute([
                'wid' => $weekId,
                'dn'  => $day,
                'l1'  => $label1,
                'v1'  => $v1,
                'l2'  => $label2,
                'v2'  => $v2
            ]);
        }
    } catch (Throwable $e) {
        error_log("save_temperature chyba: " . $e->getMessage());
        vg2_json(['success' => false, 'message' => 'Chyba databáze při ukládání teploty: ' . $e->getMessage()]);
    }

    vg2_json([
        'success' => true,
        'label1'  => $label1,
        'label2'  => $label2,
        'value1'  => $v1,
        'value2'  => $v2
    ]);
}


    // --- ULOŽIT VLHKOST PRO DEN ---
    if ($action === 'save_humidity') {
        $wn = (int)($json['week'] ?? 0);
        $day = (int)($json['day'] ?? 0);
        if ($day < 1 || $day > 7) vg2_json(['success' => false, 'message' => 'Neplatný den.']);

        $weekId = $getWeekId($wn);
        if (!$weekId) vg2_json(['success' => false, 'message' => 'Týden nebyl nalezen.']);

        $label1 = vg2_substr(trim((string)($json['label1'] ?? '')), 0, 20);
        $label2 = vg2_substr(trim((string)($json['label2'] ?? '')), 0, 20);
        if ($label1 === '') $label1 = 'Ráno';
        if ($label2 === '') $label2 = 'Večer';

        $v1raw = str_replace(',', '.', trim((string)($json['value1'] ?? '')));
        $v2raw = str_replace(',', '.', trim((string)($json['value2'] ?? '')));
        if (($v1raw !== '' && !preg_match('/^\d{1,3}(\.\d)?$/', $v1raw)) ||
            ($v2raw !== '' && !preg_match('/^\d{1,3}(\.\d)?$/', $v2raw))) {
            vg2_json(['success' => false, 'message' => 'Vlhkost musí být číslo od 0 do 100 (max. 1 desetinné místo).']);
        }

        $v1 = $v1raw === '' ? null : (float)$v1raw;
        $v2 = $v2raw === '' ? null : (float)$v2raw;
        if (($v1 !== null && ($v1 < 0 || $v1 > 100)) || ($v2 !== null && ($v2 < 0 || $v2 > 100))) {
            vg2_json(['success' => false, 'message' => 'Vlhkost musí být v rozsahu 0 až 100 %.']);
        }

        try {
            if ($v1 === null && $v2 === null && $label1 === 'Ráno' && $label2 === 'Večer') {
                $pdo->prepare("DELETE FROM growreport_humidity_2 WHERE week_id=:wid AND day_number=:dn")
                    ->execute(['wid' => $weekId, 'dn' => $day]);
            } else {
                $st = $pdo->prepare("INSERT INTO growreport_humidity_2 (week_id, day_number, label1, value1, label2, value2)
                                     VALUES (:wid, :dn, :l1, :v1, :l2, :v2)
                                     ON DUPLICATE KEY UPDATE label1=VALUES(label1), value1=VALUES(value1),
                                                             label2=VALUES(label2), value2=VALUES(value2)");
                $st->execute(['wid' => $weekId, 'dn' => $day, 'l1' => $label1, 'v1' => $v1, 'l2' => $label2, 'v2' => $v2]);
            }
        } catch (Throwable $e) {
            error_log("save_humidity chyba: " . $e->getMessage());
            vg2_json(['success' => false, 'message' => 'Chyba databáze při ukládání vlhkosti: ' . $e->getMessage()]);
        }
        vg2_json(['success' => true, 'label1' => $label1, 'label2' => $label2, 'value1' => $v1, 'value2' => $v2]);
    }

    // --- ULOŽIT NASTAVENÍ SVĚTEL PRO TÝDEN ---
    if ($action === 'save_lights') {
        $wn = (int)($json['week'] ?? 0);
        $day = (int)($json['day'] ?? 0);
        $lights = $json['lights'] ?? null;
        $allowedLights = ['HPS', 'MH', 'CMH/LEC', 'UVA/UVB', 'LED', 'CFL', 'T5', 'Plazmová výbojka'];
        if ($wn < 0 || $day < 1 || $day > 7 || !is_array($lights) || count($lights) < 1 || count($lights) > 3) {
            vg2_json(['success' => false, 'message' => 'Vyber den a alespoň jedno a nejvýše tři světla.']);
        }
        if ($lightsSchemaError) vg2_json(['success' => false, 'message' => 'Tabulka světel není připravena: ' . $lightsSchemaError]);
        $weekId = $getWeekId($wn);
        if (!$weekId) vg2_json(['success' => false, 'message' => 'Týden nebyl nalezen.']);

        $cleanLights = [];
        $seenLights = [];
        foreach ($lights as $light) {
            if (!is_array($light)) vg2_json(['success' => false, 'message' => 'Neplatná data světel.']);
            $name = trim((string)($light['name'] ?? ''));
            if (!in_array($name, $allowedLights, true) || isset($seenLights[$name])) {
                vg2_json(['success' => false, 'message' => 'Název světla není platný nebo je vybraný vícekrát.']);
            }
            $seenLights[$name] = true;
            $distance = filter_var($light['distance_cm'] ?? null, FILTER_VALIDATE_INT);
            if ($distance === false || $distance < 10 || $distance > 200) {
                vg2_json(['success' => false, 'message' => 'Vzdálenost světla musí být 10 až 200 cm.']);
            }
            if ($name === 'LED') {
                $minWatts = filter_var($light['min_watts'] ?? null, FILTER_VALIDATE_INT);
                $maxWatts = filter_var($light['max_watts'] ?? null, FILTER_VALIDATE_INT);
                $minHours = filter_var($light['min_hours'] ?? null, FILTER_VALIDATE_INT);
                $maxHours = filter_var($light['max_hours'] ?? null, FILTER_VALIDATE_INT);
                if ($minWatts === false || $minWatts < 10 || $minWatts > 2000 || $maxWatts === false || $maxWatts < 10 || $maxWatts > 2000) {
                    vg2_json(['success' => false, 'message' => 'U LED nastav minimální i maximální výkon v rozsahu 10 až 2000 W.']);
                }
                if ($maxWatts < $minWatts) vg2_json(['success' => false, 'message' => 'Maximální výkon LED nesmí být nižší než minimální výkon.']);
                if ($minHours === false || $minHours < 1 || $minHours > 24 || $maxHours === false || $maxHours < 1 || $maxHours > 24) {
                    vg2_json(['success' => false, 'message' => 'Čas chodu LED musí být 1 až 24 hodin pro minimum i maximum.']);
                }
                $cleanLights[] = ['name' => $name, 'distance_cm' => $distance, 'min_watts' => $minWatts, 'min_hours' => $minHours, 'max_watts' => $maxWatts, 'max_hours' => $maxHours];
            } else {
                $watts = filter_var($light['watts'] ?? null, FILTER_VALIDATE_INT);
                $hours = filter_var($light['hours'] ?? null, FILTER_VALIDATE_INT);
                if ($watts === false || $watts < 10 || $watts > 4000) {
                    vg2_json(['success' => false, 'message' => 'Výkon každého světla musí být 10 až 4000 W.']);
                }
                if ($hours === false || $hours < 1 || $hours > 24) {
                    vg2_json(['success' => false, 'message' => 'Čas chodu každého světla musí být 1 až 24 hodin.']);
                }
                $cleanLights[] = ['name' => $name, 'distance_cm' => $distance, 'watts' => $watts, 'hours' => $hours];
            }
        }

        $settingsJson = json_encode($cleanLights, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $st = $pdo->prepare("INSERT INTO growreport_lights2 (week_id, day_number, settings_json) VALUES (:wid, :dn, :settings) ON DUPLICATE KEY UPDATE settings_json=VALUES(settings_json)");
        $st->execute(['wid' => $weekId, 'dn' => $day, 'settings' => $settingsJson]);
        vg2_json(['success' => true, 'week' => $wn, 'day' => $day, 'lights' => $cleanLights]);
    }

    // --- ULOŽIT NASTAVENÍ VĚTRÁKŮ A ODTAHŮ OD KONKRÉTNÍHO DNE ---
    if ($action === 'save_airflow') {
        $wn = (int)($json['week'] ?? 0);
        $day = (int)($json['day'] ?? 0);
        $fans = $json['fans'] ?? null;
        $exhausts = $json['exhausts'] ?? null;
        if ($wn < 0 || $day < 1 || $day > 7 || !is_array($fans) || !is_array($exhausts) || count($fans) < 1 || count($fans) > 10 || count($exhausts) > 2) {
            vg2_json(['success' => false, 'message' => 'Zvol 1–10 větráků a 0–2 odtahy/přívody.']);
        }
        $weekId = $getWeekId($wn);
        if (!$weekId) vg2_json(['success' => false, 'message' => 'Týden nebyl nalezen.']);

        $cleanFans = [];
        foreach (array_values($fans) as $index => $fan) {
            if (!is_array($fan)) vg2_json(['success' => false, 'message' => 'Neplatná data větráku číslo ' . ($index + 1) . '.']);
            $mode = (string)($fan['mode'] ?? 'light');
            $watts = filter_var($fan['watts'] ?? null, FILTER_VALIDATE_INT);
            $hours = filter_var($fan['hours'] ?? null, FILTER_VALIDATE_INT);
            $speedScale = filter_var($fan['speed_scale'] ?? 3, FILTER_VALIDATE_INT);
            $speed = filter_var($fan['speed'] ?? ($fan['speed_scale'] ?? null ? 1 : 3), FILTER_VALIDATE_INT);
            if (!in_array($mode, ['light', 'dark'], true)) vg2_json(['success' => false, 'message' => 'U větráku vyber režim světlo nebo tma.']);
            if ($watts === false || $watts < 0 || $watts > 4000) vg2_json(['success' => false, 'message' => 'Příkon každého větráku musí být 0–4000 W.']);
            if ($hours === false || $hours < 1 || $hours > 24) vg2_json(['success' => false, 'message' => 'Čas větráku musí být 1–24 hodin.']);
            if (!in_array($speedScale, [3, 10], true) || $speed === false || $speed < 1 || $speed > $speedScale) vg2_json(['success' => false, 'message' => 'Rychlost větráku musí odpovídat stupnici 1–3 nebo 1–10.']);
            $usedWatts = round($watts * $speed / $speedScale, 2);
            $cleanFans[] = ['mode' => $mode, 'watts' => $watts, 'speed_scale' => $speedScale, 'speed' => $speed, 'used_watts' => $usedWatts, 'hours' => $hours];
        }

        $cleanExhausts = [];
        foreach (array_values($exhausts) as $index => $exhaust) {
            if (!is_array($exhaust)) vg2_json(['success' => false, 'message' => 'Neplatná data odtahu/přívodu číslo ' . ($index + 1) . '.']);
            $name = (string)($exhaust['name'] ?? 'Odtah');
            $watts = filter_var($exhaust['watts'] ?? null, FILTER_VALIDATE_INT);
            $hours = filter_var($exhaust['hours'] ?? null, FILTER_VALIDATE_INT);
            $speed = filter_var($exhaust['speed'] ?? null, FILTER_VALIDATE_INT);
            if (!in_array($name, ['Odtah', 'Přívod'], true)) vg2_json(['success' => false, 'message' => 'Název musí být Odtah nebo Přívod.']);
            if ($watts === false || $watts < 10 || $watts > 4000) vg2_json(['success' => false, 'message' => 'Příkon odtahu/přívodu musí být 10–4000 W.']);
            if ($hours === false || $hours < 1 || $hours > 24) vg2_json(['success' => false, 'message' => 'Čas odtahu/přívodu musí být 1–24 hodin.']);
            if ($speed === false || $speed < 1 || $speed > 10) vg2_json(['success' => false, 'message' => 'Rychlost odtahu/přívodu musí být 1–10.']);
            $cleanExhausts[] = ['name' => $name, 'watts' => $watts, 'hours' => $hours, 'speed' => $speed];
        }

        $settingsJson = json_encode(['fans' => $cleanFans, 'exhausts' => $cleanExhausts], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $st = $pdo->prepare("INSERT INTO growreport_airflow2 (week_id, day_number, settings_json) VALUES (:wid, :dn, :settings) ON DUPLICATE KEY UPDATE settings_json=VALUES(settings_json)");
        $st->execute(['wid' => $weekId, 'dn' => $day, 'settings' => $settingsJson]);
        vg2_json(['success' => true, 'week' => $wn, 'day' => $day, 'fans' => $cleanFans, 'exhausts' => $cleanExhausts]);
    }

    // --- ULOŽIT NASTAVENÍ KVĚTINÁČŮ ---
    if ($action === 'save_pots') {
        $pots = $json['pots'] ?? [];

        try {
            $pdo->prepare("DELETE FROM growreport_pots2 WHERE report_id=:rid")->execute(['rid' => $growreport_id]);
            $pdo->prepare("DELETE FROM growreport_pot_names2 WHERE report_id=:rid")->execute(['rid' => $growreport_id]);

            $ins = $pdo->prepare("INSERT INTO growreport_pots2 (report_id, pot_number, volume) VALUES (:rid, :pn, :vol)");
            $nameIns = $pdo->prepare("INSERT INTO growreport_pot_names2 (report_id, pot_number, pot_name) VALUES (:rid, :pn, :name)");
            foreach ($pots as $pot) {
                $pn = (int)($pot['pot_number'] ?? 0);
                $vol = round(floatval($pot['volume'] ?? 0) * 2) / 2;
                $potName = trim(strip_tags((string)($pot['pot_name'] ?? '')));
                if (function_exists('mb_substr')) $potName = mb_substr($potName, 0, 100, 'UTF-8');
                else $potName = substr($potName, 0, 100);
                if ($pn >= 1 && $pn <= 9 && $vol >= 0.5 && $vol <= 100) {
                    $ins->execute(['rid' => $growreport_id, 'pn' => $pn, 'vol' => $vol]);
                    if ($potName !== '') {
                        $nameIns->execute(['rid' => $growreport_id, 'pn' => $pn, 'name' => $potName]);
                    }
                }
            }
        } catch (Throwable $e) {
            error_log("save_pots chyba: " . $e->getMessage());
            vg2_json(['success' => false, 'message' => 'Chyba: ' . $e->getMessage()]);
        }

        vg2_json(['success' => true]);
    }

    // --- VYGENEROVAT A ULOŽIT AUTOMATICKÝ PLÁN ZALÉVÁNÍ DO BĚŽNÉ TABULKY ---
    // Automatický rozpis přijímáme také přes existující akci save_watering.
    // Tím se vyhneme samostatné API trase, kterou může serverová ochrana blokovat.
    if ($action === 'auto_watering_apply' || ($action === 'save_watering' && isset($json['schedule']))) {
        if ($wateringAutoSchemaError) {
            vg2_json(['success' => false, 'message' => 'Databáze není připravena pro automatické zalévání: ' . $wateringAutoSchemaError]);
        }
        $schedule = $json['schedule'] ?? null;
        if (!is_array($schedule) || count($schedule) < 1 || count($schedule) > 1000) {
            vg2_json(['success' => false, 'message' => 'Plán zalévání je prázdný nebo obsahuje příliš mnoho záznamů.']);
        }

        $weekStmt = $pdo->prepare('SELECT id, week_number FROM growreport_weeks2 WHERE report_id=:rid AND week_number>=1 ORDER BY week_number ASC');
        $weekStmt->execute(['rid' => $growreport_id]);
        $weekRows = $weekStmt->fetchAll(PDO::FETCH_ASSOC);
        $weeksByNumber = [];
        foreach ($weekRows as $weekRow) $weeksByNumber[(int)$weekRow['week_number']] = (int)$weekRow['id'];
        if (!$weeksByNumber) vg2_json(['success' => false, 'message' => 'Growreport zatím nemá žádné týdny od týdne 1.']);

        $potStmt = $pdo->prepare('SELECT pot_number, volume FROM growreport_pots2 WHERE report_id=:rid ORDER BY pot_number ASC');
        $potStmt->execute(['rid' => $growreport_id]);
        $potsByNumber = [];
        foreach ($potStmt->fetchAll(PDO::FETCH_ASSOC) as $potRow) {
            $pn = (int)$potRow['pot_number'];
            $volume = (float)$potRow['volume'];
            if ($pn >= 1 && $pn <= 9 && $volume >= 0.5 && $volume <= 100) $potsByNumber[$pn] = $volume;
        }
        if (!$potsByNumber) vg2_json(['success' => false, 'message' => 'Nejdříve nastav alespoň jeden květináč a jeho objem.']);

        $cleanSchedule = [];
        $seenDays = [];
        foreach ($schedule as $entry) {
            if (!is_array($entry)) vg2_json(['success' => false, 'message' => 'Plán obsahuje neplatný záznam.']);
            $wn = (int)($entry['week'] ?? 0);
            $day = (int)($entry['day'] ?? 0);
            $amounts = $entry['amounts'] ?? null;
            if ($wn < 1 || !isset($weeksByNumber[$wn]) || $day < 1 || $day > 7 || !is_array($amounts)) {
                vg2_json(['success' => false, 'message' => 'Plán obsahuje neplatný týden nebo den.']);
            }
            $dayKey = $wn . ':' . $day;
            if (isset($seenDays[$dayKey])) vg2_json(['success' => false, 'message' => 'Plán obsahuje stejný den vícekrát.']);
            $seenDays[$dayKey] = true;
            $cleanAmounts = [];
            foreach ($amounts as $amountRow) {
                if (!is_array($amountRow)) vg2_json(['success' => false, 'message' => 'Plán obsahuje neplatnou dávku.']);
                $pn = (int)($amountRow['pot_number'] ?? 0);
                $rawAmount = str_replace(',', '.', trim((string)($amountRow['amount'] ?? '')));
                if (!isset($potsByNumber[$pn]) || $rawAmount === '' || !is_numeric($rawAmount)) {
                    vg2_json(['success' => false, 'message' => 'Plán obsahuje neplatný květináč nebo množství vody.']);
                }
                $amount = round((float)$rawAmount * 100) / 100;
                if ($amount < 0 || $amount > $potsByNumber[$pn] + 0.0001) {
                    vg2_json(['success' => false, 'message' => 'Dávka pro květináč ' . $pn . ' musí být od 0 do objemu květináče (' . $potsByNumber[$pn] . ' L).']);
                }
                if ($amount > 0) $cleanAmounts[$pn] = $amount;
            }
            if (!$cleanAmounts) vg2_json(['success' => false, 'message' => 'Každý naplánovaný den musí obsahovat alespoň jednu kladnou dávku vody.']);
            $cleanSchedule[] = ['week' => $wn, 'day' => $day, 'week_id' => $weeksByNumber[$wn], 'amounts' => $cleanAmounts];
        }

        try {
            $pdo->beginTransaction();
            // Starší automatický rozpis se odstraní. Ruční zálivky mimo nové termíny zůstávají.
            $pdo->prepare('DELETE wa FROM growreport_watering2 wa INNER JOIN growreport_weeks2 w ON w.id=wa.week_id WHERE w.report_id=:rid AND w.week_number>=1 AND wa.auto_generated=1')
                ->execute(['rid' => $growreport_id]);
            $deleteScheduledDay = $pdo->prepare('DELETE FROM growreport_watering2 WHERE week_id=:wid AND day_number=:day');
            $insertWater = $pdo->prepare('INSERT INTO growreport_watering2 (week_id,day_number,pot_number,amount,auto_generated) VALUES (:wid,:day,:pot,:amount,1)');
            $recordCount = 0;
            foreach ($cleanSchedule as $entry) {
                // Záznamy na dnech nového plánu se nahradí plánovanými hodnotami.
                $deleteScheduledDay->execute(['wid' => $entry['week_id'], 'day' => $entry['day']]);
                foreach ($entry['amounts'] as $pn => $amount) {
                    $insertWater->execute(['wid' => $entry['week_id'], 'day' => $entry['day'], 'pot' => (int)$pn, 'amount' => $amount]);
                    $recordCount++;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('auto_watering_apply chyba: ' . $e->getMessage());
            vg2_json(['success' => false, 'message' => 'Automatický plán zalévání se nepodařilo uložit: ' . $e->getMessage()]);
        }

        vg2_json(['success' => true, 'generated_days' => count($cleanSchedule), 'generated_values' => $recordCount]);
    }

    // --- ULOŽIT ZALÉVÁNÍ PRO DEN ---
    if ($action === 'save_watering') {
        $wn = (int)($json['week'] ?? 0);
        $day = (int)($json['day'] ?? 0);
        $amounts = $json['amounts'] ?? [];
        $phRaw = trim((string)($json['ph'] ?? ''));
        $phValue = null;
        if ($phRaw !== '') {
            $phValue = filter_var(str_replace(',', '.', $phRaw), FILTER_VALIDATE_FLOAT);
            if ($phValue === false || $phValue < 1 || $phValue > 10 || abs($phValue * 2 - round($phValue * 2)) > 0.0001) {
                vg2_json(['success' => false, 'message' => 'pH vody musí být v rozsahu 1 až 10 po půl bodu.']);
            }
        }
        $runoffPhRaw = trim((string)($json['runoff_ph'] ?? ''));
        $runoffPhValue = null;
        if ($runoffPhRaw !== '') {
            $runoffPhValue = filter_var(str_replace(',', '.', $runoffPhRaw), FILTER_VALIDATE_FLOAT);
            if ($runoffPhValue === false || $runoffPhValue < 1 || $runoffPhValue > 10 || abs($runoffPhValue * 2 - round($runoffPhValue * 2)) > 0.0001) {
                vg2_json(['success' => false, 'message' => 'pH výtokové vody musí být v rozsahu 1 až 10 po půl bodu.']);
            }
        }
        $runoffLitersRaw = trim((string)($json['runoff_liters'] ?? ''));
        $runoffLitersValue = null;
        if ($runoffLitersRaw !== '') {
            $runoffLitersValue = filter_var(str_replace(',', '.', $runoffLitersRaw), FILTER_VALIDATE_FLOAT);
            $validRunoffSteps = abs($runoffLitersValue * 10 - round($runoffLitersValue * 10)) < 0.0001 &&
                ($runoffLitersValue <= 1.0 || abs(($runoffLitersValue - 1.0) * 2 - round(($runoffLitersValue - 1.0) * 2)) < 0.0001);
            if ($runoffLitersValue === false || $runoffLitersValue < 0.1 || $runoffLitersValue > 10 || !$validRunoffSteps) {
                vg2_json(['success' => false, 'message' => 'Objem výtoku musí být 0,1 až 10 litrů; do 1 litru po 0,1 l a nad 1 litr po 0,5 l.']);
            }
        }

        if ($phValue !== null && $wateringPhSchemaError) {
            vg2_json(['success' => false, 'message' => 'Nelze ukládat pH vody: ' . $wateringPhSchemaError]);
        }
        if ($runoffPhValue !== null && $wateringRunoffPhSchemaError) {
            vg2_json(['success' => false, 'message' => 'Nelze ukládat pH výtokové vody: ' . $wateringRunoffPhSchemaError]);
        }

        if ($day < 1 || $day > 7) vg2_json(['success' => false, 'message' => 'Neplatný den.']);

        $weekId = $getWeekId($wn);
        if (!$weekId) vg2_json(['success' => false, 'message' => 'Týden nebyl nalezen.']);

        try {
            $pdo->prepare("DELETE FROM growreport_watering2 WHERE week_id=:wid AND day_number=:dn")
                ->execute(['wid' => $weekId, 'dn' => $day]);

            $total = 0;
            $ins = $pdo->prepare("INSERT INTO growreport_watering2 (week_id, day_number, pot_number, amount, auto_generated) VALUES (:wid, :dn, :pn, :amt, 0)");
            foreach ($amounts as $a) {
                $pn = (int)($a['pot_number'] ?? 0);
                $amt = round(floatval($a['amount'] ?? 0) * 2) / 2;
                if ($pn >= 1 && $pn <= 9 && $amt > 0) {
                    $ins->execute(['wid' => $weekId, 'dn' => $day, 'pn' => $pn, 'amt' => $amt]);
                    $total += $amt;
                }
            }

            if (!$wateringPhSchemaError) {
                if ($phValue !== null) {
                    $pdo->prepare('INSERT INTO growreport_watering_ph2 (week_id, day_number, ph_value) VALUES (:wid, :dn, :ph) ON DUPLICATE KEY UPDATE ph_value=VALUES(ph_value)')
                        ->execute(['wid' => $weekId, 'dn' => $day, 'ph' => $phValue]);
                } else {
                    $pdo->prepare('DELETE FROM growreport_watering_ph2 WHERE week_id=:wid AND day_number=:dn')
                        ->execute(['wid' => $weekId, 'dn' => $day]);
                }
            }

            if (!$wateringRunoffPhSchemaError) {
                if ($runoffPhValue !== null || $runoffLitersValue !== null) {
                    $pdo->prepare('INSERT INTO growreport_watering_runoff_ph2 (week_id, day_number, ph_value, runoff_liters) VALUES (:wid, :dn, :ph, :liters) ON DUPLICATE KEY UPDATE ph_value=VALUES(ph_value), runoff_liters=VALUES(runoff_liters)')
                        ->execute(['wid' => $weekId, 'dn' => $day, 'ph' => $runoffPhValue ?? 7.0, 'liters' => $runoffLitersValue]);
                    if ($runoffPhValue === null) {
                        $pdo->prepare('UPDATE growreport_watering_runoff_ph2 SET ph_value=NULL WHERE week_id=:wid AND day_number=:dn')->execute(['wid'=>$weekId,'dn'=>$day]);
                    }
                } else {
                    $pdo->prepare('DELETE FROM growreport_watering_runoff_ph2 WHERE week_id=:wid AND day_number=:dn')
                        ->execute(['wid' => $weekId, 'dn' => $day]);
                }
            }
        } catch (Throwable $e) {
            error_log("save_watering chyba: " . $e->getMessage());
            vg2_json(['success' => false, 'message' => 'Chyba při ukládání zalévání/pH: ' . $e->getMessage()]);
        }

        vg2_json(['success' => true, 'total' => $total, 'ph' => $phValue, 'runoff_ph' => $runoffPhValue, 'runoff_liters' => $runoffLitersValue]);
    }

    if ($action === 'share') {
        $st = $pdo->prepare("UPDATE growreports2 SET share_enabled=1, share_date=NOW() WHERE id=:id AND user_id=:uid");
        $st->execute(['id' => $growreport_id, 'uid' => $_SESSION['user_id']]);
        vg2_json(['success' => true]);
    }

    if ($action === 'delete') {
        $st = $pdo->prepare("DELETE FROM growreports2 WHERE id=:id AND user_id=:uid");
        $st->execute(['id' => $growreport_id, 'uid' => $_SESSION['user_id']]);
        vg2_json(['success' => true]);
    }

    vg2_json(['success' => false, 'message' => 'Neznámá akce.']);
    } catch (Throwable $e) {
        error_log('Growreport AJAX chyba [' . $action . ']: ' . $e->getMessage());
        http_response_code(500);
        vg2_json(['success' => false, 'message' => 'Chyba serveru při akci ' . $action . ': ' . $e->getMessage()]);
    }
}

// ========== NAČTENÍ DAT ==========
$stmt = $pdo->prepare("SELECT * FROM growreport_weeks2 WHERE report_id = :id ORDER BY week_number ASC");
$stmt->execute(['id' => $growreport_id]);
$weeks = $stmt->fetchAll(PDO::FETCH_ASSOC);

$hasWeekZero = false;
foreach ($weeks as $weekRowForZeroCheck) {
    if ((int)($weekRowForZeroCheck['week_number'] ?? -1) === 0) {
        $hasWeekZero = true;
        break;
    }
}

$weekdayOptions = ['pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota', 'neděle'];
$calendarFirstWeekday = '';
$calendarStartDate = '';
try {
    $calendarStmt = $pdo->prepare('SELECT first_weekday, start_date FROM growreport_calendar_settings2 WHERE report_id=:rid LIMIT 1');
    $calendarStmt->execute(['rid' => $growreport_id]);
    $calendarSettingsRow = $calendarStmt->fetch(PDO::FETCH_ASSOC);
    if ($calendarSettingsRow) {
        $savedWeekday = mb_strtolower(trim((string)($calendarSettingsRow['first_weekday'] ?? '')), 'UTF-8');
        if (in_array($savedWeekday, $weekdayOptions, true)) $calendarFirstWeekday = $savedWeekday;
        $calendarStartDate = !empty($calendarSettingsRow['start_date']) ? (string)$calendarSettingsRow['start_date'] : '';
    }
} catch (Throwable $e) {
    error_log('Načtení nastavení kalendáře growreportu: ' . $e->getMessage());
}
if ($calendarFirstWeekday === '') {
    foreach ($weeks as $existingWeek) {
        $candidate = mb_strtolower(trim((string)($existingWeek['start_day'] ?? '')), 'UTF-8');
        if (in_array($candidate, $weekdayOptions, true)) { $calendarFirstWeekday = $candidate; break; }
    }
}
$fullMoonRows = [];
$fullMoonDates = [];
try {
    $fullMoonRows = $pdo->query('SELECT id, moon_date FROM growreport_full_moons2 ORDER BY moon_date ASC')->fetchAll(PDO::FETCH_ASSOC);
    $fullMoonDates = array_values(array_map(function ($r) { return (string)$r['moon_date']; }, $fullMoonRows));
} catch (Throwable $e) {
    error_log('Načtení kalendáře úplňků: ' . $e->getMessage());
}

$pruningRows = [];
try {
    $pruningStmt = $pdo->prepare('SELECT id, week_number, day_number, record_date, action_type, completed, created_at FROM growreport_pruning_actions2 WHERE report_id=:rid ORDER BY record_date DESC, id DESC');
    $pruningStmt->execute(['rid' => $growreport_id]);
    $pruningRows = $pruningStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Načtení záznamů zastřižení/FIM/LST: ' . $e->getMessage());
}

$customFunctionMarks = [];
$customFunctionSettings = [];
try {
    $customCfgStmt = $pdo->prepare('SELECT slot_number, function_name, color FROM growreport_custom_functions2 WHERE report_id=:rid ORDER BY slot_number');
    $customCfgStmt->execute(['rid' => $growreport_id]);
    $customFunctionSettings = $customCfgStmt->fetchAll(PDO::FETCH_ASSOC);
    $customMarksStmt = $pdo->prepare('SELECT id, slot_number, week_number, day_number, record_date, completed, created_at FROM growreport_custom_function_marks2 WHERE report_id=:rid ORDER BY record_date DESC, id DESC');
    $customMarksStmt->execute(['rid' => $growreport_id]);
    $customFunctionMarks = $customMarksStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Načtení vlastních funkcí T: ' . $e->getMessage());
}

$electricitySettings = ['price_per_kwh'=>0.0,'extra_device_watts'=>0.0,'day_price_per_kwh'=>0.0,'night_price_per_kwh'=>0.0,'day_hours'=>16.0,'night_hours'=>8.0];
try {
    $electricityStmt = $pdo->prepare('SELECT price_per_kwh, extra_device_watts, day_price_per_kwh, night_price_per_kwh, day_hours, night_hours FROM growreport_electricity2 WHERE report_id=:rid LIMIT 1');
    $electricityStmt->execute(['rid'=>$growreport_id]);
    $electricityRow = $electricityStmt->fetch(PDO::FETCH_ASSOC);
    if ($electricityRow) $electricitySettings = ['price_per_kwh'=>(float)$electricityRow['price_per_kwh'],'extra_device_watts'=>(float)$electricityRow['extra_device_watts'],'day_price_per_kwh'=>(float)$electricityRow['day_price_per_kwh'],'night_price_per_kwh'=>(float)$electricityRow['night_price_per_kwh'],'day_hours'=>(float)$electricityRow['day_hours'],'night_hours'=>(float)$electricityRow['night_hours']];
} catch (Throwable $e) { error_log('Načtení elektřiny E: '.$e->getMessage()); }

$electricityPriceHistory = [];
// Při prvním nasazení zachovej dosavadní cenu jako výchozí historii od začátku Growreportu.
try {
    $historyCountStmt=$pdo->prepare('SELECT COUNT(*) FROM growreport_electricity_price_history2 WHERE report_id=:rid');
    $historyCountStmt->execute(['rid'=>$growreport_id]);
    if ((int)$historyCountStmt->fetchColumn()===0 && (float)$electricitySettings['price_per_kwh']>0) {
        $legacyDate = ($calendarStartDate && preg_match('/^\d{4}-\d{2}-\d{2}$/',$calendarStartDate)) ? $calendarStartDate : date('Y-m-d');
        $seed=$pdo->prepare('INSERT IGNORE INTO growreport_electricity_price_history2 (report_id,effective_date,price_per_kwh,day_price_per_kwh,night_price_per_kwh,day_hours,night_hours) VALUES (:rid,:date,:price,:day,:night,:dh,:nh)');
        $seed->execute(['rid'=>$growreport_id,'date'=>$legacyDate,'price'=>$electricitySettings['price_per_kwh'],'day'=>$electricitySettings['day_price_per_kwh'],'night'=>$electricitySettings['night_price_per_kwh'],'dh'=>$electricitySettings['day_hours'],'nh'=>$electricitySettings['night_hours']]);
    }
    $priceHistoryStmt=$pdo->prepare('SELECT effective_date,price_per_kwh,day_price_per_kwh,night_price_per_kwh,day_hours,night_hours FROM growreport_electricity_price_history2 WHERE report_id=:rid ORDER BY effective_date ASC'); $priceHistoryStmt->execute(['rid'=>$growreport_id]); $electricityPriceHistory=$priceHistoryStmt->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { error_log('Načtení historie cen E: '.$e->getMessage()); }

$vzRows = [];
try {
    $vzStmt = $pdo->prepare('SELECT id, week_number, day_number, record_date, record_type, runtime_hours, anchor_future, auto_propagated, source_anchor_date, completed, created_at, updated_at FROM growreport_vz_records2 WHERE report_id=:rid ORDER BY record_date DESC, id DESC');
    $vzStmt->execute(['rid' => $growreport_id]);
    $vzRows = $vzStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Načtení záznamů V/Z: ' . $e->getMessage());
}

// Načtení nastavení květináčů a jejich volitelných vlastních názvů.
$potsConfig = [];
$potNamesByNumber = [];
try {
    $ps = $pdo->prepare("SELECT pot_number, volume FROM growreport_pots2 WHERE report_id=:rid ORDER BY pot_number ASC");
    $ps->execute(['rid' => $growreport_id]);
    $potsConfig = $ps->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("načtení květináčů: " . $e->getMessage());
}
try {
    $potNameStmt = $pdo->prepare("SELECT pot_number, pot_name FROM growreport_pot_names2 WHERE report_id=:rid");
    $potNameStmt->execute(['rid' => $growreport_id]);
    foreach ($potNameStmt->fetchAll(PDO::FETCH_ASSOC) as $potNameRow) {
        $potName = trim((string)($potNameRow['pot_name'] ?? ''));
        if ($potName !== '') {
            $potNumber = (string)(int)$potNameRow['pot_number'];
            $potNamesByNumber[$potNumber] = $potName;
        }
    }
} catch (Throwable $e) {
    error_log('Načtení vlastních názvů květináčů: ' . $e->getMessage());
}
foreach ($potsConfig as &$potConfigRow) {
    $potConfigNumber = (string)(int)$potConfigRow['pot_number'];
    $potConfigRow['pot_name'] = $potNamesByNumber[$potConfigNumber] ?? '';
}
unset($potConfigRow);

// Celkové množství vody pro každý květináč za celou historii tohoto growreportu.
$potWateringLifetimeTotals = [];
try {
    $potTotalsStmt = $pdo->prepare("SELECT wa.pot_number, COALESCE(SUM(wa.amount), 0) AS total_l
        FROM growreport_watering2 wa
        INNER JOIN growreport_weeks2 gw ON gw.id = wa.week_id
        WHERE gw.report_id = :rid
        GROUP BY wa.pot_number");
    $potTotalsStmt->execute(['rid' => $growreport_id]);
    foreach ($potTotalsStmt->fetchAll(PDO::FETCH_ASSOC) as $potTotalRow) {
        $potWateringLifetimeTotals[(string)(int)$potTotalRow['pot_number']] = round((float)$potTotalRow['total_l'], 2);
    }
} catch (Throwable $e) {
    error_log('Výpočet celkového zalití květináčů: ' . $e->getMessage());
}

// Načtení změn nastavení světel po dnech. Každá změna platí od daného dne dál,
// stejně jako u větráků/odtahů; dřívější dny se nepřepisují.
$lightOverrides = [];
$lightsLoadError = $lightsSchemaError;
try {
    if ($lightsSchemaError) throw new RuntimeException($lightsSchemaError);
    $ls = $pdo->prepare("SELECT w.week_number, l.day_number, l.settings_json FROM growreport_lights2 l JOIN growreport_weeks2 w ON w.id=l.week_id WHERE w.report_id=:rid ORDER BY w.week_number ASC, l.day_number ASC");
    $ls->execute(['rid' => $growreport_id]);
    foreach ($ls->fetchAll(PDO::FETCH_ASSOC) as $lightRow) {
        $decoded = json_decode((string)$lightRow['settings_json'], true);
        if (is_array($decoded)) $lightOverrides[(int)$lightRow['week_number']][(int)$lightRow['day_number']] = array_values($decoded);
    }
} catch (Throwable $e) {
    $lightsLoadError = $e->getMessage();
    error_log("načtení světel: " . $e->getMessage());
}
$activeLights = [];

// Nastavení větráků/odtahů se načítá jako seznam změn pro konkrétní den.
// Každá změna platí od vybraného dne dopředu; starší dny se nepřepisují.
$airflowOverrides = [];
$airflowLoadError = null;
try {
    $as = $pdo->prepare("SELECT w.week_number, a.day_number, a.settings_json FROM growreport_airflow2 a JOIN growreport_weeks2 w ON w.id=a.week_id WHERE w.report_id=:rid ORDER BY w.week_number ASC, a.day_number ASC");
    $as->execute(['rid' => $growreport_id]);
    foreach ($as->fetchAll(PDO::FETCH_ASSOC) as $airflowRow) {
        $decoded = json_decode((string)$airflowRow['settings_json'], true);
        if (is_array($decoded)) {
            $airflowOverrides[(int)$airflowRow['week_number']][(int)$airflowRow['day_number']] = [
                'fans' => (isset($decoded['fans']) && is_array($decoded['fans'])) ? array_values($decoded['fans']) : [],
                'exhausts' => (isset($decoded['exhausts']) && is_array($decoded['exhausts'])) ? array_values($decoded['exhausts']) : []
            ];
        }
    }
} catch (Throwable $e) {
    $airflowLoadError = $e->getMessage();
    error_log('Načtení větráků/odtahů: ' . $e->getMessage());
}
$activeAirflow = ['fans' => [], 'exhausts' => []];

foreach ($weeks as &$w) {
    $currentWeekNumber = (int)$w['week_number'];
    $w['photo4'] = null;
    try {
        $photo4Stmt = $pdo->prepare('SELECT file_path FROM growreport_section3_photo2 WHERE week_id=:wid LIMIT 1');
        $photo4Stmt->execute(['wid' => $w['id']]);
        $w['photo4'] = $photo4Stmt->fetchColumn() ?: null;
    } catch (Throwable $e) { error_log('Načtení druhé fotografie sekce 3: ' . $e->getMessage()); }
    $w['lightsByDay'] = [];
    for ($lightsDay = 1; $lightsDay <= 7; $lightsDay++) {
        if (isset($lightOverrides[$currentWeekNumber][$lightsDay])) {
            $activeLights = $lightOverrides[$currentWeekNumber][$lightsDay];
        }
        $w['lightsByDay'][$lightsDay] = $activeLights;
    }
    // Zpětná kompatibilita pro starší části stránky; vykreslování nyní používá lightsByDay.
    $w['lights'] = $activeLights;
    $w['airflowByDay'] = [];
    for ($airflowDay = 1; $airflowDay <= 7; $airflowDay++) {
        if (isset($airflowOverrides[$currentWeekNumber][$airflowDay])) {
            $activeAirflow = $airflowOverrides[$currentWeekNumber][$airflowDay];
        }
        $w['airflowByDay'][$airflowDay] = $activeAirflow;
    }
    $ds = $pdo->prepare("SELECT day_number, zalito, hnojeno FROM growreport_days2 WHERE week_id = :wid");
    $ds->execute(['wid' => $w['id']]);
    $w['hnojenoDays'] = [];
    $w['fertilizers'] = [];
    foreach ($ds->fetchAll(PDO::FETCH_ASSOC) as $d) {
        if ($d['hnojeno']) $w['hnojenoDays'][] = (int)$d['day_number'];
    }
    try {
        $fs = $pdo->prepare("SELECT day_number, mode, items_json, total_ml FROM growreport_fertilizers2 WHERE week_id=:wid");
        $fs->execute(['wid'=>$w['id']]);
        foreach ($fs->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $rawItems = json_decode((string)$f['items_json'], true);
            $summary = vg2_fertilizer_summary(is_array($rawItems) ? $rawItems : [], $knownDryFertilizerNames);
            $dayNo = (int)$f['day_number'];
            $w['fertilizers'][$dayNo] = [
                'mode'=>$f['mode'], 'items'=>$summary['items'],
                'total_ml'=>$summary['total_ml'], 'total_scoops'=>$summary['total_scoops'],
                'melasa_water_l'=>array_sum(array_map(static function($it) { return (isset($it['water_l']) && mb_strtolower((string)($it['name'] ?? ''), 'UTF-8') === mb_strtolower('Melasový čaj', 'UTF-8')) ? (float)$it['water_l'] : 0; }, $summary['items'])),
                'melasa_teaspoons'=>array_sum(array_map(static function($it) { return (isset($it['teaspoons']) && mb_strtolower((string)($it['name'] ?? ''), 'UTF-8') === mb_strtolower('Melasový čaj', 'UTF-8')) ? (int)$it['teaspoons'] : 0; }, $summary['items']))
            ];
            if (($summary['total_ml'] > 0 || $summary['total_scoops'] > 0 || (float)($w['fertilizers'][$dayNo]['melasa_water_l'] ?? 0) > 0) && !in_array($dayNo, $w['hnojenoDays'], true)) {
                $w['hnojenoDays'][] = $dayNo;
            }
        }
    } catch (Throwable $e) { error_log('Načtení hnojiv: '.$e->getMessage()); }

    $w['co2'] = [];
    try {
        $cs = $pdo->prepare("SELECT day_number, label1, value1, label2, value2 FROM growreport_co2_2 WHERE week_id = :wid");
        $cs->execute(['wid' => $w['id']]);
        foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $w['co2'][(int)$c['day_number']] = $c;
        }
    } catch (Throwable $e) {
        error_log("načtení CO2: " . $e->getMessage());
    }

$w['temperature'] = [];
try {
    $ts = $pdo->prepare("SELECT day_number, label1, value1, label2, value2 FROM growreport_temperature_2 WHERE week_id = :wid");
    $ts->execute(['wid' => $w['id']]);
    foreach ($ts->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $w['temperature'][(int)$t['day_number']] = $t;
    }
} catch (Throwable $e) {
    error_log("načtení teploty: " . $e->getMessage());
}

$w['humidity'] = [];
try {
    $hs = $pdo->prepare("SELECT day_number, label1, value1, label2, value2 FROM growreport_humidity_2 WHERE week_id = :wid");
    $hs->execute(['wid' => $w['id']]);
    foreach ($hs->fetchAll(PDO::FETCH_ASSOC) as $h) {
        $w['humidity'][(int)$h['day_number']] = $h;
    }
} catch (Throwable $e) {
    error_log("načtení vlhkosti: " . $e->getMessage());
}

// Každé označení problému se načítá pouze ze záznamu daného dne.
$w['problemDays'] = [];
try {
    $problemsStmt = $pdo->prepare("SELECT day_number FROM growreport_problem_marks2 WHERE week_id=:wid AND marked=1");
    $problemsStmt->execute(['wid' => $w['id']]);
    $w['problemDays'] = array_map('intval', $problemsStmt->fetchAll(PDO::FETCH_COLUMN, 0));
} catch (Throwable $e) {
    error_log("Načtení označení problémů: " . $e->getMessage());
}

    // Načtení zalévání
    $w['watering'] = [];
    $w['wateringTotals'] = [];
    $w['zalitoDays'] = [];
    try {
        $ws2 = $pdo->prepare("SELECT day_number, pot_number, amount FROM growreport_watering2 WHERE week_id = :wid");
        $ws2->execute(['wid' => $w['id']]);
        foreach ($ws2->fetchAll(PDO::FETCH_ASSOC) as $wr) {
            $dn = (int)$wr['day_number'];
            $pn = (int)$wr['pot_number'];
            $amt = (float)$wr['amount'];
            if (!isset($w['watering'][$dn])) $w['watering'][$dn] = [];
            $w['watering'][$dn][$pn] = $amt;
            if (!isset($w['wateringTotals'][$dn])) $w['wateringTotals'][$dn] = 0;
            $w['wateringTotals'][$dn] += $amt;
        }
        foreach ($w['wateringTotals'] as $dn => $total) {
            if ($total > 0) $w['zalitoDays'][] = $dn;
        }
    } catch (Throwable $e) {
        error_log("načtení zalévání: " . $e->getMessage());
    }

    $w['wateringPH'] = [];
    try {
        if ($wateringPhSchemaError) throw new RuntimeException($wateringPhSchemaError);
        $phs = $pdo->prepare('SELECT day_number, ph_value FROM growreport_watering_ph2 WHERE week_id=:wid');
        $phs->execute(['wid' => $w['id']]);
        foreach ($phs->fetchAll(PDO::FETCH_ASSOC) as $phRow) {
            $w['wateringPH'][(int)$phRow['day_number']] = (float)$phRow['ph_value'];
        }
    } catch (Throwable $e) {
        error_log('Načtení pH zalévací vody: ' . $e->getMessage());
    }

    $w['wateringRunoffPH'] = [];
    $w['wateringRunoffLiters'] = [];
    try {
        if ($wateringRunoffPhSchemaError) throw new RuntimeException($wateringRunoffPhSchemaError);
        $runoffPhs = $pdo->prepare('SELECT day_number, ph_value, runoff_liters FROM growreport_watering_runoff_ph2 WHERE week_id=:wid');
        $runoffPhs->execute(['wid' => $w['id']]);
        foreach ($runoffPhs->fetchAll(PDO::FETCH_ASSOC) as $phRow) {
            if ($phRow['ph_value'] !== null) $w['wateringRunoffPH'][(int)$phRow['day_number']] = (float)$phRow['ph_value'];
            if ($phRow['runoff_liters'] !== null) $w['wateringRunoffLiters'][(int)$phRow['day_number']] = (float)$phRow['runoff_liters'];
        }
    } catch (Throwable $e) {
        error_log('Načtení pH výtokové vody: ' . $e->getMessage());
    }
}
unset($w);

// Načtení uložených stavů tlačítka „Splněno“.
$dailyTaskCompleted = [];
try {
    $dailyTaskStmt = $pdo->prepare('SELECT week_id, day_number, completed FROM growreport_daily_tasks2 WHERE report_id=:rid');
    $dailyTaskStmt->execute(['rid' => $growreport_id]);
    foreach ($dailyTaskStmt->fetchAll(PDO::FETCH_ASSOC) as $dailyTaskRow) {
        $dailyTaskCompleted[(int)$dailyTaskRow['week_id']][(int)$dailyTaskRow['day_number']] = (int)$dailyTaskRow['completed'] === 1;
    }
} catch (Throwable $e) {
    error_log('Načtení stavu denního plánu: ' . $e->getMessage());
}

// Připravit watering, pH, fertilizer a lighting data pro JS.
$wateringJS = [];
$wateringPHJS = [];
$wateringRunoffPHJS = [];
$wateringRunoffLitersJS = [];
$fertilizersForTasksJS = [];
$hnojenoForTasksJS = [];
$lightsJS = [];
$airflowJS = [];
foreach ($weeks as $w) {
    $wn = (int)$w['week_number'];
    $wateringJS[$wn] = $w['watering'];
    $wateringPHJS[$wn] = $w['wateringPH'] ?? [];
    $wateringRunoffPHJS[$wn] = $w['wateringRunoffPH'] ?? [];
    $wateringRunoffLitersJS[$wn] = $w['wateringRunoffLiters'] ?? [];
    $fertilizersForTasksJS[$wn] = [];
    $hnojenoForTasksJS[$wn] = [];
    for ($taskDay = 1; $taskDay <= 7; $taskDay++) {
        $fertilizersForTasksJS[$wn][$taskDay] = $w['fertilizers'][$taskDay]['items'] ?? [];
        $hnojenoForTasksJS[$wn][$taskDay] = in_array($taskDay, $w['hnojenoDays'] ?? [], true);
    }
    $lightsJS[$wn] = $w['lightsByDay'] ?? [];
    $airflowJS[$wn] = $w['airflowByDay'] ?? [];
}
// Připravení grafových statistik po týdnech.
$graphAverage = function (array $values) {
    $values = array_values(array_filter($values, function ($v) { return $v !== null && $v !== '' && is_numeric($v); }));
    return count($values) ? round(array_sum(array_map('floatval', $values)) / count($values), 2) : null;
};
$graphWeeksData = [];
// Kumulativní zálivka každého květináče pro samostatné grafy; týdny jsou načtené vzestupně.
$potWaterCumulative = [];
foreach ($potsConfig as $potConfigRow) {
    $potWaterCumulative[(int)$potConfigRow['pot_number']] = 0.0;
}
$floweringWeekCount = 0;
foreach ($weeks as $w) {
    $phase = trim((string)($w['phase'] ?? ''));
    $phaseColor = strtolower(trim((string)($w['phase_color'] ?? '')));
    $isFlower = in_array($phase, ['KVĚT', 'Květ', 'kvet', 'Kvet'], true) || $phaseColor === 'red';
    $floweringWeekCount = $isFlower ? $floweringWeekCount + 1 : 0;

    $temperatureValues = $humidityValues = $co2Values = [];
    foreach (($w['temperature'] ?? []) as $row) foreach (['value1', 'value2'] as $field) if (isset($row[$field]) && $row[$field] !== '' && is_numeric($row[$field])) $temperatureValues[] = (float)$row[$field];
    foreach (($w['humidity'] ?? []) as $row) foreach (['value1', 'value2'] as $field) if (isset($row[$field]) && $row[$field] !== '' && is_numeric($row[$field])) $humidityValues[] = (float)$row[$field];
    foreach (($w['co2'] ?? []) as $row) foreach (['value1', 'value2'] as $field) if (isset($row[$field]) && $row[$field] !== '' && is_numeric($row[$field])) $co2Values[] = (float)$row[$field];

    $waterTotal = round(array_sum(array_map('floatval', $w['wateringTotals'] ?? [])), 2);
    $liquidMl = 0; $dryScoops = 0;
    foreach (($w['fertilizers'] ?? []) as $fert) { $liquidMl += (int)($fert['total_ml'] ?? 0); $dryScoops += (int)($fert['total_scoops'] ?? 0); }
    $waterPHValues = array_values($w['wateringPH'] ?? []);
    $runoffPHValues = array_values($w['wateringRunoffPH'] ?? []);

    $lightWatts = 0; $hasLights = false;
    foreach (($w['lights'] ?? []) as $light) {
        if (!is_array($light)) continue;
        $watts = (($light['name'] ?? '') === 'LED') ? ($light['max_watts'] ?? null) : ($light['watts'] ?? null);
        if (is_numeric($watts)) { $lightWatts += (float)$watts; $hasLights = true; }
    }
    $airflowWattsValues = [];
    foreach (($w['airflowByDay'] ?? []) as $airflowSettings) {
        $dayWatts = 0; $hasAirflow = false;
        foreach (($airflowSettings['fans'] ?? []) as $fan) { $fw = $fan['used_watts'] ?? ((float)($fan['watts'] ?? 0) * (float)($fan['speed'] ?? ($fan['speed_scale'] ?? null ? 1 : 3)) / (float)($fan['speed_scale'] ?? 1)); if (is_numeric($fw) && $fw > 0) { $dayWatts += (float)$fw; $hasAirflow = true; } }
        foreach (($airflowSettings['exhausts'] ?? []) as $exhaust) if (isset($exhaust['watts']) && is_numeric($exhaust['watts'])) { $dayWatts += (float)$exhaust['watts']; $hasAirflow = true; }
        if ($hasAirflow) $airflowWattsValues[] = $dayWatts;
    }
    $phaseLabel = $phase !== '' ? $phase : ($phaseColor === 'green' ? 'RŮST' : ($phaseColor === 'red' ? 'KVĚT' : ($phaseColor === 'pink' ? 'SUŠINA' : 'Bez fáze')));
    $graphWeeksData[] = [
        'week' => (int)$w['week_number'], 'phase' => $phaseLabel,
        'phase_color' => in_array($phaseColor, ['green', 'red', 'pink'], true) ? $phaseColor : 'gray',
        'is_flower' => $isFlower, 'flowering_weeks' => $isFlower ? $floweringWeekCount : null,
        'temperature_avg' => $graphAverage($temperatureValues), 'humidity_avg' => $graphAverage($humidityValues), 'co2_avg' => $graphAverage($co2Values),
        'water_total_l' => $waterTotal, 'liquid_fertilizer_ml' => $liquidMl, 'dry_fertilizer_scoops' => $dryScoops,
        'watering_ph_avg' => $graphAverage($waterPHValues), 'runoff_ph_avg' => $graphAverage($runoffPHValues),
        'light_watts' => $hasLights ? round($lightWatts, 1) : null, 'airflow_watts_avg' => $graphAverage($airflowWattsValues),
        'problem_days_count' => count($w['problemDays'] ?? []),
        'lights_names' => array_values(array_filter(array_map(function ($light) { return (string)($light['name'] ?? ''); }, $w['lights'] ?? [])))
    ];

    // Pro každý nakonfigurovaný květináč přidat součet vody od začátku pěstování do konce tohoto týdne.
    $graphRowIndex = count($graphWeeksData) - 1;
    foreach ($potsConfig as $potConfigRow) {
        $potNumber = (int)$potConfigRow['pot_number'];
        $potWeekTotal = 0.0;
        foreach (($w['watering'] ?? []) as $dayAmounts) {
            if (is_array($dayAmounts) && isset($dayAmounts[$potNumber]) && is_numeric($dayAmounts[$potNumber])) {
                $potWeekTotal += (float)$dayAmounts[$potNumber];
            }
        }
        $potWaterCumulative[$potNumber] = round(($potWaterCumulative[$potNumber] ?? 0) + $potWeekTotal, 2);
        $graphWeeksData[$graphRowIndex]['pot_' . $potNumber . '_cumulative_l'] = $potWaterCumulative[$potNumber];
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Growreport: <?= htmlspecialchars($growreport['name']) ?></title>
    <link rel="stylesheet" href="styly.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <style>
        body { font-family: 'Montserrat', sans-serif; margin: 0; padding: 0; }
        .header-container { padding: 1.5rem; background-color: rgba(76,175,80,0.95); color: white; box-shadow: 0 4px 15px rgba(0,0,0,0.1); position: relative; border-radius: 0 0 15px 15px; text-align: center; }
        .header-container h1 { margin: 0; font-size: 2rem; text-shadow: 2px 2px 4px rgba(0,0,0,0.3); letter-spacing: 1px; display: flex; align-items: center; justify-content: center; }
        .header-container h1 i { margin-right: 12px; }
        nav ul { list-style-type: none; display: flex; margin: 10px 0 0 0; padding: 0; flex-wrap: wrap; }
        nav ul li { margin-right: 20px; margin-bottom: 5px; }
        nav ul li a { color: white; text-decoration: none; font-weight: 600; transition: all 0.3s; padding: 5px 10px; border-radius: 4px; font-size: 16px; display: flex; align-items: center; }
        .growreport { width: 44cm; height: 11cm; border: 2px solid #ccc; margin: 20px auto; display: flex; flex-direction: column; }
        .growreport-header { display: flex; justify-content: space-between; align-items: center; padding: 10px; background-color: lightgray; height: 1cm; font-size: 18px; font-weight: bold; }
        .growreport-header button { padding: 5px 10px; font-size: 14px; cursor: pointer; }
        .growreport-header .growth-btn { background-color: green; color: white; }
        .growreport-header .flower-btn { background-color: red; color: white; }
        .growreport-header .dry-btn { background-color: pink; color: white; }
        .growreport-header .delete-week-btn { background-color: black; color: red; font-weight: bold; font-size: 16px; line-height: 1; border: 1px solid #333; margin-left: 4px; padding: 5px 10px; }
        .growreport-header .delete-week-btn:hover { background-color: #222; color: #ff4444; }
        .flower-week-marker { display:inline-flex; align-items:center; gap:3px; margin:0 5px; padding:1px 5px; border:1px solid #c49b27; border-radius:4px; background:#fff3bd; color:#6b4f00; font-size:14px; line-height:1.2; font-weight:800; white-space:nowrap; vertical-align:middle; }
      .growreport-content { display: grid; grid-template-columns: repeat(6, 1fr); flex: 1; }
        .growreport-content div { border: 1px solid #ccc; display: flex; justify-content: center; align-items: center; position: relative; font-size: 12px; color: #000; overflow: hidden; flex-direction: column; }
        .growreport-content div span { font-size: 20px; color: white; font-weight: bold; }
        .growreport-content img { max-width: 100%; max-height: 200px; object-fit: contain; }
        .growreport-content input[type="file"] { position: absolute; opacity: 0; height: 100%; width: 100%; cursor: pointer; }
        .growreport-content input[type="file"]::-webkit-file-upload-button { visibility: hidden; }
        .growreport-content input[type="file"]::file-selector-button { visibility: hidden; }
        .growreport-content label { position: absolute; top: 10%; left: 50%; transform: translate(-50%, -50%); text-align: center; font-size: 14px; color: #888; pointer-events: none; }
        .growreport-content textarea { width: 90%; height: 100%; padding: 5px; resize: none; margin: auto; color: black; background-color: White; }
        .growreport-content button { margin-top: auto; padding: 5px 10px; font-size: 14px; cursor: pointer; }
        #add-week { display: inline-block; margin: 20px auto; padding: 10px 20px; font-size: 16px; cursor: pointer; }
        #save-growreport, #share-growreport, #delete-growreport { display: inline-block; margin: 20px auto; padding: 10px 20px; font-size: 16px; cursor: pointer; font-weight: 600; border: none; border-radius: 5px; color: white; transition: all 0.3s; }
        #save-growreport { background-color: #4CAF50; }
        #save-growreport:hover { background-color: #388E3C; transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        #share-growreport { background-color: #ff9800; margin-left: 10px; }
        #share-growreport:hover { background-color: #f57c00; transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        #delete-growreport { background-color: #f44336; margin-left: 10px; }
        #delete-growreport:hover { background-color: #d32f2f; transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .growreport-buttons { display: flex; justify-content: center; margin: 20px 0; }
        .growreport-content input[type="checkbox"] { width: 20px; height: 34px; cursor: pointer; }
        .growreport-content label { font-size: 16px; margin-left: 5px; line-height: 20px; }
        .day-box { width: 100%; height: 40px; display: flex; align-items: center; justify-content: center; position: relative; }
        .day-box .day-zalito { position: absolute; background-color: transparent; width: 33.333%; height: 100%; left: 0; }
        .day-box .day-hnojeno { position: absolute; background-color: transparent; width: 33.333%; height: 100%; left: 33.333%; right: auto; }
        .day-box .day-co2 { position: absolute; background-color: transparent; width: 33.334%; height: 100%; right: 0; }
        .day-box .day-co2.on { background-color: gray; }
        .day-box span { position: relative; z-index: 1; color: black; }
        textarea[placeholder="Sekce 2: Text"] { color: blue; border: 2px solid blue; cursor: pointer; }
        textarea[placeholder="Sekce 3: Text"] { color: brown; border: 2px solid brown; }
        textarea[placeholder="Sekce 1: Text"] { color: black; border: 2px solid black; }
        textarea[placeholder="Sekce 4: Text"] { color: #2E7D32; border: 2px solid #2E7D32; }
        .hidden { display: none; }
        .day { width: 1cm; height: 1cm; display: flex; align-items: center; justify-content: center; border: 1px solid #ccc; }
        .growreport-content > div:nth-child(6) > div:nth-child(1),
        .growreport-content > div:nth-child(6) > div:nth-child(2),
        .growreport-content > div:nth-child(6) > div:nth-child(3),
        .growreport-content > div:nth-child(6) > div:nth-child(4) { border: none !important; }
        .growreport-content > div:nth-child(6) > div:nth-child(1) > div,
        .growreport-content > div:nth-child(6) > div:nth-child(2) > div,
        .growreport-content > div:nth-child(6) > div:nth-child(3) > div,
        .growreport-content > div:nth-child(6) > div:nth-child(4) > div { border: none !important; }
        .day-box { border: none !important; }
        .growreport-content > div:nth-child(6) > div:nth-child(2) > div > div,
        .growreport-content > div:nth-child(6) > div:nth-child(3) > div > div { border: none !important; }
        .day-zalito[style*="background-color: blue"] { background-color: blue !important; }
        .day-hnojeno[style*="background-color: brown"] { background-color: brown !important; }

        /* CO2 */
        .growreport-content .co2-cell { height: 40px; width: 100%; border: none !important; display: flex; align-items: center; justify-content: center; }
        .growreport-content button.co2-btn { margin: 0 auto; padding: 0; width: 90%; height: 36px; display: flex; flex-direction: column; align-items: center; justify-content: center; background: #f1f8e9; color: red; border: 1px solid #2E7D32; border-radius: 4px; font-size: 9px; line-height: 1.15; font-weight: 600; cursor: pointer; overflow: hidden; white-space: nowrap; }
        .growreport-content button.co2-btn:hover { background: #dcedc8; }
        .growreport-content button.co2-btn:disabled { cursor: default; color: red; opacity: 1; }
        .growreport-content button.co2-btn .co2-line { font-size: 9px; line-height: 1.15; color: red; }
        .growreport-content button.co2-btn .co2-plus { font-size: 12px; color: #888; font-weight: 400; }

/* TEPLOTA */
.growreport-content .temp-cell { height: 40px; width: 100%; border: none !important; display: flex; align-items: center; justify-content: center; }
.growreport-content button.temp-btn { margin: 0 auto; padding: 0; width: 90%; height: 36px; display: flex; flex-direction: column; align-items: center; justify-content: center; background: #ffebee; color: #c62828; border: 1px solid #c62828; border-radius: 4px; font-size: 9px; line-height: 1.15; font-weight: 600; cursor: pointer; overflow: hidden; white-space: nowrap; }
.growreport-content button.temp-btn:hover { background: #ffcdd2; }
.growreport-content button.temp-btn:disabled { cursor: default; color: #c62828; opacity: 1; }
.growreport-content button.temp-btn .temp-line { font-size: 9px; line-height: 1.15; color: #c62828; }
.growreport-content button.temp-btn .temp-plus { font-size: 12px; color: #888; font-weight: 400; }

        /* Zalito tlačítka (stejná výška jako den) */
        .growreport-content .zalito-cell { height: 40px; width: 100%; border: none !important; display: flex; align-items: center; justify-content: center; }
        .growreport-content button.zalito-btn { margin: 0 auto; padding: 0; width: 90%; height: 36px; display: flex; align-items: center; justify-content: center; background: #e3f2fd; color: blue; border: 1px solid #1565C0; border-radius: 4px; font-size: 18px; font-weight: 700; cursor: pointer; overflow: hidden; white-space: nowrap; }
        .growreport-content button.zalito-btn:hover { background: #bbdefb; }
        .growreport-content button.zalito-btn:disabled { cursor: default; color: blue; opacity: 1; }
        .growreport-content button.zalito-btn .zal-total { font-size: 18px; color: blue; font-weight: 700; }
        .growreport-content button.zalito-btn .zal-total.small { font-size: 13px; }
        .growreport-content button.zalito-btn .zal-plus { font-size: 12px; color: #888; font-weight: 400; }
        /* Hnojeno: stejný rozměr a rozložení jako tlačítka Zalito */
        .growreport-content button.hnojeno-btn { margin: 0 auto; padding: 0 2px; width: 90%; height: 36px; display: flex; align-items: center; justify-content: center; background: #f3e5dc; color: #7b3f19; border: 1px solid #8d4b24; border-radius: 4px; font-size: 15px; font-weight: 700; cursor: pointer; overflow: hidden; white-space: nowrap; }
        .growreport-content button.hnojeno-btn:hover { background: #e7c9b5; }
        .growreport-content button.hnojeno-btn:disabled { cursor: default; opacity: 1; }
        .growreport-content button.hnojeno-btn .hnoj-total { font-size: 13px; color: #7b3f19; font-weight: 700; }
        .growreport-content button.hnojeno-btn .hnoj-total.small { font-size: 10px; }
        .growreport-content button.hnojeno-btn .hnoj-plus { font-size: 12px; color: #888; font-weight: 400; }
        /* Tlačítko vymazání je součástí dialogu Hnojení, denní políčka zůstávají beze změny. */

        /* Zvětšené okénko CO2 */
        #co2-tip { position: fixed; display: none; z-index: 5000; pointer-events: none; background: #fff; border: 2px solid #2E7D32; border-radius: 8px; box-shadow: 0 6px 18px rgba(0,0,0,0.35); color: red; font-weight: 700; padding: 6px 10px; box-sizing: border-box; text-align: center; white-space: nowrap; flex-direction: column; align-items: center; justify-content: center; }
        #co2-tip .tip-line { font-size: 20px; line-height: 1.3; color: red; }

/* Zvětšené okénko TEPLOTA */
#temp-tip { position: fixed; display: none; z-index: 5000; pointer-events: none; background: #fff; border: 2px solid #c62828; border-radius: 8px; box-shadow: 0 6px 18px rgba(0,0,0,0.35); color: #c62828; font-weight: 700; padding: 6px 10px; box-sizing: border-box; text-align: center; white-space: nowrap; flex-direction: column; align-items: center; justify-content: center; }
#temp-tip .tip-line { font-size: 20px; line-height: 1.3; color: #c62828; }

/* Vlhkost: stejná velikost políček jako Teplota, jednotná barva */
.humidity-dialog-header { background-color: #1565C0; color: white; padding: 12px 20px; text-align: center; }
.humidity-dialog-header h3 { margin: 0; font-size: 20px; }
.humidity-dialog-body { padding: 18px 20px; }
.humidity-row { display: flex; gap: 10px; margin-bottom: 12px; align-items: center; }
.humidity-row input { padding: 8px; font-size: 15px; border: 1px solid #bbb; border-radius: 4px; box-sizing: border-box; font-family: 'Montserrat', sans-serif; }
.humidity-row .humidity-label-input { flex: 1; min-width: 0; }
.humidity-row .humidity-value-input { width: 90px; text-align: center; }
.humidity-hint { font-size: 12px; color: #777; text-align: center; margin-top: 4px; }
.growreport-content button.humidity-btn .humidity-line { color: #1565C0; }
#humidity-tip { position: fixed; display: none; z-index: 5000; pointer-events: none; background: #fff; border: 2px solid #1565C0; border-radius: 8px; box-shadow: 0 6px 18px rgba(0,0,0,0.35); color: #1565C0; font-weight: 700; padding: 6px 10px; box-sizing: border-box; text-align: center; white-space: nowrap; flex-direction: column; align-items: center; justify-content: center; }
#humidity-tip .tip-line { font-size: 20px; line-height: 1.3; color: #1565C0; }

/* Světla: stejná šířka sloupce a výška denních polí jako Teplota/Vlhkost. */
.lights-dialog-header { background:#2e7d32; color:#fff; padding:12px 20px; text-align:center; }
.lights-dialog-header h3 { margin:0; font-size:20px; }
.lights-dialog-body { padding:16px 20px; }
.lights-pick-list { display:grid; grid-template-columns:1fr 1fr; gap:9px 14px; }
.lights-pick-list label { display:flex; align-items:center; gap:8px; margin:0; font-size:14px; }
.lights-pick-list input { width:18px; height:18px; }
.lights-field-row { display:grid; grid-template-columns:minmax(100px,1fr) 120px; align-items:center; gap:10px; margin:9px 0; }
.lights-field-row input { width:100%; box-sizing:border-box; padding:7px; text-align:center; }
.lights-card { border:1px solid #d7e3d5; border-radius:8px; padding:10px 12px; margin:10px 0; }
.lights-card h4 { margin:0 0 7px; color:#2e7d32; }
.lights-hint { font-size:12px; color:#666; margin-top:8px; }
.growreport-content button.lights-btn { margin:0 auto; padding:1px 2px; width:90%; height:36px; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:0; background:#f1f8e9; color:#2e7d32; border:1px solid #2e7d32; border-radius:4px; font-size:8px; line-height:1.05; font-weight:700; cursor:pointer; overflow:hidden; white-space:normal; }
.growreport-content button.lights-btn .lights-name { color:#2e7d32; font-size:8px; line-height:10px; font-weight:700; max-width:100%; }
.growreport-content button.lights-btn .co2-plus { color:#888; font-size:12px; }
/* Světla a vzduch používají samostatné plovoucí zvětšené okno stejně jako Vlhkost. */
.growreport-content > div:nth-child(7) .temp-humidity-column.lights-column,
.growreport-content > div:nth-child(7) .temp-humidity-column.airflow-column { overflow:visible !important; position:relative; z-index:1; }
#lights-tip,#airflow-tip { position:fixed; display:none; z-index:5000; pointer-events:none; background:#fff; border-radius:8px; box-shadow:0 6px 18px rgba(0,0,0,.35); padding:8px 12px; box-sizing:border-box; text-align:center; white-space:normal; flex-direction:column; align-items:center; justify-content:center; font-weight:700; }
#lights-tip { border:2px solid #2e7d32; color:#2e7d32; }
#airflow-tip { border:2px solid #1565c0; color:#1565c0; }
#lights-tip .tip-line,#airflow-tip .tip-line { font-size:20px; line-height:1.3; }
.growreport-content button.lights-btn,.growreport-content button.airflow-btn { transition:background-color .15s ease,box-shadow .15s ease; }
.growreport-content button.lights-btn:hover,.growreport-content button.airflow-btn:hover { background:#e2f3d5; box-shadow:0 2px 7px rgba(0,0,0,.2); }
@media(max-width:600px){ .lights-pick-list{grid-template-columns:1fr}.lights-dialog-body{padding:12px}.lights-field-row{grid-template-columns:minmax(100px,1fr) 90px;} }

/* Větráky a odtahy – stylově navazují na sloupec Světla, bez změny rozměrů ostatních sekcí. */
.airflow-dialog-header { background:#2e7d32; color:#fff; padding:12px 20px; text-align:center; }
.airflow-dialog-header h3 { margin:0; font-size:20px; }
.airflow-dialog-body { padding:16px 20px; }
.airflow-counts { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.airflow-counts label { display:flex; flex-direction:column; gap:6px; font-weight:600; }
.airflow-counts select { padding:9px; font-size:15px; }
.airflow-card { border:1px solid #d7e3d5; border-radius:8px; padding:10px 12px; margin:10px 0; }
.airflow-card h4 { margin:0 0 8px; color:#2e7d32; }
.airflow-field-row { display:grid; grid-template-columns:minmax(130px,1fr) 130px; align-items:center; gap:10px; margin:9px 0; }
.airflow-field-row input,.airflow-field-row select { width:100%; box-sizing:border-box; padding:7px; text-align:center; }
.airflow-hint { font-size:12px; color:#666; margin-top:8px; }
.growreport-content button.airflow-btn { margin:0 auto; padding:1px 2px; width:90%; height:36px; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:1px; background:#f1f8e9; color:#2e7d32; border:1px solid #2e7d32; border-radius:4px; font-size:8px; line-height:10px; font-weight:700; cursor:pointer; overflow:hidden; white-space:normal; }
.growreport-content button.airflow-btn .airflow-summary { color:#2e7d32; font-size:8px; line-height:10px; }
.growreport-content button.airflow-btn .co2-plus { color:#000 !important; font-size:12px; font-weight:700; }
.airflow-modal-backdrop { position:fixed; inset:0; z-index:11000; display:none; align-items:center; justify-content:center; padding:14px; background:rgba(0,0,0,.58); box-sizing:border-box; }
.airflow-modal { width:100%; max-width:700px; max-height:90vh; overflow:auto; background:#fff; color:#222; border-radius:10px; box-shadow:0 10px 40px #0005; }
@media(max-width:600px){.airflow-dialog-body{padding:12px}.airflow-counts{grid-template-columns:1fr}.airflow-field-row{grid-template-columns:minmax(110px,1fr) 95px;gap:8px}}

/* Obě pravé datové sekce jsou uvnitř původního 6. sloupce, takže se nemění rozložení Growreportu ani velikost fotek. */
.growreport-content > div:nth-child(7).temp-humidity-section { display: flex !important; flex-direction: row !important; align-items: flex-start !important; justify-content: flex-start !important; }
.growreport-content > div:nth-child(7) .temp-humidity-column { width: 20% !important; min-width: 0 !important; max-width: 20% !important; flex: 0 0 20% !important; display: flex !important; flex-direction: column !important; align-items: stretch !important; justify-content: flex-start !important; border: none !important; box-sizing: border-box !important; overflow: hidden !important; }
.growreport-content > div:nth-child(7) .temp-humidity-column > textarea { width: 100% !important; height: auto !important; min-height: 32px !important; margin-top: 4px !important; margin-bottom: 4px !important; margin-left: 0 !important; margin-right: auto !important; box-sizing: border-box !important; flex: 0 0 auto !important; }
.growreport-content > div:nth-child(7) .temp-humidity-list { width: 100% !important; display: flex !important; flex-direction: column !important; align-items: stretch !important; justify-content: flex-start !important; border: none !important; margin: 0 !important; padding: 0 !important; }
.growreport-content > div:nth-child(7) .temp-humidity-list > .co2-cell { width: 100% !important; height: 40px !important; flex: 0 0 40px !important; box-sizing: border-box !important; border: none !important; margin: 0 !important; padding: 0 !important; }
.growreport-content > div:nth-child(7) .temp-humidity-list > .co2-cell > .co2-btn { width: 90% !important; height: 36px !important; margin: 0 auto !important; box-sizing: border-box !important; }

        /* CO2 dialog */
        .co2-dialog-header { background-color: #2E7D32; color: white; padding: 12px 20px; text-align: center; }
        .co2-dialog-header h3 { margin: 0; font-size: 20px; }
        .co2-dialog-body { padding: 18px 20px; }
        .co2-row { display: flex; gap: 10px; margin-bottom: 12px; align-items: center; }
        .co2-row input { padding: 8px; font-size: 15px; border: 1px solid #bbb; border-radius: 4px; box-sizing: border-box; font-family: 'Montserrat', sans-serif; }
        .co2-row .co2-label-input { flex: 1; min-width: 0; }
        .co2-row .co2-value-input { width: 90px; text-align: center; }
        .co2-hint { font-size: 12px; color: #777; text-align: center; margin-top: 4px; }

/* TEPLOTA dialog */
.temp-dialog-header { background-color: #c62828; color: white; padding: 12px 20px; text-align: center; }
.temp-dialog-header h3 { margin: 0; font-size: 20px; }
.temp-dialog-body { padding: 18px 20px; }
.temp-row { display: flex; gap: 10px; margin-bottom: 12px; align-items: center; }
.temp-row input { padding: 8px; font-size: 15px; border: 1px solid #bbb; border-radius: 4px; box-sizing: border-box; font-family: 'Montserrat', sans-serif; }
.temp-row .temp-label-input { flex: 1; min-width: 0; }
.temp-row .temp-value-input { width: 90px; text-align: center; }
.temp-hint { font-size: 12px; color: #777; text-align: center; margin-top: 4px; }

        /* Pot config dialog */
        .pots-dialog-header { background-color: #1565C0; color: white; padding: 12px 20px; text-align: center; }
        .pots-dialog-header h3 { margin: 0; font-size: 20px; }
        .pots-dialog-body { padding: 18px 20px; }
        .pots-count-row { display: flex; gap: 10px; margin-bottom: 16px; align-items: center; justify-content: center; }
        .pots-count-row label { font-weight: 600; font-size: 15px; }
        .pots-count-row select { padding: 8px 12px; font-size: 16px; border: 1px solid #bbb; border-radius: 4px; font-family: 'Montserrat', sans-serif; }
        .pot-row { display: flex; gap: 8px; margin-bottom: 10px; align-items: center; }
        .pot-row span { font-weight: 600; min-width: 0; }
        .pot-row input { padding: 8px; font-size: 15px; border: 1px solid #bbb; border-radius: 4px; width: 90px; text-align: center; font-family: 'Montserrat', sans-serif; }
        .pot-row .pot-name-label { font-weight: 600; min-width: 0; white-space: nowrap; }
        .pot-row .pot-name-input { width: auto; min-width: 80px; flex: 1 1 auto; text-align: left; }
        .pot-row .pot-volume-label { font-weight: 600; min-width: 0; white-space: nowrap; }
        .pot-row .pot-volume-input { width: 74px; flex: 0 0 74px; }

        /* Watering dialog */
        .watering-dialog-header { background-color: #1565C0; color: white; padding: 12px 20px; text-align: center; }
        .watering-dialog-header h3 { margin: 0; font-size: 20px; }
        .watering-dialog-body { padding: 18px 10px; }
        .watering-pots-row { display: flex; gap: 8px; justify-content: center; overflow-x: auto; padding: 0 5px; }
        .pot-card { display: flex; flex-direction: column; align-items: center; gap: 4px; min-width: 64px; }
        .pot-card .pot-lbl { font-weight: 700; font-size: 12px; color: #1565C0; max-width: 76px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pot-card .pot-vol { font-size: 11px; color: #666; margin-bottom: 2px; }
        /* Scroll picker */
        .sp-wrap { width: 64px; height: 120px; overflow-y: auto; scroll-snap-type: y mandatory; border: 2px solid #1565C0; border-radius: 8px; background: #f0f4ff; position: relative; -webkit-overflow-scrolling: touch; }
        .sp-wrap::-webkit-scrollbar { width: 0; }
        .sp-pad { height: 40px; flex-shrink: 0; }
        .sp-item { height: 40px; scroll-snap-align: center; display: flex; align-items: center; justify-content: center; font-size: 16px; color: #bbb; flex-shrink: 0; user-select: none; }
        .sp-item.sp-sel { color: #1565C0; font-weight: 700; font-size: 22px; background: rgba(21,101,192,0.08); }
        .watering-total { text-align: center; font-size: 22px; color: blue; font-weight: 700; margin-top: 14px; }
        .watering-ph-panel { max-width: 560px; margin: 14px auto 0; padding: 12px 14px; background: #e8f5e9; border: 1px solid #43a047; border-radius: 8px; color: #1b5e20; box-sizing: border-box; }
        .watering-ph-row { display: flex; align-items: center; justify-content: center; gap: 12px; flex-wrap: wrap; }
        .watering-ph-row label { font-weight: 700; color: #1b5e20; }
        .watering-ph-select { min-width: 100px; padding: 8px 12px; border: 2px solid #2e7d32; border-radius: 6px; font-size: 17px; font-weight: 700; text-align: center; background: #fff; }
        .watering-ph-saved { margin-top: 7px; text-align: center; font-size: 13px; color: #2e7d32; font-weight: 600; }
        .watering-calc-open-row { display:flex; justify-content:center; margin:0 0 14px; }
        .watering-calc-open-btn { border:1px solid #0d47a1; background:#1565c0; color:#fff; border-radius:7px; padding:9px 14px; font-size:14px; font-weight:700; cursor:pointer; box-shadow:0 2px 5px rgba(21,101,192,.18); }
        .watering-calc-open-btn:hover { background:#0d47a1; }
        .watering-calc-overlay { z-index:1200; }
        .watering-calc-dialog { background:#fff; border-radius:10px; width:min(960px,96vw); max-height:92vh; overflow:auto; box-shadow:0 14px 40px rgba(0,0,0,.35); }
        .watering-calc-header { background:#1565c0; color:#fff; padding:12px 18px; display:flex; align-items:center; justify-content:space-between; gap:12px; position:sticky; top:0; z-index:2; }
        .watering-calc-header h3 { margin:0; font-size:20px; }
        .watering-calc-close { border:0; color:#fff; background:transparent; font-size:28px; font-weight:700; line-height:1; cursor:pointer; }
        .watering-calc-body { padding:15px; display:grid; gap:14px; }
        .watering-calc-section { border:1px solid #d5e2f0; background:#f8fbff; border-radius:8px; padding:12px; min-width:0; }
        .watering-calc-section h4 { margin:0 0 10px; color:#174678; font-size:15px; }
        .watering-calc-phase-row { display:flex; flex-wrap:wrap; gap:10px; }
        .watering-calc-phase-btn { flex:1 1 150px; border:1px solid #bdcfe3; border-radius:7px; padding:10px; background:#fff; color:#23364d; font-weight:700; cursor:pointer; }
        .watering-calc-phase-btn.active { border:2px solid #2e7d32; background:#e8f5e9; color:#1b5e20; }
        .watering-calc-phase-note { margin:8px 0 0; color:#555; font-size:12px; }
        .watering-calc-stage-grid { display:grid; grid-template-columns:repeat(9,minmax(0,1fr)); gap:6px; }
        .watering-calc-stage-grid.flower-grid { grid-template-columns:repeat(10,minmax(0,1fr)); }
        .watering-calc-stage-btn { min-width:0; position:relative; overflow:visible; z-index:1; border:1px solid #cbd6e2; border-radius:7px; background:#fff; color:#263238; padding:5px 2px 6px; display:flex; flex-direction:column; align-items:center; justify-content:flex-start; gap:3px; cursor:pointer; }
        .watering-calc-stage-btn:hover { background:#f1f8ff; border-color:#1565c0; position:relative; z-index:30; }
        .watering-calc-stage-btn.selected { border:2px solid #2e7d32; background:#e8f5e9; box-shadow:0 0 0 1px rgba(46,125,50,.1); }
        .watering-calc-stage-btn.flower-stage.selected { border-color:#8e24aa; background:#f8eafa; }
        .watering-calc-stage-btn svg { width:58px; max-width:100%; height:64px; display:block; position:relative; z-index:1; transform-origin:center center; transition:transform .14s ease; }
        .watering-calc-stage-btn svg:hover { transform:scale(4); z-index:40; filter:drop-shadow(0 2px 5px rgba(0,0,0,.25)); }
        .watering-calc-stage-grid { overflow:visible; }
        .watering-calc-stage-num { font-size:14px; font-weight:800; }
        .watering-calc-stage-name { font-size:10px; line-height:1.2; text-align:center; min-height:24px; overflow-wrap:anywhere; }
        .watering-calc-volume-rows { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:8px; }
        .watering-calc-volume-row { display:flex; align-items:center; justify-content:space-between; gap:8px; padding:7px 9px; border:1px solid #dce5ef; border-radius:6px; background:#fff; }
        .watering-calc-volume-row label { font-weight:700; font-size:12px; min-width:0; overflow-wrap:anywhere; }
        .watering-calc-volume-row input { width:78px; min-width:0; box-sizing:border-box; text-align:center; padding:6px; border:1px solid #b8c6d6; border-radius:5px; font-size:14px; }
        .watering-calc-frequency-row { display:flex; flex-wrap:wrap; gap:8px; }
        .watering-calc-frequency-option { display:flex; align-items:center; gap:6px; flex:1 1 150px; border:1px solid #d0dbe8; background:#fff; border-radius:6px; padding:8px; font-size:13px; cursor:pointer; }
        .watering-calc-frequency-option:has(input:checked) { border-color:#1565c0; background:#e8f1ff; }
        .watering-calc-frequency-option input { accent-color:#1565c0; }
        .watering-calc-round-row { margin-top:7px; }
        .watering-calc-round-option { flex:1 1 100%; background:#fff8e1; border-color:#e0b54b; }
        .watering-calc-round-option:has(input:checked) { background:#fff0bd; border-color:#b8860b; }
        .watering-calc-help { margin:8px 0 0; color:#666; font-size:12px; line-height:1.4; }
        .watering-calc-result { font-size:14px; }
        .watering-calc-result:empty { display:none; }
        .watering-calc-result table { width:100%; border-collapse:collapse; margin-top:8px; background:#fff; }
        .watering-calc-result th,.watering-calc-result td { border:1px solid #d6e1ee; padding:8px; text-align:left; }
        .watering-calc-result th { background:#e8f1ff; color:#174678; }
        .watering-calc-result .calc-result-total { font-weight:800; background:#e8f5e9; color:#1b5e20; }
        .watering-calc-warning { padding:10px 12px; background:#fff3e0; border:1px solid #ffcc80; color:#7a4300; border-radius:6px; }
        .watering-calc-consumption-wrap { margin-top:12px; border:1px solid #d4e1ef; border-radius:7px; background:#fff; padding:9px; }
        .watering-calc-consumption-wrap h5 { margin:0 0 7px; font-size:13px; color:#174678; }
        .watering-calc-consumption-scroll { overflow-x:auto; }
        .watering-calc-consumption-table { width:100%; min-width:500px; border-collapse:collapse; font-size:12px; }
        .watering-calc-consumption-table th,.watering-calc-consumption-table td { border:1px solid #d6e1ee; padding:6px 7px; text-align:center; }
        .watering-calc-consumption-table th:first-child,.watering-calc-consumption-table td:first-child { text-align:left; }
        .watering-calc-consumption-table th { background:#e8f1ff; color:#174678; }
        .watering-calc-consumption-table [data-frequency].is-selected-frequency { font-weight:800; }
        .watering-calc-consumption-table [data-frequency].watering-rec-optimal { background:#e8f5e9; border-left:4px solid #2e7d32 !important; border-right:4px solid #2e7d32 !important; }
        .watering-calc-consumption-table thead [data-frequency].watering-rec-optimal { border-top:4px solid #2e7d32 !important; color:#1b5e20; }
        .watering-calc-consumption-table tbody tr:last-child [data-frequency].watering-rec-optimal { border-bottom:4px solid #2e7d32 !important; }
        .watering-calc-consumption-table [data-frequency].watering-rec-possible { background:#fff8e1; border-left:4px solid #f9a825 !important; border-right:4px solid #f9a825 !important; }
        .watering-calc-consumption-table thead [data-frequency].watering-rec-possible { border-top:4px solid #f9a825 !important; color:#8a5a00; }
        .watering-calc-consumption-table tbody tr:last-child [data-frequency].watering-rec-possible { border-bottom:4px solid #f9a825 !important; }
        .watering-calc-consumption-table [data-frequency].watering-rec-not-recommended { background:#ffebee; border-left:4px solid #c62828 !important; border-right:4px solid #c62828 !important; }
        .watering-calc-consumption-table thead [data-frequency].watering-rec-not-recommended { border-top:4px solid #c62828 !important; color:#b71c1c; }
        .watering-calc-consumption-table tbody tr:last-child [data-frequency].watering-rec-not-recommended { border-bottom:4px solid #c62828 !important; }
        .watering-calc-consumption-table thead [data-frequency].watering-rec-not-recommended::after { content:'nedoporučeno'; display:block; margin-top:3px; font-size:9px; line-height:1.1; font-weight:700; color:#b71c1c; }
        .watering-calc-dose-legend { display:flex; flex-wrap:wrap; gap:10px 16px; align-items:center; margin:0 0 9px; font-size:11px; color:#334155; }
        .watering-calc-dose-legend-item { display:inline-flex; align-items:center; gap:6px; }
        .watering-calc-dose-legend-square { width:11px; height:11px; display:inline-block; border:1px solid rgba(0,0,0,.22); border-radius:2px; flex:0 0 11px; }
        .watering-calc-dose-legend-square.optimal { background:#2e7d32; }
        .watering-calc-dose-legend-square.possible { background:#f9a825; }
        .watering-calc-dose-legend-square.not-recommended { background:#c62828; }
        .watering-calc-actions { display:flex; justify-content:flex-end; gap:8px; padding:0 15px 15px; flex-wrap:wrap; }
        .watering-calc-action-btn { border:0; border-radius:6px; padding:9px 15px; font-weight:700; cursor:pointer; }
        .watering-calc-action-close { background:#757575; color:#fff; }
        .watering-calc-action-calculate { background:#2e7d32; color:#fff; }
        .watering-calc-action-apply { background:#1565c0; color:#fff; }
        @media (max-width:760px) { .watering-calc-stage-grid,.watering-calc-stage-grid.flower-grid { grid-template-columns:repeat(5,minmax(0,1fr)); } .watering-calc-stage-btn svg { height:58px; } .watering-calc-header h3 { font-size:17px; } }

        .preview-image { max-width: 100%; max-height: 200px; margin-top: 10px; display: none; border: 1px solid #ddd; border-radius: 4px; }
        .compression-info { font-size: 12px; color: #4CAF50; margin-top: 5px; text-align: center; display: none; }
        .loading-spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid rgba(0, 0, 0, 0.1); border-radius: 50%; border-top-color: #4CAF50; animation: spin 1s ease-in-out infinite; margin-right: 5px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        footer { text-align: center; padding: 20px; background-color: rgba(76,175,80,0.95); color: white; margin-top: 50px; }
        .report-header { text-align: center; margin: 20px 0; padding: 10px; }
        .report-header h2 { margin-bottom: 5px; color: #2E7D32; }
        .report-header p { margin: 5px 0; color: #555; }
        .confirm-dialog-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(0,0,0,0.7); z-index: 1000; display: none; justify-content: center; align-items: center; }
        .confirm-dialog { background-color: #fff; border-radius: 8px; overflow: hidden; width: 90%; max-width: 500px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); animation: dialog-appear 0.3s ease; }
        @keyframes dialog-appear { from { transform: translateY(-50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .confirm-header { background-color: #f44336; color: white; padding: 15px 20px; display: flex; align-items: center; justify-content: center; position: relative; }
        .confirm-header h3 { margin: 0; font-size: 22px; text-align: center; }
        .confirm-header i { font-size: 28px; margin-right: 15px; }
        .confirm-content { padding: 20px; font-size: 16px; color: #333; text-align: center; }
        .confirm-actions { display: flex; justify-content: center; padding: 15px 20px; background-color: #f8f8f8; border-top: 1px solid #eee; gap: 15px; }
        .confirm-button { padding: 10px 20px; font-size: 16px; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; min-width: 100px; transition: all 0.2s; }
        .confirm-cancel { background-color: #e0e0e0; color: #333; }
        .confirm-cancel:hover { background-color: #d0d0d0; }
        .confirm-delete { background-color: #f44336; color: white; }
        .confirm-delete:hover { background-color: #d32f2f; }
        .confirm-ok { background-color: #4CAF50; color: white; }
        .confirm-ok:hover { background-color: #388E3C; }
        .confirm-ok-blue { background-color: #1565C0; color: white; }
        .confirm-ok-blue:hover { background-color: #0D47A1; }

  /* DNY: jen širší barevné pruhy uvnitř boxu */
.week-metrics-grid .day-box{
  position: relative;
  overflow: hidden;
}

/* Pruhy zalito, hnojeno a CO2 zůstávají celé uvnitř svého denního čtverečku.
   Každý má vlastní třetinu šířky; pruh světel zůstává nahoře jako tenká lišta. */
.week-metrics-grid .day-box .day-zalito{
  left: 0;
  right: auto;
  width: 33.333%;
}

.week-metrics-grid .day-box .day-hnojeno{
  left: 33.333%;
  right: auto;
  width: 33.333%;
}

.week-metrics-grid .day-box .day-co2{
  left: auto;
  right: 0;
  width: 33.334%;
}

/* číslo dne zůstane nahoře čitelné */
.week-metrics-grid .day-box > span{
  position: relative;
  z-index: 2;
}

/* Pruh světel je oddělený od pruhů zalití, hnojení a CO₂, aby se původní značení neměnilo. */
.week-metrics-grid .day-box .day-lights {
  position: absolute;
  z-index: 1;
  top: 0;
  left: 0;
  right: 0;
  height: 6px;
  display: flex !important;
  flex-direction: row !important;
  align-items: stretch !important;
  justify-content: flex-start !important;
  gap: 0;
  margin: 0;
  padding: 0;
  border: none !important;
  overflow: hidden;
  pointer-events: none;
}
.week-metrics-grid .day-box .day-lights > span {
  display: block;
  position: static;
  flex: 1 1 0;
  min-width: 0;
  height: 6px;
  margin: 0;
  padding: 0;
  border: none !important;
  font-size: 0;
  line-height: 6px;
  font-weight: normal;
}

/* ===== ONLY sekce 7 (Teplota) after inserting daily-task column ===== */
.growreport-content > div:nth-child(7){
  border: 1px solid #ccc !important;
  display: flex !important;
  flex-direction: column !important;
  align-items: stretch !important;
  justify-content: flex-start !important;
  overflow: hidden !important;
}

/* textarea nahoře - úplně doprava */
.growreport-content > div:nth-child(7) > textarea{
  width: 20% !important;
  height: auto !important;
  min-height: 32px !important;
  margin-top: 4px !important;
  margin-bottom: 4px !important;
 margin-left: 0 !important;
  margin-right: auto !important
  box-sizing: border-box !important;
}

/* wrapper se 7 řádky */
.growreport-content > div:nth-child(7) > div{
  width: 100% !important;
  display: flex !important;
  flex-direction: column !important;
  align-items: stretch !important;
  justify-content: flex-start !important;
  border: none !important;
  margin: 0 !important;
  padding: 0 !important;
}

/* každý řádek */
.growreport-content > div:nth-child(7) > div > .co2-cell{
  height: 40px !important;
  width: 20% !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  border: none !important;
  margin: 0 !important;
  padding: 0 !important;
}

/* tlačítko v teplotě */
.growreport-content > div:nth-child(7) > div > .co2-cell > .co2-btn{
  width: 90% !important;
  height: 36px !important;
  margin: 0 auto !important;
  box-sizing: border-box !important;
}

/* Problém: značka se týká pouze vybraného dne. */
.growreport-content button.problem-btn { margin:0 auto; padding:0; width:90%; height:36px; display:flex; align-items:center; justify-content:center; background:#fff; color:#f00; border:1px solid #777; border-radius:4px; font-size:26px; line-height:1; font-weight:900; cursor:pointer; overflow:hidden; box-sizing:border-box; }
.growreport-content button.problem-btn:hover { background:#f5f5f5; }
.growreport-content button.problem-btn.is-marked { background:#000; border-color:#000; }
.growreport-content button.problem-btn .problem-mark { color:#f00; font-size:27px; line-height:1; font-weight:900; }
.growreport-content button.problem-btn:disabled { cursor:default; opacity:1; }

/* První den, počáteční datum a značení úplňků; nezasahuje do velikosti Growreportu ani fotek. */
.harvest-toolbar { display:flex; align-items:center; justify-content:center; gap:12px; flex-wrap:wrap; margin:12px auto 18px; }
.full-moon-toggle { padding:9px 16px; border:1px solid #b8860b; border-radius:6px; background:#fff8dc; color:#6b4f00; font-weight:700; cursor:pointer; }
.full-moon-toggle.active { background:#d4af37; color:#382900; box-shadow:0 0 0 2px #b8860b inset; }
/* Malé čtvercové tlačítko úplňku vedle Růst; původní hlavní tlačítko zůstává zachované. */
.growreport-header .full-moon-mini-btn { display:inline-flex !important; align-items:center !important; justify-content:center !important; width:30px !important; min-width:30px !important; height:30px !important; padding:0 !important; margin:0 4px !important; box-sizing:border-box !important; border:1px solid #b8860b !important; border-radius:4px !important; background:#fff8dc !important; color:#8a6800 !important; font-size:17px !important; line-height:1 !important; vertical-align:middle; cursor:pointer; }
.growreport-header .full-moon-mini-btn:hover { background:#ffef9c !important; }
.growreport-header .full-moon-mini-btn.active { background:#d4af37 !important; color:#382900 !important; box-shadow:inset 0 0 0 2px #b8860b !important; }
/* Hlavní ovládací tlačítka zůstávají pohromadě v jedné řadě: úplněk, nůžky, růst, květ a sušina. */
.growreport-header .growreport-header-actions { display:flex !important; align-items:center !important; justify-content:flex-end !important; gap:4px !important; flex-wrap:nowrap !important; white-space:nowrap !important; }
.growreport-header .growreport-header-actions > button { flex:0 0 auto; }
.full-moon-help { color:#666; font-size:12px; }
.full-moon-admin-panel { max-width:900px; margin:0 auto 20px; padding:14px 16px; border:1px solid #8bc34a; border-radius:8px; background:#f4faee; color:#222; box-sizing:border-box; }
.full-moon-admin-panel h3 { margin:0 0 6px; color:#2e7d32; }
.full-moon-admin-panel p { margin:4px 0 12px; font-size:13px; }
.full-moon-admin-add { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.full-moon-admin-add label { font-weight:700; }
.full-moon-admin-add input { padding:7px; border:1px solid #aaa; border-radius:4px; font:inherit; }
.full-moon-admin-add button, .full-moon-admin-row button { padding:7px 10px; border:0; border-radius:4px; background:#2e7d32; color:#fff; cursor:pointer; font-weight:600; }
.full-moon-admin-row button { background:#b71c1c; }
.full-moon-admin-message { margin-top:8px; font-size:13px; }
.full-moon-admin-list { max-height:210px; overflow:auto; margin-top:12px; padding-top:10px; border-top:1px solid #c5dfb3; }
.full-moon-admin-row { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:5px 0; border-bottom:1px solid #e0ecd8; font-size:13px; }
.full-moon-empty { color:#666; }
.growreport-content button.first-day-open-btn { margin:0 auto 4px !important; padding:0 !important; width:90% !important; height:36px !important; min-height:36px !important; max-width:90% !important; display:flex !important; flex-direction:column !important; align-items:center !important; justify-content:center !important; box-sizing:border-box !important; border:1px solid #2e7d32 !important; border-radius:4px !important; background:#e8f5e9 !important; color:#2e7d32 !important; font-size:9px !important; line-height:1.15 !important; font-weight:600 !important; white-space:nowrap !important; cursor:pointer; }

/* Galerie týdne */
.growreport-header .gallery-btn { margin:0 4px !important; padding:5px 9px !important; border:1px solid #1565c0 !important; border-radius:4px !important; background:#e3f2fd !important; color:#0d47a1 !important; font-size:12px !important; font-weight:700 !important; cursor:pointer; white-space:nowrap; }
.growreport-header .gallery-btn:hover { background:#bbdefb !important; }
.growreport-header .week-zero-mini-btn { display:inline-flex !important; align-items:center !important; justify-content:center !important; width:30px !important; min-width:30px !important; height:30px !important; padding:0 !important; margin:0 4px !important; box-sizing:border-box !important; border:1px solid #0d47a1 !important; border-radius:4px !important; background:#1565c0 !important; color:#4caf50 !important; font-size:19px !important; line-height:1 !important; font-weight:900 !important; cursor:pointer; }
.growreport-header .week-zero-mini-btn:hover { background:#0d47a1 !important; }
.growreport-header .week-zero-mini-btn:disabled { opacity:.65; cursor:wait; }
/* Tlačítko U na každém týdnu: stejný modrý čtverec se zeleným znakem jako tlačítko týdne 0. */
.growreport-header .save-week-mini-btn { display:inline-flex !important; align-items:center !important; justify-content:center !important; width:30px !important; min-width:30px !important; height:30px !important; padding:0 !important; margin:0 4px !important; box-sizing:border-box !important; border:1px solid #0d47a1 !important; border-radius:4px !important; background:#1565c0 !important; color:#4caf50 !important; font-size:19px !important; line-height:1 !important; font-weight:900 !important; cursor:pointer; }
.growreport-header .save-week-mini-btn:hover { background:#0d47a1 !important; }
.growreport-header .save-week-mini-btn:disabled { opacity:.65; cursor:wait; }
/* Přímý přístup k automatickému hnojení; plán se zapisuje do běžné tabulky Hnojeno. */
.growreport-header .auto-fert-header-btn { display:inline-flex !important; align-items:center !important; justify-content:center !important; width:30px !important; min-width:30px !important; height:30px !important; padding:0 !important; margin:0 4px !important; box-sizing:border-box !important; border:1px solid #6d4c41 !important; border-radius:4px !important; background:#efebe9 !important; color:#2e7d32 !important; font-size:17px !important; line-height:1 !important; cursor:pointer; }
.growreport-header .auto-fert-header-btn:hover { background:#d7ccc8 !important; }
.gallery-modal-backdrop { position:fixed; inset:0; z-index:13000; display:none; align-items:center; justify-content:center; padding:14px; background:rgba(0,0,0,.62); box-sizing:border-box; }
.gallery-modal { width:100%; max-width:900px; max-height:92vh; overflow:auto; border-radius:10px; background:#fff; color:#222; box-shadow:0 10px 40px #0006; }
.gallery-modal-header { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 18px; background:#1565c0; color:#fff; }
.gallery-modal-header h3 { margin:0; font-size:19px; }
.gallery-modal-close { border:0; background:transparent; color:#fff; font-size:26px; cursor:pointer; line-height:1; }
.gallery-modal-body { padding:16px; }
.gallery-upload-row { display:flex; align-items:center; gap:10px; flex-wrap:wrap; padding:12px; margin-bottom:12px; background:#f3f7fc; border:1px solid #c5d9f1; border-radius:7px; }
.gallery-upload-row input[type=file] { max-width:100%; }
.gallery-upload-row button,.gallery-item-delete { border:0; border-radius:5px; padding:8px 12px; cursor:pointer; font-weight:700; }
.gallery-upload-row button { background:#1565c0; color:white; }
.gallery-upload-row button:disabled { opacity:.55; cursor:wait; }
.gallery-status { margin:8px 0; color:#444; font-size:13px; white-space:pre-wrap; }
.gallery-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:12px; }
.gallery-item { min-width:0; padding:8px; border:1px solid #ddd; border-radius:7px; background:#fff; overflow:hidden; }
.gallery-item img,.gallery-item video { display:block; width:100%; height:120px; object-fit:contain; background:#111; border-radius:4px; }
.gallery-photo-wrap { position:relative; width:100%; min-width:0; }
.gallery-photo-wrap img { cursor:zoom-in; }
.gallery-photo-zoom-btn { position:absolute; top:5px; right:5px; z-index:5; width:30px; height:30px; display:inline-flex; align-items:center; justify-content:center; padding:0; margin:0; border:1px solid rgba(255,255,255,.8); border-radius:5px; background:rgba(0,0,0,.78); color:#fff; font-size:20px; line-height:1; cursor:pointer; box-shadow:0 1px 5px rgba(0,0,0,.35); }
.gallery-photo-zoom-btn:hover { background:rgba(0,0,0,.95); }
.gallery-item-name { margin:7px 0; font-size:12px; overflow-wrap:anywhere; }
.gallery-item-delete { width:100%; background:#b71c1c; color:#fff; font-size:12px; }
.gallery-empty { grid-column:1/-1; padding:18px; text-align:center; color:#777; border:1px dashed #bbb; border-radius:7px; }
/* Dvě nahratelné fotografie uvnitř původního třetího fotopolíčka – rozměry ostatních sekcí zůstávají beze změny. */
.growreport-content .section3-photo-cell { overflow:hidden !important; padding:0 !important; }
.growreport-content .section3-photo-stack { display:flex !important; flex-direction:column !important; align-items:stretch !important; justify-content:stretch !important; gap:1px; width:100%; height:100%; min-height:0; border:0 !important; overflow:hidden !important; }
.growreport-content .section3-photo-slot { position:relative; display:flex !important; flex:1 1 50%; flex-direction:column !important; align-items:center !important; justify-content:center !important; width:100%; min-height:0; border:0 !important; overflow:hidden !important; box-sizing:border-box; }
.growreport-content .section3-photo-slot label { top:12% !important; font-size:10px !important; z-index:2; }
.growreport-content .section3-photo-slot input[type=file] { inset:0; z-index:3; width:100%; height:100%; }
.growreport-content .section3-photo-slot img:not(.preview-image) { display:block; max-width:100%; max-height:100%; width:100%; height:100%; object-fit:contain; }
.growreport-content .section3-photo-slot .preview-image { max-width:100%; max-height:100%; width:100%; height:100%; object-fit:contain; margin:0; }
.growreport-content .section3-photo-slot p { margin:0; font-size:10px; color:#888; }
.growreport-content .section3-photo-slot .compression-info { position:absolute; left:2px; right:2px; bottom:1px; font-size:8px; line-height:1; z-index:4; }
@media(max-width:600px) { .gallery-modal-body{padding:10px}.gallery-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.growreport-header .gallery-btn{font-size:10px!important;padding:5px 6px!important} }
.first-day-readonly-label { padding:6px 4px; text-align:center; font-weight:700; color:#555; }
.calendar-modal-backdrop { position:fixed; inset:0; z-index:12000; display:none; align-items:center; justify-content:center; padding:14px; background:rgba(0,0,0,.58); box-sizing:border-box; }
.calendar-modal { width:100%; max-width:620px; max-height:90vh; overflow:auto; border-radius:10px; background:#fff; color:#222; box-shadow:0 10px 40px #0005; }
.calendar-modal-header { padding:12px 20px; background:#2e7d32; color:#fff; text-align:center; }
.calendar-modal-header h3 { margin:0; font-size:20px; }
.calendar-modal-body { padding:16px 20px; }
.calendar-config-section { margin:0 0 16px; padding:12px; border:1px solid #d7e3d5; border-radius:7px; background:#fbfdf9; }
.calendar-config-section h4 { margin:0 0 10px; color:#2e7d32; }
.calendar-config-section > label { display:block; margin-bottom:6px; font-weight:600; }
.calendar-config-section input[type="date"] { width:100%; max-width:260px; padding:9px; border:1px solid #aaa; border-radius:4px; font:inherit; box-sizing:border-box; }
.calendar-weekday-options { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px 14px; }
.calendar-weekday-options label { display:flex; align-items:center; gap:8px; margin:0; padding:7px 8px; border:1px solid #e0e0e0; border-radius:5px; background:#fff; cursor:pointer; }
.calendar-weekday-options input { width:18px; height:18px; accent-color:#2e7d32; }
.calendar-hint { margin:8px 0 0; color:#666; font-size:12px; }
.calendar-config-message { display:none; margin-top:10px; padding:8px; border-radius:5px; white-space:pre-wrap; font-size:13px; }
.growreport-content .day { flex-direction:column !important; gap:1px; padding:1px 0; box-sizing:border-box; }
.growreport-content .day .calendar-day-name { display:block; position:static !important; transform:none !important; color:#333 !important; font-size:9px !important; font-weight:700 !important; line-height:11px !important; white-space:nowrap; }
.growreport-content .day .calendar-day-date { display:block; position:static !important; transform:none !important; color:#555 !important; font-size:7px !important; font-weight:600 !important; line-height:8px !important; white-space:nowrap; letter-spacing:-.15px; }
/* Při najetí myší se zvětší jen den a datum. Rozložení buněk zůstává stejné. */
.growreport-content .week-metrics-grid { overflow:visible !important; }
.growreport-content .calendar-days-column { overflow:visible !important; position:relative; z-index:1; }
.growreport-content .calendar-days-column:hover { z-index:1000 !important; }
.growreport-content .calendar-days-column [id^="days-"] { overflow:visible !important; }
.growreport-content .calendar-days-column .day { position:relative; z-index:1; transform-origin:center center; transition:transform .15s ease, box-shadow .15s ease; }
.growreport-content .calendar-days-column .day:hover { transform:scale(1.4); z-index:1001; box-shadow:0 4px 12px rgba(0,0,0,.28); cursor:default; }
/* Aktuální den má trvalý stříbrný obrys; zaznamenaný výtok svítí červeně. */
.growreport-content .calendar-days-column .day.today-day { outline:4px solid #e6e6e6 !important; outline-offset:-4px !important; z-index:3; }
.growreport-content .calendar-days-column .day.runoff-day { box-shadow:inset 0 0 0 3px #ff1744, 0 0 9px 2px rgba(255,23,68,.95), 0 0 17px rgba(255,23,68,.65) !important; }
.growreport-content .calendar-days-column .day.today-day.runoff-day { outline:4px solid #e6e6e6 !important; outline-offset:-4px !important; }

/* Denní přehled vody a hnojiv – modrý při čekajícím úkolu, světle zelený po potvrzení. */
.daily-tasks-section { width:100%; margin:14px 0 18px; padding:12px; box-sizing:border-box; border:1px solid #b6d3eb; border-radius:8px; background:#f7fbff; }
.daily-tasks-section h3 { margin:0 0 10px; color:#174d7a; font-size:16px; }
.daily-tasks-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(235px,1fr)); gap:10px; }
.daily-task-card { min-width:0; display:flex; flex-direction:column; gap:7px; padding:8px; box-sizing:border-box; border:1px solid #1565c0; border-radius:7px; background:#e3f2fd; }
.daily-task-card .daily-task-text { display:block; width:100%; min-height:42px; max-height:105px; height:auto; padding:5px; box-sizing:border-box; border:1px solid #64a6df; border-radius:5px; background:#e3f2fd; color:#123b5b; font:10px/1.25 Arial,sans-serif; white-space:pre-wrap; overflow-wrap:anywhere; overflow-x:hidden; overflow-y:scroll !important; scrollbar-gutter:stable; }
.daily-task-card .daily-task-day { display:block; font-weight:800; margin:0 0 2px; }
.daily-task-card .daily-task-details { white-space:pre-wrap; overflow-wrap:anywhere; }
.daily-task-card .daily-task-entry { display:block; margin-top:5px; padding:6px 7px; border:1px solid #64a6df; border-radius:5px; background:#fff; color:#123b5b; white-space:pre-wrap; overflow-wrap:anywhere; font:10px/1.3 Arial,sans-serif; }
.daily-task-card.completed .daily-task-entry { border-color:#81c784; background:#f4fbf4; color:#1b5e20; }
.daily-task-complete-btn { width:100%; min-height:36px; padding:8px 10px; border:1px solid #1565c0; border-radius:5px; background:#1976d2; color:#fff; font-weight:700; cursor:pointer; }
.daily-task-complete-btn:disabled { opacity:.7; cursor:wait; }
.daily-action-task-list { display:flex; flex-direction:column; gap:5px; margin-top:2px; }
.daily-action-task { display:flex; flex-direction:column; gap:5px; padding:7px; border:1px solid #64a6df; border-radius:5px; background:#fff; }
.daily-action-task.completed { border-color:#81c784; background:#f4fbf4; }
.daily-action-task-note { display:block; width:100%; box-sizing:border-box; color:#123b5b; font:10px/1.3 Arial,sans-serif; font-weight:700; white-space:pre-wrap; overflow-wrap:anywhere; }
.daily-action-task-button { width:100%; min-height:36px; padding:8px 10px; border:1px solid #1565c0; border-radius:5px; background:#1976d2; color:#fff; font-weight:700; cursor:pointer; }
.daily-action-task-button.completed { border-color:#66bb6a; background:#c8e6c9; color:#1b5e20; }
.daily-action-task-button:disabled { opacity:.7; cursor:not-allowed; }

.daily-task-card.completed { border-color:#66bb6a; background:#e8f5e9; }
.daily-task-card.completed .daily-task-text { border-color:#81c784; background:#e8f5e9; color:#1b5e20; }
.daily-task-card.completed .daily-task-complete-btn { border-color:#66bb6a; background:#c8e6c9; color:#1b5e20; }
@media(max-width:600px) { .daily-tasks-section { padding:8px; } .daily-tasks-grid { grid-template-columns:1fr; } }
/* Denní plány jsou umístěny přímo pod původním textovým polem a tlačítkem Uložit text. */
.growreport-content > .week-notes-cell { display:flex !important; flex-direction:column !important; align-items:stretch !important; justify-content:flex-start !important; gap:8px !important; min-width:0 !important; overflow:visible !important; }
.growreport-content > .week-notes-cell > textarea[id^="weekText-"] { width:100% !important; min-height:115px !important; height:130px !important; flex:0 0 auto !important; box-sizing:border-box !important; }
.growreport-content > .week-notes-cell > .save-week-text-btn { align-self:flex-start; flex:0 0 auto; position:relative; z-index:3; }
.growreport-content > .week-notes-cell > .save-text-status { flex:0 0 auto; text-align:left; font-size:12px; overflow-wrap:anywhere; white-space:pre-wrap; }
.growreport-content > .week-notes-cell .daily-tasks-section { display:block; width:100%; max-width:100%; max-height:440px; overflow:auto !important; margin:4px 0 0; padding:8px; box-sizing:border-box; }
.growreport-content > .week-notes-cell .daily-tasks-grid { display:grid !important; grid-template-columns:minmax(0,1fr) !important; gap:8px; width:100%; }
.growreport-content > .week-notes-cell .daily-task-card { display:flex; flex-direction:column; align-items:stretch; justify-content:flex-start; gap:7px; min-width:0; width:100%; box-sizing:border-box; overflow:visible; border:1px solid #1565c0; border-radius:7px; padding:8px; }
.growreport-content > .week-notes-cell .daily-task-card .daily-task-text { display:block; width:100%; min-height:42px; height:auto; max-height:105px; resize:none; flex:0 0 auto; overflow-y:scroll !important; scrollbar-gutter:stable; }
.growreport-content > .week-notes-cell .daily-task-complete-btn { min-height:34px; flex:0 0 auto; }
#save-growreport-debug { display:none; margin:0 auto 12px; width:min(100%,1000px); padding:10px 12px; box-sizing:border-box; border:1px solid #90caf9; border-radius:6px; background:#e3f2fd; color:#0d47a1; font:13px/1.45 monospace; white-space:pre-wrap; overflow-wrap:anywhere; }
#save-growreport-debug.error { display:block; background:#ffebee; border-color:#ef9a9a; color:#b71c1c; }
#save-growreport-debug.success { display:block; background:#e8f5e9; border-color:#a5d6a7; color:#1b5e20; }


.growreport-header .pruning-mini-btn { display:inline-flex !important; align-items:center !important; justify-content:center !important; width:30px !important; min-width:30px !important; height:30px !important; padding:0 !important; margin:0 4px !important; box-sizing:border-box !important; border:1px solid #aeb4bc !important; border-radius:4px !important; background:linear-gradient(145deg,#fff,#c7ccd2) !important; color:#c62828 !important; font-size:18px !important; line-height:1 !important; vertical-align:middle; cursor:pointer; }
.growreport-header .pruning-mini-btn:hover { background:linear-gradient(145deg,#fff,#b5bbc3) !important; }
.growreport-header .custom-functions-mini-btn { display:inline-flex !important; align-items:center !important; justify-content:center !important; width:30px !important; min-width:30px !important; height:30px !important; padding:0 !important; margin:0 4px !important; box-sizing:border-box !important; border:1px solid #7e57c2 !important; border-radius:4px !important; background:linear-gradient(145deg,#fff,#e1bee7) !important; color:#4a148c !important; font-size:17px !important; font-weight:800 !important; line-height:1 !important; cursor:pointer; }
.growreport-header .custom-functions-mini-btn:hover { background:#ce93d8 !important; }
.custom-function-settings { display:grid; grid-template-columns:1fr; gap:9px; margin:0 0 12px; }
.custom-function-setting { display:grid; grid-template-columns:90px minmax(0,1fr) minmax(145px,.8fr); align-items:center; gap:8px; padding:9px; border:1px solid #ddd; border-radius:6px; background:#fafafa; }
.custom-function-setting input,.custom-function-setting select { min-width:0; width:100%; padding:8px; border:1px solid #aaa; border-radius:4px; box-sizing:border-box; font:inherit; }
.custom-functions-dialog .pruning-dialog-body > label { margin-top:10px; }
.custom-functions-dialog .pruning-dialog-body > select { display:block; }
.custom-function-history { width:100%; border-collapse:collapse; font-size:13px; margin-top:10px; }
.custom-function-history th,.custom-function-history td { border:1px solid #ddd; padding:7px; text-align:left; }
.custom-function-history th { background:#f0f1f3; }
@media(max-width:520px) { .custom-function-setting { grid-template-columns:1fr; } }
.growreport-header .vz-mini-btn { display:inline-flex !important; align-items:center !important; justify-content:center !important; width:30px !important; min-width:30px !important; height:30px !important; padding:0 !important; margin:0 4px !important; box-sizing:border-box !important; border:1px solid #9ccc65 !important; border-radius:4px !important; background:#dcedc8 !important; color:#1565c0 !important; font-size:12px !important; line-height:1 !important; font-weight:800 !important; letter-spacing:-.5px; cursor:pointer; }
.growreport-header .vz-mini-btn:hover { background:#c5e1a5 !important; }
.day.vz-day.vz-day:not(.full-moon-day) { background:#123d73 !important; color:#fff !important; border:1px solid #0b2d57 !important; box-shadow:inset 0 0 0 1px #315f91; }
.day.vz-day.vz-day:not(.full-moon-day) .calendar-day-name, .day.vz-day.vz-day:not(.full-moon-day) .calendar-day-date { color:#fff !important; }
.day.pruning-day { position:relative; }
.day.harvest-day:not(.full-moon-day) { background:#e53935 !important; color:#fff !important; border:1px solid #b71c1c !important; box-shadow:inset 0 0 0 1px #ef9a9a; }
.day.harvest-day:not(.full-moon-day) .calendar-day-name, .day.harvest-day:not(.full-moon-day) .calendar-day-date { color:#fff !important; }
/* Celý čtvereček dne s úkonem je světle zelený; samotný malý indikátor už není potřeba. */
.day.pruning-day:not(.full-moon-day) { background:#dcedc8 !important; color:#245b2a !important; border:1px solid #9ccc65 !important; box-shadow:inset 0 0 0 1px #c5e1a5; }
.day.pruning-day:not(.full-moon-day) .calendar-day-name, .day.pruning-day:not(.full-moon-day) .calendar-day-date { color:#245b2a !important; }
.day .pruning-mark { display:none !important; }
/* Defoliace má vlastní tmavě zelené zvýraznění dne. */
.day.defoliation-day { background:#39ff14 !important; background-image:none !important; color:#124000 !important; border:1px solid #20b800 !important; box-shadow:inset 0 0 0 1px #7cff62 !important; }
.day.defoliation-day *, .day.defoliation-day .calendar-day-name, .day.defoliation-day .calendar-day-date { color:#124000 !important; }
.day.defoliation-day::before, .day.defoliation-day::after { background:#39ff14 !important; }
.growreport-header .spray-action-stack { display:flex !important; flex-direction:column !important; align-items:center !important; justify-content:center !important; gap:1px !important; flex:0 0 auto !important; margin:0 2px !important; white-space:nowrap; }
.growreport-header .spray-mini-btn { display:inline-flex !important; align-items:center !important; justify-content:center !important; width:30px !important; min-width:30px !important; height:30px !important; padding:0 !important; margin:0 !important; box-sizing:border-box !important; border:1px solid #6d4c41 !important; border-radius:4px !important; background:#8d6e63 !important; color:#fff8e1 !important; font-size:17px !important; line-height:1 !important; cursor:pointer; }
.growreport-header .spray-mini-btn:hover { background:#6d4c41 !important; }
.growreport-header .net-mini-btn { display:inline-flex !important; align-items:center !important; justify-content:center !important; width:36px !important; min-width:36px !important; height:17px !important; padding:0 2px !important; margin:0 !important; box-sizing:border-box !important; border:1px solid #64b5f6 !important; border-radius:3px !important; background:#e3f2fd !important; color:#0d47a1 !important; font-size:9px !important; line-height:1 !important; font-weight:700 !important; cursor:pointer; }
.growreport-header .net-mini-btn:hover { background:#bbdefb !important; }
.day.spray-day:not(.full-moon-day):not(.net-day) { background:#efdfd3 !important; color:#5d4037 !important; border:1px solid #bcaaa4 !important; box-shadow:inset 0 0 0 1px #d7ccc8; }
.day.spray-day:not(.full-moon-day):not(.net-day) .calendar-day-name, .day.spray-day:not(.full-moon-day):not(.net-day) .calendar-day-date { color:#5d4037 !important; }
.day.net-day:not(.full-moon-day) { background:#d9efff !important; color:#0d47a1 !important; border:1px solid #90caf9 !important; box-shadow:inset 0 0 0 1px #bbdefb; }
.day.net-day:not(.full-moon-day) .calendar-day-name, .day.net-day:not(.full-moon-day) .calendar-day-date { color:#0d47a1 !important; }
.spray-dialog-backdrop { position:fixed; inset:0; z-index:13500; display:none; align-items:center; justify-content:center; padding:14px; background:rgba(0,0,0,.60); box-sizing:border-box; }
.spray-dialog { width:100%; max-width:760px; max-height:92vh; overflow:auto; border-radius:10px; background:#fff; color:#222; box-shadow:0 10px 40px #0005; }
.spray-dialog-header { padding:12px 20px; background:#8d6e63; color:#fff; text-align:center; }
.spray-dialog-header h3 { margin:0; font-size:20px; }
.spray-dialog-body { padding:16px 20px; }
.spray-dialog-body label { display:block; font-weight:700; margin:0 0 6px; }
.spray-dialog-body select, .spray-dialog-body input[type=date], .spray-dialog-body input[type=text], .spray-dialog-body input[type=number] { width:100%; max-width:100%; padding:8px; margin:0; border:1px solid #aaa; border-radius:4px; font:inherit; box-sizing:border-box; }
.spray-date-select { margin-bottom:14px !important; }
.spray-choice-row { display:grid; grid-template-columns:minmax(0,1fr) 120px; align-items:end; gap:10px; padding:10px 0; border-bottom:1px solid #eee; }
.spray-choice-row .spray-field { min-width:0; }
.spray-choice-row .spray-field label { font-size:12px; margin-bottom:4px; }
.spray-choice-row input[type=number] { padding:7px; }
.spray-history-list { margin-top:10px; overflow-x:auto; }
.spray-history-table { width:100%; border-collapse:collapse; font-size:13px; }
.spray-history-table th,.spray-history-table td { border:1px solid #d7ccc8; padding:7px; text-align:left; vertical-align:top; }
.spray-history-table th { background:#efdfd3; color:#5d4037; }
.spray-history-delete { width:25px; height:25px; border:0; border-radius:4px; background:#c62828; color:#fff; font-size:17px; line-height:1; cursor:pointer; }
.spray-message { font-size:13px; margin-top:8px; min-height:18px; }
.net-dialog-backdrop { position:fixed; inset:0; z-index:13500; display:none; align-items:center; justify-content:center; padding:14px; background:rgba(0,0,0,.60); box-sizing:border-box; }
.net-dialog { width:100%; max-width:560px; max-height:90vh; overflow:auto; border-radius:10px; background:#fff; color:#222; box-shadow:0 10px 40px #0005; }
.net-dialog-header { padding:12px 20px; background:#90caf9; color:#0d47a1; text-align:center; }
.net-dialog-header h3 { margin:0; font-size:20px; }
.net-dialog-body { padding:16px 20px; }
.net-dialog-body label { display:block; font-weight:700; margin:0 0 6px; }
.net-dialog-body select { width:100%; padding:9px; border:1px solid #aaa; border-radius:4px; font:inherit; box-sizing:border-box; margin-bottom:12px; }
.net-checkbox-row { display:flex !important; align-items:center; gap:8px; margin:8px 0 12px !important; }
.net-checkbox-row input { width:18px; height:18px; }
.net-history-list { display:grid; gap:6px; margin-top:10px; }
.net-history-item { display:flex; justify-content:space-between; align-items:center; gap:8px; border:1px solid #90caf9; border-radius:5px; background:#e3f2fd; padding:7px 8px; font-size:13px; }
.net-history-delete { border:0; border-radius:3px; background:#c62828; color:#fff; font-size:16px; cursor:pointer; padding:2px 8px; }
@media(max-width:600px) { .spray-choice-row { grid-template-columns:1fr; gap:6px; } .spray-dialog-body,.net-dialog-body { padding:12px; } }
.pruning-dialog-backdrop { position:fixed; inset:0; z-index:13000; display:none; align-items:center; justify-content:center; padding:14px; background:rgba(0,0,0,.58); box-sizing:border-box; }
.pruning-dialog { width:100%; max-width:620px; max-height:90vh; overflow:auto; border-radius:10px; background:#fff; color:#222; box-shadow:0 10px 40px #0005; }
.pruning-dialog-header { padding:12px 20px; background:#bfc5cc; color:#8b0000; text-align:center; }
.pruning-dialog-header h3 { margin:0; font-size:20px; }
.pruning-dialog-body { padding:16px 20px; }
.pruning-dialog-body label { display:block; font-weight:700; margin:0 0 6px; }
.pruning-dialog-body select { width:100%; max-width:100%; padding:9px; margin-bottom:12px; border:1px solid #aaa; border-radius:4px; font:inherit; box-sizing:border-box; }
.pruning-history { width:100%; border-collapse:collapse; font-size:13px; margin-top:12px; }
.pruning-history th,.pruning-history td { border:1px solid #ddd; padding:7px; text-align:left; }
.pruning-history th { background:#f0f1f3; }
.pruning-history-delete { width:25px; height:25px; border:0; border-radius:4px; background:#c62828; color:#fff; font-size:17px; line-height:1; cursor:pointer; }
.pruning-history-delete:hover { background:#a71919; }
.pruning-message { font-size:13px; margin-top:8px; }
.vz-dialog-backdrop { position:fixed; inset:0; z-index:13600; display:none; align-items:center; justify-content:center; padding:14px; background:rgba(0,0,0,.60); box-sizing:border-box; }
.vz-dialog { width:100%; max-width:640px; max-height:92vh; overflow:auto; border-radius:10px; background:#fff; color:#222; box-shadow:0 10px 40px #0005; }
.vz-dialog-header { padding:12px 20px; background:#dcedc8; color:#0d47a1; text-align:center; }
.vz-dialog-header h3 { margin:0; font-size:20px; }
.vz-dialog-body { padding:16px 20px; }
.vz-dialog-body label { display:block; font-weight:700; margin:0 0 6px; }
.vz-date-select, .vz-type-select { width:100%; padding:10px; margin:0 0 14px; border:1px solid #123d73; border-radius:5px; background:#123d73; color:#fff; font:inherit; box-sizing:border-box; }
.vz-type-select { background:#fff; color:#222; border-color:#aaa; }
.vz-hours-picker-wrap { display:none; flex-direction:column; align-items:center; gap:8px; padding:8px 0 14px; }
.vz-hours-picker-wrap.visible { display:flex !important; }
#vz-dialog #vz-hours-picker-wrap[style*="display: flex"] { display:flex !important; }
.vz-hours-picker-label { text-align:center; color:#123d73; font-weight:700; }
#vz-hours-picker { width:76px; height:120px; border-color:#1565C0; }
.vz-anchor-option { display:flex !important; align-items:center; justify-content:center; gap:9px; margin:2px auto 10px !important; padding:9px 11px; border:1px solid #b9d5a0; border-radius:6px; background:#f1f8e9; color:#234d20; font-size:13px; line-height:1.35; cursor:pointer; }
.vz-anchor-option input { width:18px; height:18px; margin:0; accent-color:#2e7d32; flex:0 0 auto; }
.vz-history { width:100%; border-collapse:collapse; font-size:13px; margin-top:12px; }
.vz-history th,.vz-history td { border:1px solid #ddd; padding:7px; text-align:left; }
.vz-history th { background:#e8eef5; }
.vz-history-delete { width:25px; height:25px; border:0; border-radius:4px; background:#c62828; color:#fff; font-size:17px; line-height:1; cursor:pointer; }
.vz-message { font-size:13px; margin-top:8px; }
@media(max-width:600px) { .vz-dialog-body { padding:12px; } .vz-dial { width:164px; height:164px; } }
.day.full-moon-day { background:#ffd700 !important; color:#5b4200 !important; border:1px solid #b8860b !important; font-weight:800 !important; box-shadow:inset 0 0 0 1px #f3c623; }
.day.full-moon-day .calendar-day-name, .day.full-moon-day .calendar-day-date { color:#5b4200 !important; }
@media(max-width:600px) { .calendar-modal-body{padding:12px}.calendar-weekday-options{grid-template-columns:1fr}.full-moon-admin-panel{margin-left:8px;margin-right:8px} }

            /* Graf a statistiky týdnů */
        .growreport-graph-nav-btn { display:inline-flex; align-items:center; gap:6px; margin-left:8px; padding:7px 12px; border:0; border-radius:5px; background:#1565c0; color:#fff; font:600 15px 'Montserrat',sans-serif; cursor:pointer; white-space:nowrap; }
        .growreport-graph-nav-btn:hover { background:#0d47a1; }
        nav ul li .growreport-graph-nav-btn { margin:0; }
        .gr-graph-overlay { position:fixed; inset:0; z-index:16000; display:none; align-items:flex-start; justify-content:center; padding:14px; overflow:auto; box-sizing:border-box; background:rgba(0,0,0,.68); }
        .gr-graph-panel { width:100%; max-width:1400px; max-height:calc(100vh - 28px); overflow:auto; box-sizing:border-box; background:#f7f9fc; border-radius:12px; box-shadow:0 12px 50px #0006; color:#263238; }
        .gr-graph-head { position:sticky; top:0; z-index:2; display:flex; justify-content:space-between; align-items:center; gap:12px; padding:14px 18px; background:#2e7d32; color:#fff; border-radius:12px 12px 0 0; }
        .gr-graph-head h2 { margin:0; font-size:22px; }
        .gr-graph-close { border:0; border-radius:5px; padding:8px 13px; background:#fff; color:#222; font-weight:700; cursor:pointer; }
        .gr-graph-body { padding:16px; }
        .gr-graph-intro { margin:0 0 12px; color:#546e7a; font-size:13px; }
        .gr-graph-controls { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:12px; }
        .gr-graph-controls button { border:1px solid #2e7d32; border-radius:5px; padding:7px 12px; background:#e8f5e9; color:#1b5e20; font-weight:700; cursor:pointer; }
        .gr-graph-filters { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:8px; margin-bottom:16px; padding:12px; background:#fff; border:1px solid #dce4e7; border-radius:8px; }
        .gr-graph-filter { display:flex; align-items:center; gap:8px; font-size:13px; }
        .gr-graph-filter input { width:17px; height:17px; }
        .gr-graph-chart-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(min(100%,420px),1fr)); gap:14px; }
        .gr-graph-chart-card { background:#fff; border:1px solid #dce4e7; border-radius:8px; padding:12px; min-width:0; }
        .gr-graph-chart-card h3 { margin:0 0 8px; font-size:15px; color:#37474f; }
        .gr-graph-canvas-wrap { height:245px; position:relative; }
        .gr-graph-empty { color:#777; background:#fff; padding:20px; border-radius:8px; text-align:center; }
        .gr-week-summary-heading { margin:24px 0 10px; color:#263238; }
        .gr-week-summary-list { display:flex; flex-direction:column; gap:9px; }
        .gr-week-summary-card { --phase:#78909c; background:#fff; border:1px solid #dce4e7; border-left:7px solid var(--phase); border-radius:7px; overflow:hidden; }
        .gr-week-summary-phasebar { height:5px; background:var(--phase); }
        .gr-week-summary-inner { padding:10px 12px; }
        .gr-week-summary-top { display:flex; flex-wrap:wrap; align-items:center; gap:8px 12px; margin-bottom:8px; }
        .gr-week-summary-top strong { font-size:16px; }
        .gr-week-phase-label { font-size:11px; text-transform:uppercase; letter-spacing:.04em; padding:3px 7px; border-radius:12px; background:#eef2f3; color:#37474f; font-weight:700; }
        .gr-flower-duration { color:#ad2222; font-weight:700; font-size:12px; }
        .gr-week-summary-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:5px 12px; font-size:12px; color:#455a64; }
        .gr-week-summary-stats span strong { color:#263238; }
        .gr-week-summary-stats .gr-week-summary-value { color:#1565c0; font-weight:700; }
        .gr-graph-error { display:none; white-space:pre-wrap; padding:10px; margin:8px 0; background:#ffebee; color:#b71c1c; border:1px solid #e57373; border-radius:6px; font-size:13px; }
        @media(max-width:650px){ .gr-graph-overlay{padding:5px}.gr-graph-panel{max-height:calc(100vh - 10px)}.gr-graph-body{padding:9px}.gr-graph-head h2{font-size:17px}.gr-graph-canvas-wrap{height:220px}.gr-graph-chart-grid{grid-template-columns:1fr} }



/* Sjednocení ovládacího čtverečku „První den“ s denními políčky CO2/teploty/vlhkosti/světel. */
.growreport-content .calendar-days-column > .first-day-open-btn,
.growreport-content .calendar-days-column > .first-day-readonly-label {
  display: flex !important;
  flex: 0 0 36px !important;
  align-items: center !important;
  justify-content: center !important;
  width: 90% !important;
  min-width: 0 !important;
  max-width: 90% !important;
  height: 36px !important;
  min-height: 36px !important;
  max-height: 36px !important;
  margin: 0 auto 4px !important;
  padding: 0 !important;
  box-sizing: border-box !important;
  border: 1px solid #2e7d32 !important;
  border-radius: 4px !important;
  background: #e8f5e9 !important;
  color: #2e7d32 !important;
  font-size: 9px !important;
  line-height: 1.15 !important;
  font-weight: 600 !important;
  white-space: nowrap !important;
}
.growreport-content .calendar-days-column > .first-day-open-btn { cursor: pointer !important; }
.growreport-content .calendar-days-column > .first-day-open-btn:hover { background: #dcedc8 !important; }
.growreport-content .calendar-days-column > .first-day-readonly-label { text-align: center !important; }
/* Posun datových čtverečků přibližně o 1 mm dolů pro srovnání s ostatními denními údaji. */
.growreport-content .calendar-days-column > div[id^="days-"] {
  margin-top: 1mm !important;
}


/* Názvy se zalamují až nad 160 znaků; text se nikdy nezkracuje ani neukončuje třemi tečkami. */
.report-header h2.report-title-short {
    max-width: min(96vw, 1400px);
    margin-left: auto;
    margin-right: auto;
    white-space: nowrap;
    overflow: visible;
    text-overflow: clip;
    line-height: 1.18;
    display: block;
}
.report-header h2.report-title-long {
    max-width: min(96vw, 1400px);
    margin-left: auto;
    margin-right: auto;
    white-space: normal;
    overflow-wrap: normal;
    line-height: 1.18;
    display: block;
    overflow: visible;
}
.growreport-header {
    height: auto !important;
    min-height: 1cm;
    box-sizing: border-box;
    gap: 8px;
}
/* Název týdne: do 160 znaků je vždy na jednom řádku; delší se zobrazí ve dvou řádcích. */
.growreport-header > span[id^="week-title-"] {
    flex: 1 1 auto;
    min-width: 0;
    max-width: none;
    min-height: 1em;
    line-height: 1.15;
    overflow: visible;
    text-align: left !important;
    margin-left: 0 !important;
    margin-right: auto !important;
}
.growreport-header > span[id^="week-title-"].week-title-short {
    display: block;
    white-space: nowrap !important;
    overflow: visible !important;
    text-overflow: clip !important;
    overflow-wrap: normal !important;
    -webkit-line-clamp: unset !important;
    -webkit-box-orient: initial !important;
}
.growreport-header > span[id^="week-title-"].week-title-long {
    display: block;
    white-space: normal !important;
    overflow: visible !important;
    overflow-wrap: normal !important;
    -webkit-line-clamp: unset !important;
    -webkit-box-orient: initial !important;
}

/* Veřejný náhled: editační ovladače zůstávají viditelné, ale jsou zřetelně neaktivní. */
body.growreport-public-view button:disabled,
body.growreport-public-view input:disabled,
body.growreport-public-view .growreport-header-actions button:disabled {
    filter: grayscale(1) !important;
    opacity: 0.48 !important;
    cursor: not-allowed !important;
    box-shadow: none !important;
}
body.growreport-public-view button:disabled:hover {
    transform: none !important;
    box-shadow: none !important;
}

/* DEFINITIVNÍ PŘEDNOST PRO DEFOLIACI: celý denní čtverec musí zůstat neonově zelený,
   i když se ve stejný den překrývá úplněk, V/Z, postřik nebo jiný úkon. */
.day.defoliation-day,
.day.defoliation-day.full-moon-day,
.day.defoliation-day.vz-day,
.day.defoliation-day.pruning-day,
.day.defoliation-day.spray-day,
.day.defoliation-day.net-day {
    background: #39ff14 !important;
    background-color: #39ff14 !important;
    background-image: none !important;
    color: #103b00 !important;
    border: 2px solid #20b800 !important;
    box-shadow: inset 0 0 0 2px #7cff62 !important;
    opacity: 1 !important;
}
.day.defoliation-day *,
.day.defoliation-day .calendar-day-name,
.day.defoliation-day .calendar-day-date {
    color: #103b00 !important;
    opacity: 1 !important;
}
.day.defoliation-day::before,
.day.defoliation-day::after {
    background: #39ff14 !important;
    background-color: #39ff14 !important;
    opacity: 1 !important;
}



/* FINÁLNÍ STYL DEFOLIACE: plná neonově zelená plocha a čitelný den i datum. */
.growreport-content .calendar-days-column .day.defoliation-day,
.growreport-content .calendar-days-column .day.defoliation-day.full-moon-day,
.growreport-content .calendar-days-column .day.defoliation-day.vz-day,
.growreport-content .calendar-days-column .day.defoliation-day.pruning-day,
.growreport-content .calendar-days-column .day.defoliation-day.spray-day,
.growreport-content .calendar-days-column .day.defoliation-day.net-day {
    position: relative !important;
    isolation: isolate !important;
    background: #39ff14 !important;
    background-color: #39ff14 !important;
    background-image: none !important;
    color: #102900 !important;
    border: 1px solid #168000 !important;
    box-shadow: none !important;
    opacity: 1 !important;
}
.growreport-content .calendar-days-column .day.defoliation-day::before,
.growreport-content .calendar-days-column .day.defoliation-day::after {
    content: none !important;
    display: none !important;
    background: transparent !important;
    box-shadow: none !important;
}
.growreport-content .calendar-days-column .day.defoliation-day .calendar-day-name,
.growreport-content .calendar-days-column .day.defoliation-day .calendar-day-date,
.growreport-content .calendar-days-column .day.defoliation-day > * {
    position: relative !important;
    z-index: 2 !important;
    display: block !important;
    color: #102900 !important;
    opacity: 1 !important;
    visibility: visible !important;
    text-shadow: none !important;
}

</style>


</head>
<body<?= !$canEdit ? ' class="growreport-public-view"' : '' ?>>
<header>
    <div class="header-container">
        <h1><i class="fas fa-seedling"></i> Growreports.cz - Detail Growreportu</h1>
        <nav><?php include('menu.php'); ?>
            ></nav>
    </div>
</header>

<main>
    <div class="report-header">
        <?php
        // Celý název ponecháme. Do 160 znaků je v jednom řádku; delší rozdělíme uprostřed mezi slovy.
        $reportTitleOriginal = (string)($growreport['name'] ?? '');
        $reportTitle = $reportTitleOriginal;
        $reportTitleLength = function_exists('mb_strlen') ? mb_strlen($reportTitle, 'UTF-8') : strlen($reportTitle);
        $reportTitleFirstLine = $reportTitle;
        $reportTitleSecondLine = '';
        if ($reportTitleLength > 160) {
            $splitAt = (int)floor($reportTitleLength / 2);
            $beforeSplit = function_exists('mb_substr') ? mb_substr($reportTitle, 0, $splitAt, 'UTF-8') : substr($reportTitle, 0, $splitAt);
            $space = function_exists('mb_strrpos') ? mb_strrpos($beforeSplit, ' ', 0, 'UTF-8') : strrpos($beforeSplit, ' ');
            if ($space !== false && $space >= (int)floor($splitAt * 0.6)) {
                $splitAt = $space;
            } else {
                $nextSpace = function_exists('mb_strpos') ? mb_strpos($reportTitle, ' ', $splitAt, 'UTF-8') : strpos($reportTitle, ' ', $splitAt);
                if ($nextSpace !== false) $splitAt = $nextSpace;
            }
            $reportTitleFirstLine = trim(function_exists('mb_substr')
                ? mb_substr($reportTitle, 0, $splitAt, 'UTF-8')
                : substr($reportTitle, 0, $splitAt));
            $reportTitleSecondLine = trim(function_exists('mb_substr')
                ? mb_substr($reportTitle, $splitAt, null, 'UTF-8')
                : substr($reportTitle, $splitAt));
        }
        $reportTitleFontSize = $reportTitleLength > 80 ? '16px' : 'inherit';
        ?>
        <h2 class="<?= $reportTitleLength > 160 ? 'report-title-long' : 'report-title-short' ?>" style="font-size:<?= htmlspecialchars($reportTitleFontSize, ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($reportTitleOriginal, ENT_QUOTES, 'UTF-8') ?>"><?php if ($reportTitleLength > 160): ?><?= htmlspecialchars($reportTitleFirstLine, ENT_QUOTES, 'UTF-8') ?><br><?= htmlspecialchars($reportTitleSecondLine, ENT_QUOTES, 'UTF-8') ?><?php else: ?><?= htmlspecialchars($reportTitle, ENT_QUOTES, 'UTF-8') ?><?php endif; ?></h2>
        <p><strong>Autor:</strong> <?= htmlspecialchars($growreport['author']) ?> | <strong>Vytvořeno:</strong> <?= date('d.m.Y H:i', strtotime($growreport['created_at'])) ?></p>
    </div>

    <div class="harvest-toolbar">
        <button type="button" id="full-moon-toggle" class="full-moon-toggle" onclick="toggleFullMoonHarvest()">🌕 Sklizeň podle úplňku</button>
        <span id="full-moon-help" class="full-moon-help">Po stisknutí se dny s úplňkem označí zlatě.</span>
    </div>

    <?php if ($isAdmin && !$isPublicView): ?>
    <section class="full-moon-admin-panel" aria-labelledby="full-moon-admin-title">
        <h3 id="full-moon-admin-title">Administrace úplňků</h3>
        <p>Zadej alespoň jedno známé datum úplňku jako referenci. Další úplňky se dopočítají automaticky podle průměrného lunárního cyklu 29,53 dne (ne 28 dní). Můžeš také přidávat další přesná data jako opravy pro libovolné měsíce a roky. Tato administrace je dostupná pouze administrátorovi.</p>
        <div class="full-moon-admin-add">
            <label for="full-moon-date">Datum úplňku</label>
            <input type="date" id="full-moon-date">
            <button type="button" onclick="addFullMoonDate()">Přidat úplněk</button>
        </div>
        <div id="full-moon-admin-message" role="status" class="full-moon-admin-message"></div>
        <div class="full-moon-admin-list">
            <strong>Uložené úplňky</strong>
            <?php if (!$fullMoonRows): ?>
                <p class="full-moon-empty">Zatím nejsou zadána žádná data úplňků.</p>
            <?php else: ?>
                <?php foreach ($fullMoonRows as $moonRow): $moonTs = strtotime($moonRow['moon_date']); ?>
                    <div class="full-moon-admin-row">
                        <?php $czMonths = [1=>'leden',2=>'únor',3=>'březen',4=>'duben',5=>'květen',6=>'červen',7=>'červenec',8=>'srpen',9=>'září',10=>'říjen',11=>'listopad',12=>'prosinec']; ?>
                        <span><?= htmlspecialchars($czMonths[(int)date('n', $moonTs)] . ' ' . date('Y', $moonTs), ENT_QUOTES, 'UTF-8') ?> — <?= date('d.m.Y', $moonTs) ?></span>
                        <button type="button" onclick="deleteFullMoonDate(<?= (int)$moonRow['id'] ?>)" aria-label="Smazat úplněk <?= date('d.m.Y', $moonTs) ?>">Smazat</button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <div id="growreport-container" data-id="<?= (int)$growreport['id'] ?>">
        <?php $flowerWeekCounter = 0; foreach ($weeks as $week):
            $wn = (int)$week['week_number'];
            $weekPhase = trim((string)($week['phase'] ?? ''));
            // Nový týden 0 má při vytvoření modrou lištu; po výběru běžné fáze používá její standardní barvu.
            $bg = ($wn === 0 && $weekPhase === '') ? '#1565c0' : (!empty($week['phase_color']) ? $week['phase_color'] : 'white');
            $isFlowerWeek = in_array($weekPhase, ['KVĚT', 'Květ', 'květ'], true);
            if ($isFlowerWeek) $flowerWeekCounter++;
            $flowerIndex = $isFlowerWeek ? $flowerWeekCounter : 0;
            // Délku určujeme podle celého viditelného textu řádku týdne. Zalamování nastává až nad 160 znaků.
            $weekTitlePrefix = 'Týden ' . $wn . ($isFlowerWeek ? ' K' . $flowerIndex . ' 🌼' : '') . ' - ';
            $weekTitleDetails = trim((string)($growreport['name'] ?? '')) . ' - ' . trim((string)($growreport['author'] ?? ''));
            $weekTitlePlainText = $weekTitlePrefix . $weekTitleDetails;
            $weekTitleLength = function_exists('mb_strlen') ? mb_strlen($weekTitlePlainText, 'UTF-8') : strlen($weekTitlePlainText);
            $weekTitleClass = $weekTitleLength > 160 ? 'week-title-long' : 'week-title-short';
            $weekTitleDetailsFirstLine = $weekTitleDetails;
            $weekTitleDetailsSecondLine = '';
            if ($weekTitleLength > 160) {
                $prefixLength = function_exists('mb_strlen') ? mb_strlen($weekTitlePrefix, 'UTF-8') : strlen($weekTitlePrefix);
                $detailsLength = function_exists('mb_strlen') ? mb_strlen($weekTitleDetails, 'UTF-8') : strlen($weekTitleDetails);
                $splitAt = max(1, (int)floor($detailsLength / 2));
                $beforeSplit = function_exists('mb_substr') ? mb_substr($weekTitleDetails, 0, $splitAt, 'UTF-8') : substr($weekTitleDetails, 0, $splitAt);
                $space = function_exists('mb_strrpos') ? mb_strrpos($beforeSplit, ' ', 0, 'UTF-8') : strrpos($beforeSplit, ' ');
                if ($space !== false && $space >= (int)floor($splitAt * 0.6)) {
                    $splitAt = $space;
                } else {
                    $nextSpace = function_exists('mb_strpos') ? mb_strpos($weekTitleDetails, ' ', $splitAt, 'UTF-8') : strpos($weekTitleDetails, ' ', $splitAt);
                    if ($nextSpace !== false) $splitAt = $nextSpace;
                }
                $weekTitleDetailsFirstLine = trim(function_exists('mb_substr') ? mb_substr($weekTitleDetails, 0, $splitAt, 'UTF-8') : substr($weekTitleDetails, 0, $splitAt));
                $weekTitleDetailsSecondLine = trim(function_exists('mb_substr') ? mb_substr($weekTitleDetails, $splitAt, null, 'UTF-8') : substr($weekTitleDetails, $splitAt));
            }
            $weekTitleFontSize = $weekTitleLength > 160 ? '11px' : ($weekTitleLength > 120 ? '10px' : ($weekTitleLength > 80 ? '12px' : '18px'));
        ?>
            <div class="growreport" data-week="<?= $wn ?>" data-phase="<?= htmlspecialchars($weekPhase, ENT_QUOTES, 'UTF-8') ?>">
                <div class="growreport-header" style="background-color: <?= htmlspecialchars($bg) ?>">
                    <span id="week-title-<?= $wn ?>" class="<?= $weekTitleClass ?>" style="font-size:<?= htmlspecialchars($weekTitleFontSize, ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($weekTitlePlainText, ENT_QUOTES, 'UTF-8') ?>">
                        Týden <?= $wn ?> <span class="flower-week-marker" id="flower-week-marker-<?= $wn ?>" style="display:<?= $isFlowerWeek ? 'inline-flex' : 'none' ?>"><?= $isFlowerWeek ? 'K' . $flowerIndex . ' 🌼' : '' ?></span> -
                        <?php if ($weekTitleLength > 160): ?><?= htmlspecialchars($weekTitleDetailsFirstLine, ENT_QUOTES, 'UTF-8') ?><br><?= htmlspecialchars($weekTitleDetailsSecondLine, ENT_QUOTES, 'UTF-8') ?><?php else: ?><?= htmlspecialchars($weekTitleDetails, ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
                    </span>
                    <div class="growreport-header-actions<?= $canEdit ? '' : ' growreport-header-actions-readonly' ?>">
                        <?php if ($wn === 1 && !$hasWeekZero): ?>
                        <button type="button" id="add-week-zero" class="week-zero-mini-btn" title="Vytvořit týden 0 před týdnem 1" aria-label="Vytvořit týden nula" <?= $canEdit ? '' : 'disabled aria-disabled="true"' ?> onclick="addWeekZero()">0</button>
                        <?php endif; ?>
                        <button type="button" class="save-week-mini-btn" title="Uložit celý Growreport" aria-label="Uložit celý Growreport" <?= $canEdit ? '' : 'disabled aria-disabled="true"' ?> onclick="saveGrowreport()">U</button>
                        <button type="button" class="custom-functions-mini-btn" title="Tři vlastní funkce a barevné označení dnů" aria-label="Vlastní funkce T" <?= $canEdit ? '' : 'disabled aria-disabled="true"' ?> onclick="openCustomFunctionsDialog(<?= $wn ?>)">T</button>
                        <button type="button" class="custom-functions-mini-btn" style="border-color:#78909c !important;background:linear-gradient(145deg,#fff,#cfd8dc) !important;color:#263238 !important" title="Spotřeba a cena elektřiny" aria-label="Elektřina E" onclick="window.openElectricityDialog()">E</button>
                        <button type="button" class="custom-functions-mini-btn" style="border-color:#2e7d32 !important;background:linear-gradient(145deg,#fff,#c8e6c9) !important;color:#1b5e20 !important" title="Audit optimálního osvětlení a proudění vzduchu" aria-label="Audit A" <?= $canEdit ? '' : 'disabled aria-disabled="true"' ?> onclick="window.openGrowAudit()">A</button>
                        <button type="button" class="gallery-btn"  title="Galerie fotografií a videí tohoto týdne" onclick="try { var gd=document.getElementById('gallery-dialog'); var gs=document.getElementById('gallery-status'); if (gd) gd.style.display='flex'; if (gs) gs.textContent='DEBUG GALERIE: kliknutí funguje; týden <?= $wn ?>.'; if (typeof window.debugOpenGallery === 'function') { window.debugOpenGallery(<?= $wn ?>, this); } else if (typeof window.openGallery === 'function') { window.openGallery(<?= $wn ?>); } else { if (gs) gs.textContent += ' Funkce openGallery není dostupná; pravděpodobně došlo k chybě JavaScriptu.'; alert(gs ? gs.textContent : 'DEBUG GALERIE: chybí dialog galerie.'); } } catch(e) { console.error('DEBUG GALERIE – kliknutí:', e); alert('DEBUG GALERIE: ' + (e && e.message ? e.message : e)); } return false;">🖼️ Galerie</button>
                        <button type="button" class="auto-fert-header-btn" title="Nastavit automatické hnojení a zapsat ho do Growreportu" aria-label="Automatické hnojení" <?= $canEdit ? '' : 'disabled aria-disabled="true"' ?> onclick="openAutoFertilizerForWeek(<?= $wn ?>)">🧪</button>
                        <button type="button" class="gallery-btn" title="Otevřít graf a statistiky tohoto growreportu" onclick="openGrowreportGraph()" aria-haspopup="dialog">📊 Graf</button>
                        <button type="button" class="pruning-mini-btn" aria-label="Zastřižení, FIM a LST" title="Zaznamenat zastřižení, FIM nebo LST" <?= $canEdit ? '' : 'disabled aria-disabled="true"' ?> onclick="openPruningDialog(<?= $wn ?>)">✂</button>
                        <button type="button" class="vz-mini-btn" aria-label="Vanička nebo zvlhčovač" title="Evidence vaničky nebo zvlhčovače" <?= $canEdit ? '' : 'disabled aria-disabled="true"' ?> onclick="openVzDialog(<?= $wn ?>)">V/Z</button>
                        <div class="spray-action-stack">
                         <button type="button" class="spray-mini-btn" aria-label="Postřik" title="Zaznamenat postřik" <?= $canEdit ? '' : 'disabled aria-disabled="true"' ?> onclick="openSprayDialog(<?= $wn ?>)"><svg aria-hidden="true" viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 7h7l2 3v11H6V9l2-2Z" fill="#f5eee8"/><path d="M8 7V4h7l2 3M15 4h4M19 4l2-2M19 5l2 1M18 7l2 2"/><path d="M9 11h5" stroke="#8d6e63"/><path d="M3 3l1 .7M2 6l1 .2M5 1l.2 1" stroke="#f5eee8"/></svg></button>
                         <button type="button" class="net-mini-btn" title="Označit den se sítí" <?= $canEdit ? '' : 'disabled aria-disabled="true"' ?> onclick="openNetDialog(<?= $wn ?>)">Síť</button>
                        </div>
                        <button type="button" class="full-moon-mini-btn" aria-label="Sklizeň podle úplňku" aria-pressed="false" title="Sklizeň podle úplňku" onclick="toggleFullMoonHarvest()">🌕</button>
                        <button class="growth-btn" <?= $canEdit ? '' : 'disabled aria-disabled="true"' ?> onclick="setColor(<?= $wn ?>, 'RŮST', 'green')">📈Růst</button>
                        <button class="flower-btn" <?= $canEdit ? '' : 'disabled aria-disabled="true"' ?> onclick="setColor(<?= $wn ?>, 'KVĚT', 'red')">🌼Květ</button>
                        <button class="dry-btn" <?= $canEdit ? '' : 'disabled aria-disabled="true"' ?> onclick="setColor(<?= $wn ?>, 'SUŠINA', 'pink')">🌿Sušina</button>
                        <button class="delete-week-btn" type="button" title="Smazat tento týden" <?= $canEdit ? '' : 'disabled aria-disabled="true"' ?> onclick="showDeleteWeekConfirmation(<?= $wn ?>)">✕</button>
                    </div>
                </div>

                <div class="growreport-content">
                    <?php for ($p = 1; $p <= 3; $p++):
                        $photo = $week['photo' . $p];
                    ?>
                    <div<?= $p === 3 ? ' class="section3-photo-cell"' : '' ?>>
                        <?php if ($p === 3): ?>
                            <div class="section3-photo-stack">
                                <div class="section3-photo-slot">
                                    <label for="file-input-<?= $wn ?>-3">Sekce 3 – foto 1</label>
                                    <input id="file-input-<?= $wn ?>-3" type="file" accept="image/*" <?= $canEdit ? '' : 'disabled aria-disabled="true"' ?> onchange="previewImage(event, this)">
                                    <?php if (!empty($photo) && file_exists($photo)): ?>
                                        <img src="<?= htmlspecialchars($photo) ?>" alt="Foto 3" style="display:block;">
                                    <?php else: ?>
                                        <p style="color:#888;"><i class="fas fa-image"></i> Foto 1</p>
                                    <?php endif; ?>
                                    <img class="preview-image" id="preview-<?= $wn ?>-3" src="#" alt="Náhled foto 1">
                                    <button type="button" class="photo-zoom-btn" id="photo-zoom-btn-<?= $wn ?>-3" title="Zvětšit fotografii" aria-label="Zvětšit fotografii 3" onclick="openPhotoZoom(this)" style="<?= (!empty($photo) && file_exists($photo)) ? 'display:inline-flex;' : 'display:none;' ?>">⛶</button>
                                    <div class="compression-info" id="compression-info-<?= $wn ?>-3"></div>
                                </div>
                                <div class="section3-photo-slot">
                                    <label for="file-input-<?= $wn ?>-4">Sekce 3 – foto 2</label>
                                    <input id="file-input-<?= $wn ?>-4" type="file" accept="image/*" <?= $canEdit ? '' : 'disabled aria-disabled="true"' ?> onchange="previewImage(event, this)">
                                    <?php if (!empty($week['photo4']) && file_exists($week['photo4'])): ?>
                                        <img src="<?= htmlspecialchars($week['photo4']) ?>" alt="Foto 4" style="display:block;">
                                    <?php else: ?>
                                        <p style="color:#888;"><i class="fas fa-image"></i> Foto 2</p>
                                    <?php endif; ?>
                                    <img class="preview-image" id="preview-<?= $wn ?>-4" src="#" alt="Náhled foto 2">
                                    <button type="button" class="photo-zoom-btn" id="photo-zoom-btn-<?= $wn ?>-4" title="Zvětšit fotografii" aria-label="Zvětšit fotografii 4" onclick="openPhotoZoom(this)" style="<?= (!empty($week['photo4']) && file_exists($week['photo4'])) ? 'display:inline-flex;' : 'display:none;' ?>">⛶</button>
                                    <div class="compression-info" id="compression-info-<?= $wn ?>-4"></div>
                                </div>
                            </div>
                        <?php else: ?>
                            <label for="file-input-<?= $wn ?>-<?= $p ?>">Sem Vložte Foto</label>
                            <input id="file-input-<?= $wn ?>-<?= $p ?>" type="file" accept="image/*" <?= $canEdit ? '' : 'disabled aria-disabled="true"' ?> onchange="previewImage(event, this)">
                            <?php if (!empty($photo) && file_exists($photo)): ?>
                                <img src="<?= htmlspecialchars($photo) ?>" alt="Foto <?= $p ?>" style="display: block;">
                            <?php else: ?>
                                <p style="color: #888;"><i class="fas fa-image"></i> Žádná fotka</p>
                            <?php endif; ?>
                            <img class="preview-image" id="preview-<?= $wn ?>-<?= $p ?>" src="#" alt="Náhled">
                            <button type="button" class="photo-zoom-btn" id="photo-zoom-btn-<?= $wn ?>-<?= $p ?>" title="Zvětšit fotografii" aria-label="Zvětšit fotografii <?= $p ?>" onclick="openPhotoZoom(this)" style="<?= (!empty($photo) && file_exists($photo)) ? 'display:inline-flex;' : 'display:none;' ?>">⛶</button>
                            <div class="compression-info" id="compression-info-<?= $wn ?>-<?= $p ?>"></div>
                        <?php endif; ?>
                    </div>
                    <?php endfor; ?>

                    <div class="week-notes-cell">
                        <textarea id="weekText-<?= $wn ?>" <?= $canEdit ? '' : 'readonly' ?>><?= htmlspecialchars($week['text_content'] ?? '') ?></textarea>
                        <button type="button" class="save-week-text-btn" <?= $canEdit ? '' : 'disabled aria-disabled="true"' ?> onclick="saveText(<?= $wn ?>)">⏬Uložit text</button>
                        <div id="responseMessage-text-<?= $wn ?>" class="save-text-status" role="status" aria-live="polite"></div>
                    </div>
                <?php
                    $weekHasDailyTasks = false;
                    for ($taskDay = 1; $taskDay <= 7; $taskDay++) {
                        $taskWater = $week['watering'][$taskDay] ?? [];
                        $taskWaterHasData = false;
                        foreach ($taskWater as $taskAmount) if (is_numeric($taskAmount) && (float)$taskAmount > 0) { $taskWaterHasData = true; break; }
                        $taskFertilizerItems = $week['fertilizers'][$taskDay]['items'] ?? [];
                        $taskHasFertilizer = !empty($taskFertilizerItems) || in_array($taskDay, $week['hnojenoDays'] ?? [], true);
                        if ($taskWaterHasData || $taskHasFertilizer) $weekHasDailyTasks = true;
                    }
                ?>
                <section class="daily-tasks-section" id="daily-tasks-section-<?= $wn ?>" style="<?= $weekHasDailyTasks ? 'display:flex;' : 'display:none;' ?>">
                    <div class="daily-tasks-grid">
                        <?php for ($taskDay = 1; $taskDay <= 7; $taskDay++):
                            $taskWater = $week['watering'][$taskDay] ?? [];
                            $taskFertilizerItems = $week['fertilizers'][$taskDay]['items'] ?? [];
                            $taskFertilized = in_array($taskDay, $week['hnojenoDays'] ?? [], true);
                            $taskWaterHasData = false;
                            foreach ($taskWater as $taskAmount) if (is_numeric($taskAmount) && (float)$taskAmount > 0) { $taskWaterHasData = true; break; }
                            $taskHasData = $taskWaterHasData || !empty($taskFertilizerItems) || $taskFertilized;
                            $taskCompleted = !empty($dailyTaskCompleted[(int)$week['id']][$taskDay]);
                            $globalTaskDay = (($wn - 1) * 7) + $taskDay;
                        ?>
                            <div class="daily-task-card<?= $taskCompleted ? ' completed' : '' ?>" id="daily-task-card-<?= $wn ?>-<?= $taskDay ?>" data-week="<?= $wn ?>" data-day="<?= $taskDay ?>" data-completed="<?= $taskCompleted ? '1' : '0' ?>" data-has-data="<?= $taskHasData ? '1' : '0' ?>" style="<?= $taskHasData ? '' : 'display:none;' ?>">
                                <?php $taskTextLines = explode("\n", vg2_daily_task_text($globalTaskDay, $taskWater, $taskFertilizerItems, $taskFertilized, $potNamesByNumber)); ?>
                                <div class="daily-task-text" id="daily-task-text-<?= $wn ?>-<?= $taskDay ?>" role="note" tabindex="0">
                                    <strong class="daily-task-day"><?= htmlspecialchars(array_shift($taskTextLines) ?? '', ENT_QUOTES, 'UTF-8') ?></strong>
                                    <?php if (!empty($taskTextLines)): ?><div class="daily-task-details"><?= htmlspecialchars(implode("\n", $taskTextLines), ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                                </div>
                                <?php if ($canEdit): ?>
                                    <button type="button" class="daily-task-complete-btn" id="daily-task-button-<?= $wn ?>-<?= $taskDay ?>" aria-pressed="<?= $taskCompleted ? 'true' : 'false' ?>" onclick="toggleDailyTask(<?= $wn ?>, <?= $taskDay ?>)"><?= $taskCompleted ? '✓ Splněno — vrátit' : '☐ Splněno' ?></button>
                                <?php else: ?>
                                    <button type="button" class="daily-task-complete-btn" disabled><?= $taskCompleted ? '✓ Splněno' : '☐ Nesplněno' ?></button>
                                <?php endif; ?>
                            </div>
                            <div class="daily-action-task-host" id="daily-action-task-list-<?= $wn ?>-<?= $taskDay ?>" style="display:contents"></div>
                        <?php endfor; ?>
                    </div>
                </section>

                    <div class="week-metrics-grid" style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px;">
                        <!-- DNY -->
                        <div>
                            <textarea placeholder="Sekce 1: Text" readonly>Dny</textarea>
                            <div>
                                <?php for ($i = 1; $i <= 7; $i++):
                                    $c = $week['co2'][$i] ?? null;
                                    $co2DayStatus = vg2_co2_status($c['value1'] ?? null, $c['value2'] ?? null);
                                    $hasCo2 = $co2DayStatus['stripe'];
                                    $hasWater = in_array($i, $week['zalitoDays']);
                                    $lightStripeColors = vg2_lighting_stripe_colors($week['lightsByDay'][$i] ?? []);
                                ?>
                                    <div id="day-box-<?= $wn ?>-<?= $i ?>" class="day-box">
                                        <div id="day-zalito-<?= $wn ?>-<?= $i ?>" class="day-zalito" <?= $hasWater ? 'style="background-color: blue;"' : '' ?>></div>
                                        <div id="day-hnojeno-<?= $wn ?>-<?= $i ?>" class="day-hnojeno" <?= in_array($i, $week['hnojenoDays']) ? 'style="background-color: brown;"' : '' ?>></div>
                                        <div id="day-co2-<?= $wn ?>-<?= $i ?>" class="day-co2<?= $hasCo2 ? ' on' : '' ?>"></div>
                                        <?php if (!empty($lightStripeColors)): ?>
                                            <div id="day-lights-<?= $wn ?>-<?= $i ?>" class="day-lights" aria-label="Barva pruhu světel">
                                                <?php foreach ($lightStripeColors as $lightStripeColor): ?>
                                                    <span style="background-color: <?= htmlspecialchars($lightStripeColor, ENT_QUOTES, 'UTF-8') ?>;"></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                        <span><?= $wn * 7 - (7 - $i) ?></span>
                                    </div>
                                <?php endfor; ?>
                            </div>
                        </div>

                        <!-- ZALITO (tlačítka) -->
                        <div>
                            <textarea placeholder="Sekce 2: Text" readonly <?= $canEdit ? 'onclick="openPotsConfig()"' : '' ?>>Zalito</textarea>
                            <div>
                                <?php for ($i = 1; $i <= 7; $i++):
                                    $wTotal = $week['wateringTotals'][$i] ?? 0;
                                    $wTotalStr = ($wTotal == (int)$wTotal) ? (string)(int)$wTotal : number_format($wTotal, 1, '.', '');
                                ?>
                                    <div class="zalito-cell">
                                        <button type="button" class="zalito-btn" id="zal-<?= $wn ?>-<?= $i ?>"
                                                data-total="<?= $wTotalStr ?>"
                                                <?= $canEdit ? 'onclick="openWatering(' . $wn . ', ' . $i . ')"' : 'disabled' ?>>
                                            <?php if ($wTotal > 0): ?>
                                                <span class="zal-total <?= strlen($wTotalStr) > 2 ? 'small' : '' ?>"><?= $wTotalStr ?></span>
                                            <?php else: ?>
                                                <span class="zal-plus">+</span>
                                            <?php endif; ?>
                                        </button>
                                    </div>
                                <?php endfor; ?>
                            </div>
                        </div>

                        <!-- HNOJENO: stejný typ tlačítek jako Zalito; klik otevře výběr hnojiv -->
                        <div>
                            <textarea placeholder="Sekce 3: Text" readonly>Hnojeno</textarea>
                            <div>
                                <?php for ($i = 1; $i <= 7; $i++):
                                    $fTotal = (int)($week['fertilizers'][$i]['total_ml'] ?? 0);
                                    $fScoops = (int)($week['fertilizers'][$i]['total_scoops'] ?? 0);
                                    $fMelasaWater = (float)($week['fertilizers'][$i]['melasa_water_l'] ?? 0);
                                    $fMelasaTeaspoons = (int)($week['fertilizers'][$i]['melasa_teaspoons'] ?? 0);
                                    $fHasAny = $fTotal > 0 || $fScoops > 0 || $fMelasaWater > 0 || $fMelasaTeaspoons > 0;
                                ?>
                                    <div class="zalito-cell hnojeno-cell">
                                        <button type="button" class="hnojeno-btn" id="hnoj-<?= $wn ?>-<?= $i ?>"
                                                data-total="<?= $fTotal ?>" data-scoops="<?= $fScoops ?>" data-melasa-water="<?= htmlspecialchars((string)$fMelasaWater, ENT_QUOTES, 'UTF-8') ?>" data-melasa-teaspoons="<?= $fMelasaTeaspoons ?>"
                                                <?= $canEdit ? 'onclick="openFertilizer( ' . $wn . ', ' . $i . ' )"' : 'disabled' ?>>
                                            <?php if ($fHasAny): ?>
                                                <?php if ($fTotal > 0): ?><span class="hnoj-total <?= strlen((string)$fTotal) > 3 ? 'small' : '' ?>"><?= $fTotal ?> ml</span><?php endif; ?>
                                                <?php if ($fScoops > 0): ?><span class="hnoj-dry-total"><?= $fScoops ?></span><?php endif; ?>
                                                <?php if ($fMelasaTeaspoons > 0): ?><span class="hnoj-melasa-total">mel:<?= $fMelasaTeaspoons ?></span><?php endif; ?>
                                            <?php else: ?>
                                                <span class="hnoj-plus">+</span>
                                            <?php endif; ?>
                                        </button>
                                        <input type="checkbox" style="display:none" id="hnojeno-<?= $wn ?>-<?= $i ?>" value="<?= $i ?>" <?= in_array($i, $week['hnojenoDays']) ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
                                    </div>
                                <?php endfor; ?>
                            </div>
                        </div>

                        <!-- CO2 -->
                        <div>
                            <textarea placeholder="Sekce 4: Text" readonly>CO2</textarea>
                            <div>
                                <?php for ($i = 1; $i <= 7; $i++):
                                    $c = $week['co2'][$i] ?? null;
                                    $l1 = $c['label1'] ?? 'Ráno';
                                    $l2 = $c['label2'] ?? 'Večer';
                                    $v1 = ($c && $c['value1'] !== null) ? (string)$c['value1'] : '';
                                    $v2 = ($c && $c['value2'] !== null) ? (string)$c['value2'] : '';
                                    $co2Status = vg2_co2_status($v1, $v2);
                                ?>
                                    <div class="co2-cell">
                                        <button type="button" class="co2-btn" id="co2-<?= $wn ?>-<?= $i ?>"
                                                data-l1="<?= htmlspecialchars($l1, ENT_QUOTES) ?>" data-v1="<?= htmlspecialchars($v1, ENT_QUOTES) ?>"
                                                data-l2="<?= htmlspecialchars($l2, ENT_QUOTES) ?>" data-v2="<?= htmlspecialchars($v2, ENT_QUOTES) ?>"
                                                data-color="<?= htmlspecialchars($co2Status['color'], ENT_QUOTES) ?>" data-stripe="<?= $co2Status['stripe'] ? '1' : '0' ?>"
                                                <?= $canEdit ? 'onclick="openCo2(this, ' . $wn . ', ' . $i . ')"' : 'disabled' ?>>
                                            <?php if ($v1 === '' && $v2 === ''): ?>
                                                <span class="co2-plus">+</span>
                                            <?php else: ?>
                                                <?php if ($v1 !== ''): ?><span class="co2-line" style="color:<?= htmlspecialchars($co2Status['color'], ENT_QUOTES) ?>"><?= htmlspecialchars(mb_strtoupper(mb_substr($l1, 0, 1)) . $v1) ?></span><?php endif; ?>
                                                <?php if ($v2 !== ''): ?><span class="co2-line" style="color:<?= htmlspecialchars($co2Status['color'], ENT_QUOTES) ?>"><?= htmlspecialchars(mb_strtoupper(mb_substr($l2, 0, 1)) . $v2) ?></span><?php endif; ?>
                                            <?php endif; ?>
                                        </button>
                                    </div>
                                <?php endfor; ?>
                            </div>
                        </div>



                        <!-- PRVNÍ DEN: globální nastavení počátku kalendáře tohoto growreportu -->
                        <div class="calendar-days-column">
                            <button type="button" class="first-day-open-btn" <?= $canEdit ? '' : 'disabled aria-disabled="true"' ?> onclick="openCalendarSettings()">První den</button>
                            <input type="hidden" id="start-day-<?= $wn ?>" value="<?= htmlspecialchars($calendarFirstWeekday ?: ($week['start_day'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <div id="days-<?= $wn ?>"></div>
                            <div id="responseMessage-<?= $wn ?>"></div>
                        </div>

                    </div>

<!-- HLAVNÍ SLOUPEC 6: TEPLOTA + VLHKOST (vložená do stávajícího sloupce bez změny rozměrů Growreportu) -->
<div class="temp-humidity-section">
    <div class="temp-humidity-column">
        <textarea readonly>Teplota</textarea>
        <div class="temp-humidity-list">
            <?php for ($i = 1; $i <= 7; $i++):
                $t = $week['temperature'][$i] ?? null;
                $l1t = $t['label1'] ?? 'Ráno';
                $l2t = $t['label2'] ?? 'Večer';
                $v1t = ($t && $t['value1'] !== null) ? (string)(float)$t['value1'] : '';
                $v2t = ($t && $t['value2'] !== null) ? (string)(float)$t['value2'] : '';
            ?>
                <div class="co2-cell">
                    <button type="button" class="co2-btn" id="temp-<?= $wn ?>-<?= $i ?>"
                            data-l1="<?= htmlspecialchars($l1t, ENT_QUOTES) ?>"
                            data-v1="<?= htmlspecialchars($v1t, ENT_QUOTES) ?>"
                            data-l2="<?= htmlspecialchars($l2t, ENT_QUOTES) ?>"
                            data-v2="<?= htmlspecialchars($v2t, ENT_QUOTES) ?>"
                            <?= $canEdit ? 'onclick="openTemperature(this, ' . $wn . ', ' . $i . ')"' : 'disabled' ?>>
                        <?php if ($v1t === '' && $v2t === ''): ?>
                            <span class="co2-plus">+</span>
                        <?php else: ?>
                            <?php if ($v1t !== ''): $v1TempColor = ((float)$v1t >= 18 && (float)$v1t <= 38) ? '#2e7d32' : '#c62828'; ?><span class="co2-line" style="color:<?= $v1TempColor ?>"><?= htmlspecialchars(mb_strtoupper(mb_substr($l1t, 0, 1)) . $v1t . '°') ?></span><?php endif; ?>
                            <?php if ($v2t !== ''): $v2TempColor = ((float)$v2t >= 18 && (float)$v2t <= 38) ? '#2e7d32' : '#c62828'; ?><span class="co2-line" style="color:<?= $v2TempColor ?>"><?= htmlspecialchars(mb_strtoupper(mb_substr($l2t, 0, 1)) . $v2t . '°') ?></span><?php endif; ?>
                        <?php endif; ?>
                    </button>
                </div>
            <?php endfor; ?>
        </div>
    </div>
    <div class="temp-humidity-column">
        <textarea readonly>Vlhkost</textarea>
        <div class="temp-humidity-list">
            <?php for ($i = 1; $i <= 7; $i++):
                $h = $week['humidity'][$i] ?? null;
                $l1h = $h['label1'] ?? 'Ráno';
                $l2h = $h['label2'] ?? 'Večer';
                $v1h = ($h && $h['value1'] !== null) ? (string)(float)$h['value1'] : '';
                $v2h = ($h && $h['value2'] !== null) ? (string)(float)$h['value2'] : '';
            ?>
                <div class="co2-cell">
                    <button type="button" class="co2-btn humidity-btn" id="humidity-<?= $wn ?>-<?= $i ?>"
                            data-l1="<?= htmlspecialchars($l1h, ENT_QUOTES) ?>"
                            data-v1="<?= htmlspecialchars($v1h, ENT_QUOTES) ?>"
                            data-l2="<?= htmlspecialchars($l2h, ENT_QUOTES) ?>"
                            data-v2="<?= htmlspecialchars($v2h, ENT_QUOTES) ?>"
                            <?= $canEdit ? 'onclick="openHumidity(this, ' . $wn . ', ' . $i . ')"' : 'disabled' ?>>
                        <?php if ($v1h === '' && $v2h === ''): ?>
                            <span class="co2-plus">+</span>
                        <?php else: ?>
                            <?php if ($v1h !== ''): ?><span class="co2-line" style="color:<?= vg2_humidity_color($v1h) ?>"><?= htmlspecialchars(mb_strtoupper(mb_substr($l1h, 0, 1)) . $v1h . '%') ?></span><?php endif; ?>
                            <?php if ($v2h !== ''): ?><span class="co2-line" style="color:<?= vg2_humidity_color($v2h) ?>"><?= htmlspecialchars(mb_strtoupper(mb_substr($l2h, 0, 1)) . $v2h . '%') ?></span><?php endif; ?>
                        <?php endif; ?>
                    </button>
                </div>
            <?php endfor; ?>
        </div>
    </div>
    <div class="temp-humidity-column lights-column">
        <textarea readonly <?= $canEdit ? 'onclick="window.openLights(' . $wn . ', 1)"' : '' ?>>Světla</textarea>
        <div class="temp-humidity-list">
            <?php for ($i = 1; $i <= 7; $i++): ?>
                <?php $dayLights = $week['lightsByDay'][$i] ?? []; ?>
                <div class="co2-cell">
                    <button type="button" class="lights-btn" id="lights-<?= $wn ?>-<?= $i ?>"
                            <?= $canEdit ? 'onclick="window.openLights(' . $wn . ', ' . $i . ')"' : 'disabled' ?>
                            aria-label="Nastavení světel, týden <?= $wn ?>, den <?= $i ?>">
                        <?php if (!$dayLights): ?>
                            <span class="co2-plus">+</span>
                        <?php else: ?>
                            <?php foreach ($dayLights as $light):
                                $displayLightName = (($light['name'] ?? '') === 'Plazmová výbojka') ? 'Plazma' : (string)($light['name'] ?? '');
                            ?>
                                <span class="lights-name"><?= htmlspecialchars($displayLightName, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </button>
                </div>
            <?php endfor; ?>
        </div>
    </div>
    <div class="temp-humidity-column airflow-column">
        <textarea readonly>Vzduch</textarea>
        <div class="temp-humidity-list">
            <?php for ($i = 1; $i <= 7; $i++):
                $airflow = $week['airflowByDay'][$i] ?? ['fans' => [], 'exhausts' => []];
                $airflowFans = is_array($airflow['fans'] ?? null) ? $airflow['fans'] : [];
                $airflowExhausts = is_array($airflow['exhausts'] ?? null) ? $airflow['exhausts'] : [];
                $airflowConfigured = count($airflowFans) > 0 || count($airflowExhausts) > 0;
            ?>
                <div class="co2-cell">
                    <button type="button" class="airflow-btn" id="airflow-<?= $wn ?>-<?= $i ?>"
                            <?= $canEdit ? 'onclick="window.openAirflow(' . $wn . ', ' . $i . ')"' : 'disabled' ?>
                            aria-label="Nastavení větráků a odtahů, týden <?= $wn ?>, den <?= $i ?>">
                        <?php if (!$airflowConfigured): ?>
                            <span class="co2-plus">+</span>
                        <?php else: ?>
                            <span class="airflow-summary">Větráky: <?= count($airflowFans) ?></span>
                            <span class="airflow-summary">Odtah/přívod: <?= count($airflowExhausts) ?></span>
                        <?php endif; ?>
                    </button>
                </div>
            <?php endfor; ?>
        </div>
    </div>
    <div class="temp-humidity-column problem-column">
        <textarea readonly>Problém</textarea>
        <div class="temp-humidity-list">
            <?php for ($i = 1; $i <= 7; $i++): $problemMarked = in_array($i, $week['problemDays'] ?? [], true); ?>
                <div class="co2-cell">
                    <button type="button"
                            class="problem-btn<?= $problemMarked ? ' is-marked' : '' ?>"
                            id="problem-<?= $wn ?>-<?= $i ?>"
                            data-marked="<?= $problemMarked ? '1' : '0' ?>"
                            aria-label="Označit problém, týden <?= $wn ?>, den <?= $i ?>"
                            aria-pressed="<?= $problemMarked ? 'true' : 'false' ?>"
                            <?= $canEdit ? 'onclick="toggleProblemMark(this, ' . $wn . ', ' . $i . ')"' : 'disabled' ?>>
                        <?php if ($problemMarked): ?><span class="problem-mark" aria-hidden="true">!</span><?php endif; ?>
                    </button>
                </div>
            <?php endfor; ?>
        </div>
    </div>

</div>


                </div>
            </div>
        <?php endforeach; ?>
    </div>



    <button id="add-week" <?= $canEdit ? '' : 'disabled aria-disabled="true"' ?> onclick="addWeek()">📥Další týden</button>
    <div class="growreport-buttons">
        <button id="save-growreport" type="button" <?= $canEdit ? '' : 'disabled aria-disabled="true"' ?> onclick="saveGrowreport()">⏬Uložit Growreport</button>
        <button id="share-growreport" type="button" <?= $canEdit ? '' : 'disabled aria-disabled="true"' ?> onclick="shareGrowreport()">🌟Pochlub se</button>
        <button id="delete-growreport" type="button" <?= $canEdit ? '' : 'disabled aria-disabled="true"' ?> onclick="showDeleteConfirmation()">🗑️ Smazat growreport</button>
    </div>
    <?php if ($canEdit): ?><div id="save-growreport-debug" role="status" aria-live="polite"></div><?php endif; ?>
</main>

<!-- Graf a týdenní statistiky -->
<div id="growreport-graph-overlay" class="gr-graph-overlay" role="dialog" aria-modal="true" aria-labelledby="gr-graph-title">
  <div class="gr-graph-panel">
    <div class="gr-graph-head"><h2 id="gr-graph-title">Graf a statistiky Growreportu</h2><button type="button" class="gr-graph-close" onclick="closeGrowreportGraph()">Zavřít ✕</button></div>
    <div class="gr-graph-body">
      <p class="gr-graph-intro">Vyber jednu veličinu nebo několik najednou. Každá veličina má vlastní osu, aby se jednotky (°C, %, ppm, litry a ml) vzájemně nepřekrývaly. Průměry se počítají z uložených měření v daném týdnu.</p>
      <div class="gr-graph-controls"><button type="button" onclick="setGrowreportGraphSelection(true)">Vybrat všechna data</button><button type="button" onclick="setGrowreportGraphSelection(false)">Zrušit výběr</button></div>
      <div id="gr-graph-filters" class="gr-graph-filters"></div>
      <div id="gr-graph-error" class="gr-graph-error" role="alert"></div>
      <div id="gr-graph-chart-grid" class="gr-graph-chart-grid"></div>
      <h3 class="gr-week-summary-heading">Týdny – stručný přehled</h3>
      <div id="gr-week-summary-list" class="gr-week-summary-list"></div>
    </div>
  </div>
</div>

<!-- Delete growreport dialog -->
<div id="delete-confirmation" class="confirm-dialog-overlay">
    <div class="confirm-dialog">
        <div class="confirm-header"><i class="fas fa-exclamation-triangle"></i><h3>POZOR!</h3></div>
        <div class="confirm-content"><p>Opravdu chcete smazat tento growreport a všechna jeho data?</p><p><strong>Tato akce je nevratná!</strong></p></div>
        <div class="confirm-actions">
            <button id="cancel-delete" class="confirm-button confirm-cancel">Zrušit</button>
            <button id="confirm-delete" class="confirm-button confirm-delete">Smazat</button>
        </div>
    </div>

</div>

<!-- Delete week dialog -->
<div id="delete-week-confirmation" class="confirm-dialog-overlay">
    <div class="confirm-dialog">
        <div class="confirm-header"><i class="fas fa-exclamation-triangle"></i><h3>POZOR!</h3></div>
        <div class="confirm-content"><p>Opravdu chcete smazat <strong id="delete-week-label">tento týden</strong> včetně fotek, textu a zalití/hnojení?</p><p><strong>Tato akce je nevratná!</strong></p></div>
        <div class="confirm-actions">
            <button id="cancel-delete-week" class="confirm-button confirm-cancel">Zrušit</button>
            <button id="confirm-delete-week" class="confirm-button confirm-delete">Smazat týden</button>
        </div>


    </div>

</div>

<!-- CO2 dialog -->
<div id="co2-dialog" class="confirm-dialog-overlay">
    <div class="confirm-dialog" style="max-width: 400px;">
        <div class="co2-dialog-header"><h3 id="co2-dialog-title">CO2 (PPM)</h3></div>
        <div class="co2-dialog-body">
            <div class="co2-row">
                <input type="text" id="co2-label1" class="co2-label-input" maxlength="20" placeholder="Název (Ráno)">
                <input type="text" id="co2-value1" class="co2-value-input" maxlength="4" inputmode="numeric" placeholder="PPM">
            </div>
            <div class="co2-row">
                <input type="text" id="co2-label2" class="co2-label-input" maxlength="20" placeholder="Název (Večer)">
                <input type="text" id="co2-value2" class="co2-value-input" maxlength="4" inputmode="numeric" placeholder="PPM">
            </div>
            <div class="co2-hint">Název můžeš přejmenovat. Hodnota: max. 4 číslice.</div>
        </div>
        <div class="confirm-actions">
            <button type="button" id="co2-cancel" class="confirm-button confirm-cancel">Zrušit</button>
            <button type="button" id="co2-ok" class="confirm-button confirm-ok">OK</button>


        </div>
    </div>

</div>

<!-- TEPLOTA dialog -->
<div id="temp-dialog" class="confirm-dialog-overlay">
    <div class="confirm-dialog" style="max-width: 400px;">
        <div class="temp-dialog-header"><h3 id="temp-dialog-title">Teplota (°C)</h3></div>
        <div class="temp-dialog-body">
            <div class="temp-row">
                <input type="text" id="temp-label1" class="temp-label-input" maxlength="20" placeholder="Název (Ráno)">
                <input type="text" id="temp-value1" class="temp-value-input" inputmode="decimal" placeholder="°C">
            </div>
            <div class="temp-row">
                <input type="text" id="temp-label2" class="temp-label-input" maxlength="20" placeholder="Název (Večer)">
                <input type="text" id="temp-value2" class="temp-value-input" inputmode="decimal" placeholder="°C">
            </div>
            <div class="temp-hint">Název můžeš přejmenovat. Hodnota: -30 až 60, max. 1 desetinné místo.</div>
        </div>
        <div class="confirm-actions">
            <button type="button" id="temp-cancel" class="confirm-button confirm-cancel">Zrušit</button>
            <button type="button" id="temp-ok" class="confirm-button confirm-ok-blue">OK</button>
        </div>
    </div>
</div>

<!-- Vlhkost dialog -->
<div id="humidity-dialog" class="confirm-dialog-overlay">
    <div class="confirm-dialog" style="max-width: 400px;">
        <div class="humidity-dialog-header"><h3 id="humidity-dialog-title">Vlhkost (%)</h3></div>
        <div class="humidity-dialog-body">
            <div class="humidity-row">
                <input type="text" id="humidity-label1" class="humidity-label-input" maxlength="20" placeholder="Název (Ráno)">
                <input type="text" id="humidity-value1" class="humidity-value-input" inputmode="decimal" placeholder="%">
            </div>
            <div class="humidity-row">
                <input type="text" id="humidity-label2" class="humidity-label-input" maxlength="20" placeholder="Název (Večer)">
                <input type="text" id="humidity-value2" class="humidity-value-input" inputmode="decimal" placeholder="%">
            </div>
            <div class="humidity-hint">Zadej vlhkost 0 až 100 %, maximálně 1 desetinné místo.</div>
        </div>
        <div class="confirm-actions">
            <button type="button" id="humidity-cancel" class="confirm-button confirm-cancel">Zrušit</button>
            <button type="button" id="humidity-ok" class="confirm-button confirm-ok-blue">OK</button>
        </div>
    </div>
</div>

<!-- Nastavení světel -->
<div id="lights-dialog" class="confirm-dialog-overlay">
    <div class="confirm-dialog" style="max-width:700px;max-height:90vh;overflow:auto;">
        <div class="lights-dialog-header"><h3 id="lights-dialog-title">Světla</h3></div>
        <div class="lights-dialog-body">
            <div id="lights-step-select">
                <p>Vyber nejvýše 3 světla:</p>
                <div class="lights-pick-list" id="lights-pick-list"></div>
                <div class="lights-hint">Pro každé vybrané světlo se v dalším kroku nastaví vzdálenost, výkon a doba svícení.</div>
            </div>
            <div id="lights-step-values" style="display:none;">
                <div id="lights-values-form"></div>
                <div class="lights-hint">Vzdálenost 10–200 cm, výkon podle typu světla a doba svícení 1–24 hodin.</div>
            </div>
            <div id="lights-debug-message" role="alert" style="display:none;margin-top:12px;padding:8px;background:#fff3f3;color:#8b0000;border:1px solid #c62828;border-radius:5px;white-space:pre-wrap;font-size:12px;"></div>
        </div>
        <div class="confirm-actions">
            <button type="button" id="lights-cancel" class="confirm-button confirm-cancel">Zrušit</button>
            <button type="button" id="lights-back" class="confirm-button confirm-cancel" style="display:none;">Zpět</button>
            <button type="button" id="lights-next" class="confirm-button confirm-ok-blue">Pokračovat</button>
            <button type="button" id="lights-save" class="confirm-button confirm-ok-blue" style="display:none;">Uložit</button>
        </div>
    </div>
</div>

<!-- Nastavení větráků a odtahů -->
<div id="airflow-dialog" class="airflow-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="airflow-dialog-title">
    <div class="airflow-modal">
        <div class="airflow-dialog-header"><h3 id="airflow-dialog-title">Větráky a odtahy</h3></div>
        <div class="airflow-dialog-body">
            <div id="airflow-step-counts">
                <div class="airflow-counts">
                    <label for="airflow-fan-count">Počet větráků (1–10)
                        <select id="airflow-fan-count"><?php for ($n = 1; $n <= 10; $n++): ?><option value="<?= $n ?>"><?= $n ?></option><?php endfor; ?></select>
                    </label>
                    <label for="airflow-exhaust-count">Počet odtahů / přívodů (0–2)
                        <select id="airflow-exhaust-count"><option value="0">0</option><option value="1">1</option><option value="2">2</option></select>
                    </label>
                </div>
                <p class="airflow-hint">Nastavení uložené pro vybraný den platí od tohoto dne dál až do další změny. Starší dny zůstanou zachované.</p>
            </div>
            <div id="airflow-step-values" style="display:none;">
                <div id="airflow-values-form"></div>
                <p class="airflow-hint">Příkon 10–4000 W, čas 1–24 hodin, rychlost odtahu/přívodu 1–10.</p>
            </div>
            <div id="airflow-debug-message" role="alert" style="display:none;margin-top:12px;padding:8px;background:#fff3f3;color:#8b0000;border:1px solid #c62828;border-radius:5px;white-space:pre-wrap;font-size:12px;"></div>
        </div>
        <div class="confirm-actions">
            <button type="button" id="airflow-cancel" class="confirm-button confirm-cancel">Zrušit</button>
            <button type="button" id="airflow-back" class="confirm-button confirm-cancel" style="display:none;">Zpět</button>
            <button type="button" id="airflow-next" class="confirm-button confirm-ok-blue">OK</button>
            <button type="button" id="airflow-save" class="confirm-button confirm-ok-blue" style="display:none;">Uložit</button>
        </div>
    </div>
</div>

<!-- Pot config dialog -->
<div id="pots-dialog" class="confirm-dialog-overlay">
    <div class="confirm-dialog" style="max-width: 620px;">
        <div class="pots-dialog-header"><h3>Nastavení květináčů</h3></div>
        <div class="pots-dialog-body">
            <div class="pots-count-row">
                <label for="pots-count">Počet květináčů:</label>
                <select id="pots-count" onchange="renderPotRows()">
                    <?php for ($pc = 1; $pc <= 9; $pc++): ?>
                        <option value="<?= $pc ?>"><?= $pc ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div id="pots-rows"></div>
        </div>
        <div class="confirm-actions">
            <button type="button" id="pots-cancel" class="confirm-button confirm-cancel">Zrušit</button>
            <button type="button" id="pots-save" class="confirm-button confirm-ok-blue">Uložit</button>
        </div>
    </div>
</div>




<!-- Audit A -->
<div id="grow-audit-dialog" class="pruning-dialog-backdrop" role="dialog" aria-modal="true" aria-labelledby="grow-audit-title" style="display:none;z-index:26000;">
 <div class="pruning-dialog" style="width:min(1180px,97vw);max-width:1180px;max-height:94vh;overflow:auto;">
  <div class="pruning-dialog-header"><h3 id="grow-audit-title">A – Audit světla a proudění vzduchu</h3><button type="button" onclick="closeGrowAudit()">×</button></div>
  <div class="pruning-dialog-body">
   <p style="color:#546e7a">Zadej rozměry prostoru a parametry zařízení. Audit připraví doporučení pro všechny čtyři fáze. Výsledky jsou orientační; PPFD ověř měřidlem na úrovni koruny.</p>
   <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;align-items:start">
    <section style="border:1px solid #c5d8e8;border-radius:10px;padding:12px;min-width:0">
     <h3 style="color:#1565c0">☀ Audit světla</h3>
     <label>Jednotky rozměrů <select id="audit-unit" onchange="auditUnitChanged()"><option value="cm">Centimetry (cm)</option><option value="in">Palce (in)</option></select></label>
     <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px;margin:8px 0">
      <label>Délka <input id="audit-length" type="number" min="1" value="120" style="width:100%;box-sizing:border-box"></label>
      <label>Šířka <input id="audit-width" type="number" min="1" value="120" style="width:100%;box-sizing:border-box"></label>
      <label>Výška <input id="audit-height" type="number" min="1" value="200" style="width:100%;box-sizing:border-box"></label>
     </div>
     <label>Aktuální fáze <select id="audit-stage" style="width:100%"><option value="early_growth">A – Raný růst</option><option value="late_growth">B – Pokročilý růst</option><option value="early_flower">C – Raný květ</option><option value="late_flower">D – Pokročilý květ</option></select></label>
     <div style="display:flex;justify-content:space-between;margin:12px 0 6px"><strong>Světla</strong><button type="button" onclick="auditAddLight()">＋ Přidat světlo</button></div>
     <div id="audit-lights"></div>
     <small style="color:#607d8b">PPF = celkový tok fotonů (µmol/s). PPE/účinnost = µmol/J. PPFD = intenzita dopadající na plochu.</small><div id="audit-light-result" style="margin-top:10px"></div>
    </section>
    <section style="border:1px solid #c8dfc8;border-radius:10px;padding:12px;min-width:0">
     <h3 style="color:#2e7d32">〰 Audit proudění vzduchu</h3>
     <p style="font-size:12px;color:#546e7a">CFM znamená cubic feet per minute – kubické stopy za minutu. 1 CFM ≈ 1,699 m³/h.</p>
     <div style="display:flex;justify-content:space-between;margin:8px 0"><strong>Větráky / odtahy</strong><button type="button" onclick="auditAddFan()">＋ Přidat větrák</button></div>
     <div id="audit-fans"></div>
     <label>Počet rychlostních stupňů <select id="audit-speeds"><option value="3">3 rychlosti</option><option value="4">4 rychlosti</option><option value="5">5 rychlostí</option><option value="6">6 rychlostí</option><option value="10" selected>10 rychlostí</option><option value="variable">Plynulá regulace</option></select></label>
     <div id="audit-air-result" style="margin-top:10px"></div>
    </section>
   </div>
   <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;margin-top:14px"><button type="button" onclick="deleteGrowAudit()" style="background:#ffebee;color:#b71c1c">Vymazat uložená data</button><button type="button" onclick="calculateGrowAudit()" style="background:#1565c0;color:white">OK – vypočítat a uložit audit</button><button type="button" onclick="closeGrowAudit()">Zavřít</button></div>
   <div id="audit-message" role="status" style="margin-top:10px;font-weight:600"></div><div id="audit-all-phases" style="margin-top:14px"></div>
   <p style="font-size:11px;color:#78909c">Předvolby vycházejí z dodaných parametrů. U Mars Hydro SP6500 je ve vstupních podkladech rozpor 650 W vs. 220 W; předvolba přebírá 220 W, PPF 1890 a PPE 2,8 a vyžaduje ověření. Model je orientační. Hodnota m/s sama neurčuje m³/h bez průměru/ plochy výstupu. Vzdálenost světla nelze spolehlivě určit pouze z wattů; skutečné PPFD závisí na optice, ploše a rozložení svítidla. Zadané m³/h je třeba chápat jako reálný průtok po započtení odporu filtrů a potrubí.</p>
  </div>
 </div>
</div>

<!-- Přehled spotřeby elektřiny E -->
<div id="electricity-dialog" class="pruning-dialog-backdrop" role="dialog" aria-modal="true" aria-labelledby="electricity-title" style="display:none;">
 <div class="pruning-dialog custom-functions-dialog">
  <div class="pruning-dialog-header"><h3 id="electricity-title">E – Spotřeba a náklady na elektřinu</h3></div>
  <div class="pruning-dialog-body">
   <p style="color:#555;margin-top:0">Spotřeba se načítá z denních záznamů světel, větráků a odtahů.</p>
   <div style="border:1px solid #cfd8dc;border-radius:7px;padding:12px;margin:8px 0">
    <label for="electricity-single-price"><strong>Proud – cena za kWh (Kč)</strong></label>
    <input id="electricity-single-price" type="number" min="0" step="0.001" placeholder="Cena elektřiny za kWh" style="width:100%;padding:9px;box-sizing:border-box;margin-top:5px">
    <button type="button" id="electricity-single-save" class="confirm-button confirm-ok-blue" style="margin-top:10px" onclick="saveElectricitySettings(false)">Uložit cenu proudu</button>
    <label style="display:flex;gap:8px;align-items:center;margin-top:12px"><input type="checkbox" id="electricity-change-from-date"> Změnit cenu až od konkrétního data</label>
    <label for="electricity-effective-date" style="display:block;margin-top:7px">Datum platnosti nové ceny</label><input id="electricity-effective-date" type="date" style="width:100%;padding:9px;box-sizing:border-box" disabled>
    <div id="electricity-price-history" style="margin-top:10px;font-size:13px"></div>
   </div>
   <button type="button" id="electricity-tariff-toggle" class="confirm-button confirm-ok-blue" style="margin:6px 0" aria-expanded="false" onclick="toggleElectricityTariffs()">＋ Rozdělit na denní a noční</button>
   <div id="electricity-tariff-settings" style="display:none;border:1px solid #cfd8dc;border-radius:7px;padding:12px;margin:8px 0">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px">
     <div><label for="electricity-day-price">Denní proud – Kč/kWh</label><input id="electricity-day-price" type="number" min="0.01" step="0.001" placeholder="Cena denního proudu" style="width:100%;padding:9px;box-sizing:border-box"></div>
     <div><label for="electricity-night-price">Noční proud – Kč/kWh</label><input id="electricity-night-price" type="number" min="0.01" step="0.001" placeholder="Cena nočního proudu" style="width:100%;padding:9px;box-sizing:border-box"></div>
     <div><label for="electricity-day-hours">Hodin denního proudu za den</label><input id="electricity-day-hours" type="number" min="0" max="24" step="0.25" value="16" style="width:100%;padding:9px;box-sizing:border-box"></div>
     <div><label for="electricity-night-hours">Hodin nočního proudu za den</label><input id="electricity-night-hours" type="number" min="0" max="24" step="0.25" value="8" style="width:100%;padding:9px;box-sizing:border-box"></div>
    </div>
    <p style="font-size:12px;color:#666;margin:6px 0">Součet denních a nočních hodin musí být 24. Spotřeba se mezi tarify rozdělí poměrem zadaných hodin.</p>
    <label for="electricity-extra-watts" style="display:block;margin-top:10px">Další zařízení – souhrnný příkon (W)</label><input id="electricity-extra-watts" type="number" min="0" step="1" style="width:100%;padding:9px;box-sizing:border-box" value="0">
    <button type="button" class="confirm-button confirm-ok-blue" style="margin-top:12px" onclick="saveElectricitySettings(true)">Uložit tarify a příkon</button>
   </div>
   <div id="electricity-results" style="margin-top:14px"></div>
   <div id="electricity-message" class="pruning-message" role="status"></div>
   <pre id="electricity-debug" style="white-space:pre-wrap;background:#fff8e1;border:1px solid #ffcc80;border-radius:5px;padding:8px;font-size:11px;color:#5d4037;max-height:120px;overflow:auto">DEBUG E: připraveno</pre>
   <button type="button" class="confirm-button confirm-cancel" onclick="closeElectricityDialog()">Zavřít</button>
  </div>
 </div>
</div>

<!-- Tři vlastní uživatelské funkce -->
<div id="custom-functions-dialog" class="pruning-dialog-backdrop" role="dialog" aria-modal="true" aria-labelledby="custom-functions-title">
 <div class="pruning-dialog custom-functions-dialog">
  <div class="pruning-dialog-header"><h3 id="custom-functions-title">T – vlastní funkce</h3></div>
  <div class="pruning-dialog-body">
   <p style="margin:0 0 12px;color:#555">Pojmenuj si až tři vlastní funkce a vyber jejich barvu. Potom vyber funkci a datum, kdy se má barevně označit den v kalendáři.</p>
   <div class="custom-function-settings">
    <div class="custom-function-setting"><strong>Funkce 1</strong><input id="custom-function-name-1" type="text" maxlength="100" placeholder="Vlastní název funkce 1"><select id="custom-function-color-1"><option value="#ff9100">Svítivě oranžová</option><option value="#ffea00">Svítivě žlutá</option><option value="#00b0ff">Svítivě modrá</option></select></div>
    <div class="custom-function-setting"><strong>Funkce 2</strong><input id="custom-function-name-2" type="text" maxlength="100" placeholder="Vlastní název funkce 2"><select id="custom-function-color-2"><option value="#ff9100">Svítivě oranžová</option><option value="#ffea00">Svítivě žlutá</option><option value="#00b0ff">Svítivě modrá</option></select></div>
    <div class="custom-function-setting"><strong>Funkce 3</strong><input id="custom-function-name-3" type="text" maxlength="100" placeholder="Vlastní název funkce 3"><select id="custom-function-color-3"><option value="#ff9100">Svítivě oranžová</option><option value="#ffea00">Svítivě žlutá</option><option value="#00b0ff">Svítivě modrá</option></select></div>
   </div>
   <button type="button" class="confirm-button confirm-ok-blue" onclick="saveCustomFunctionSettings()">Uložit názvy a barvy</button>
   <hr style="margin:16px 0;border:0;border-top:1px solid #ddd">
   <h4 style="margin:0 0 8px">Vytvoření týdnů Growreportu</h4>
   <p style="margin:0 0 10px;color:#555">Nastav počet týdnů růstu a květu. Po kliknutí na Vytvořit se připraví požadovaný počet týdnů a automaticky se označí fáze RŮST (zeleně) a KVĚT (červeně). Již uložené záznamy v týdnech zůstanou zachované.</p>
   <div class="custom-function-setting" style="grid-template-columns:1fr 1fr;">
    <label for="custom-growth-weeks">Počet týdnů RŮST</label>
    <label for="custom-flower-weeks">Počet týdnů KVĚT</label>
    <input id="custom-growth-weeks" type="number" min="0" max="52" step="1" value="11">
    <input id="custom-flower-weeks" type="number" min="0" max="52" step="1" value="10">
   </div>
   <button type="button" class="confirm-button confirm-ok-blue" id="custom-create-weeks-button" onclick="generateGrowreportWeeksFromT()">Vytvořit týdny</button>
   <div id="custom-create-weeks-message" class="pruning-message" role="status" aria-live="polite"></div>
   <hr style="margin:16px 0;border:0;border-top:1px solid #ddd">
   <label for="custom-function-slot">Vybraná funkce</label><select id="custom-function-slot"><option value="1">Funkce 1</option><option value="2">Funkce 2</option><option value="3">Funkce 3</option></select>
   <label for="custom-function-date">Datum označení</label><select id="custom-function-date"></select>
   <button type="button" class="confirm-button confirm-ok-blue" onclick="saveCustomFunctionMark()">Označit vybraný den</button>
   <div id="custom-functions-message" class="pruning-message" role="status"></div>
   <h4>Uložené záznamy</h4><div id="custom-functions-history"></div>
   <div class="growreport-calendar-color-legend" style="margin-top:18px;padding-top:12px;border-top:1px solid #ddd;">
    <h4 style="margin:0 0 10px;">Přehled všech barev používaných v kalendáři</h4>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:7px 12px;">
     <div style="display:flex;align-items:center;gap:8px;"><span style="display:inline-block;width:22px;height:18px;background:#ff1744;border:1px solid #b00020;border-radius:3px;"></span><span>Výtok – svítivě červená</span></div>
     <div style="display:flex;align-items:center;gap:8px;"><span style="display:inline-block;width:22px;height:18px;background:#5d4037;border:1px solid #3e2723;border-radius:3px;"></span><span>Přesazení – tmavě hnědá</span></div>
     <div style="display:flex;align-items:center;gap:8px;"><span style="display:inline-block;width:22px;height:18px;background:#ffffff;border:1px solid #777;border-radius:3px;"></span><span>Kolínka, Přivázání, Kameny – bílá</span></div>
     <div style="display:flex;align-items:center;gap:8px;"><span style="display:inline-block;width:22px;height:18px;background:#dcedc8;border:1px solid #9eaa8d;border-radius:3px;"></span><span>Zastřižení, FIM, LST – světle zelená</span></div>
     <div style="display:flex;align-items:center;gap:8px;"><span style="display:inline-block;width:22px;height:18px;background:#39ff14;border:1px solid #20b800;border-radius:3px;"></span><span>Defoliace – svítivě zelená</span></div>
     <div style="display:flex;align-items:center;gap:8px;"><span style="display:inline-block;width:22px;height:18px;background:#e53935;border:1px solid #b71c1c;border-radius:3px;"></span><span>Sklizeň – červená</span></div>
     <div style="display:flex;align-items:center;gap:8px;"><span style="display:inline-block;width:22px;height:18px;background:#123d73;border:1px solid #0d2d55;border-radius:3px;"></span><span>Vanička / zvlhčovač – tmavě modrá</span></div>
     <div style="display:flex;align-items:center;gap:8px;"><span style="display:inline-block;width:22px;height:18px;background:#efdfd3;border:1px solid #bba99d;border-radius:3px;"></span><span>Postřik – světle hnědá</span></div>
     <div style="display:flex;align-items:center;gap:8px;"><span style="display:inline-block;width:22px;height:18px;background:#d9efff;border:1px solid #9bbbd3;border-radius:3px;"></span><span>Síť – světle modrá</span></div>
     <div style="display:flex;align-items:center;gap:8px;"><span style="display:inline-block;width:22px;height:18px;background:#ffd700;border:1px solid #b89b00;border-radius:3px;"></span><span>Úplněk – zlatá</span></div>
     <div style="display:flex;align-items:center;gap:8px;"><span style="display:inline-block;width:22px;height:18px;background:#ff9100;border:1px solid #b76500;border-radius:3px;"></span><span>Vlastní funkce – svítivě oranžová</span></div>
     <div style="display:flex;align-items:center;gap:8px;"><span style="display:inline-block;width:22px;height:18px;background:#ffea00;border:1px solid #b8a900;border-radius:3px;"></span><span>Vlastní funkce – svítivě žlutá</span></div>
     <div style="display:flex;align-items:center;gap:8px;"><span style="display:inline-block;width:22px;height:18px;background:#00b0ff;border:1px solid #0079b3;border-radius:3px;"></span><span>Vlastní funkce – svítivě modrá</span></div>
    </div>
   </div>
  </div>
  <div class="confirm-actions"><button type="button" class="confirm-button confirm-cancel" onclick="closeCustomFunctionsDialog()">Zavřít</button></div>
 </div>
</div>

<!-- Evidence zastřižení, FIM a LST -->
<div id="pruning-dialog" class="pruning-dialog-backdrop" role="dialog" aria-modal="true" aria-labelledby="pruning-dialog-title">
 <div class="pruning-dialog">
  <div class="pruning-dialog-header"><h3 id="pruning-dialog-title">Zastřižení / FIM / LST</h3></div>
  <div class="pruning-dialog-body">
   <label for="pruning-date">Datum úkonu</label>
   <select id="pruning-date"></select>
   <label for="pruning-action-type">Provedený úkon</label>
   <select id="pruning-action-type"><option value="1">1. Zastřižení</option><option value="2">2. FIM</option><option value="3">3. LST</option><option value="4">4. Kameny</option><option value="5">5. Přivázání</option><option value="6">6. Kolínka</option><option value="8">8. Defoliace</option><option value="9">9. Sklizeň</option><option value="10">10. Přesazení</option></select>
   <div id="pruning-message" class="pruning-message" role="status"></div>
   <h4>Historie záznamů</h4>
   <div id="pruning-history-wrap"></div>
  </div>
  <div class="confirm-actions">
   <button type="button" class="confirm-button confirm-cancel" onclick="closePruningDialog()">Zavřít</button>
   <button type="button" class="confirm-button confirm-ok-blue" onclick="savePruningAction()">Uložit úkon</button>
  </div>
 </div>
</div>

<!-- Evidence vaničky / zvlhčovače -->
<div id="vz-dialog" class="vz-dialog-backdrop" role="dialog" aria-modal="true" aria-labelledby="vz-dialog-title">
 <div class="vz-dialog">
  <div class="vz-dialog-header"><h3 id="vz-dialog-title">Vanička / zvlhčovač</h3></div>
  <div class="vz-dialog-body">
   <label for="vz-date">Den a datum</label>
   <select id="vz-date" class="vz-date-select"></select>
   <label for="vz-type">Záznam</label>
   <select id="vz-type" class="vz-type-select" onchange="toggleVzDial()"><option value="vanicka">Vanička</option><option value="zvlhcovac">Zvlhčovač</option></select>
   <div id="vz-hours-picker-wrap" class="vz-hours-picker-wrap">
    <label class="vz-hours-picker-label" for="vz-hours-picker">Doba chodu zvlhčovače (0–24 hodin)</label>
    <div id="vz-hours-picker" class="sp-wrap" role="listbox" aria-label="Doba chodu zvlhčovače v hodinách"></div>
    <div style="font-size:12px;color:#667;margin-top:2px">Posuň válec na požadovaný čas. Krok je 0,5 hodiny.</div>
   </div>
   <label class="vz-anchor-option" for="vz-anchor-future"><input type="checkbox" id="vz-anchor-future"> <span>Ukotvit toto nastavení jako předlohu pro budoucí označené dny</span></label>
   <div class="vz-anchor-help" style="font-size:12px;color:#526477;text-align:center;margin:-4px 0 10px">V budoucím dni se hodnota pouze předvyplní po jeho výběru. Do kalendáře se zapíše až po stisknutí „Uložit záznam“.</div>
   <div id="vz-message" class="vz-message" role="status"></div>
   <h4>Historie záznamů</h4>
   <div id="vz-history-wrap"></div>
  </div>
  <div class="confirm-actions">
   <button type="button" class="confirm-button confirm-cancel" onclick="closeVzDialog()">Zavřít</button>
   <button type="button" class="confirm-button confirm-ok-blue" onclick="saveVzRecord()">Uložit záznam</button>
  </div>
 </div>
</div>

<!-- Evidence postřiků -->
<div id="spray-dialog" class="spray-dialog-backdrop" role="dialog" aria-modal="true" aria-labelledby="spray-dialog-title">
 <div class="spray-dialog">
  <div class="spray-dialog-header"><h3 id="spray-dialog-title">Postřik</h3></div>
  <div class="spray-dialog-body">
   <label for="spray-date">Datum postřiku</label>
   <select id="spray-date" class="spray-date-select"></select>
   <div class="spray-choice-row">
    <div class="spray-field"><label for="spray-name-1">Postřik 1 – název</label><select id="spray-name-1"><option value="">Vyber postřik</option><option value="PK 13/14">PK 13/14</option><option value="Pivo">Pivo</option><option value="Koloidní stříbro">Koloidní stříbro</option></select></div>
    <div class="spray-field"><label for="spray-ml-1">Množství (ml)</label><input id="spray-ml-1" type="number" min="1" max="10000" step="1" value="1"></div>
   </div>
   <div class="spray-choice-row">
    <div class="spray-field"><label for="spray-name-2">Postřik 2 – název</label><input id="spray-name-2" type="text" maxlength="100" placeholder="Druhý název postřiku (volitelné)"></div>
    <div class="spray-field"><label for="spray-ml-2">Množství (ml)</label><input id="spray-ml-2" type="number" min="1" max="10000" step="1" value="1"></div>
   </div>
   <p style="font-size:12px;color:#666;margin:8px 0 0">U Postřiku 1 vyber jednu ze tří možností; Postřik 2 může mít vlastní název. U každého zvoleného postřiku zadej množství v ml.</p>
   <div id="spray-message" class="spray-message" role="status"></div>
   <h4>Historie postřiků</h4>
   <div id="spray-history-wrap" class="spray-history-list"></div>
  </div>
  <div class="confirm-actions"><button type="button" class="confirm-button confirm-cancel" onclick="closeSprayDialog()">Zavřít</button><button type="button" class="confirm-button confirm-ok-blue" onclick="saveSprayAction()">Uložit postřik</button></div>
 </div>
</div>

<!-- Označení dne se sítí -->
<div id="net-dialog" class="net-dialog-backdrop" role="dialog" aria-modal="true" aria-labelledby="net-dialog-title">
 <div class="net-dialog">
  <div class="net-dialog-header"><h3 id="net-dialog-title">Síť</h3></div>
  <div class="net-dialog-body">
   <label for="net-date">Datum označení sítě</label>
   <select id="net-date"></select>
   <label class="net-checkbox-row"><input type="checkbox" id="net-enabled"><span>Síť je nasazena</span></label>
   <div id="net-message" class="spray-message" role="status"></div>
   <h4>Označené dny</h4><div id="net-history-wrap" class="net-history-list"></div>
  </div>
  <div class="confirm-actions"><button type="button" class="confirm-button confirm-cancel" onclick="closeNetDialog()">Zavřít</button><button type="button" class="confirm-button confirm-ok-blue" onclick="saveNetMark()">Uložit síť</button></div>
 </div>
</div>

<!-- Nastavení prvního dne a data začátku growreportu -->
<div id="calendar-config-dialog" class="calendar-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="calendar-config-title">
    <div class="calendar-modal">
        <div class="calendar-modal-header"><h3 id="calendar-config-title">První den growreportu</h3></div>
        <div class="calendar-modal-body">
            <section class="calendar-config-section">
                <h4>1. Vyberte počáteční den týdne</h4>
                <div class="calendar-weekday-options">
                    <?php foreach ($weekdayOptions as $weekdayOption): ?>
                    <label><input type="radio" name="calendar-first-weekday" value="<?= htmlspecialchars($weekdayOption, ENT_QUOTES, 'UTF-8') ?>" <?= $calendarFirstWeekday === $weekdayOption ? 'checked' : '' ?>> <span><?= htmlspecialchars(ucfirst($weekdayOption), ENT_QUOTES, 'UTF-8') ?></span></label>
                    <?php endforeach; ?>
                </div>
            </section>
            <section class="calendar-config-section">
                <h4>2. Vyberte počáteční datum</h4>
                <label for="calendar-start-date">Datum prvního dne pěstování</label>
                <input type="date" id="calendar-start-date" value="<?= htmlspecialchars($calendarStartDate, ENT_QUOTES, 'UTF-8') ?>">
                <p class="calendar-hint">Po výběru data se den týdne nastaví automaticky. Podle tohoto data se budou určovat i dny s úplňkem.</p>
            </section>
            <div id="calendar-config-message" role="alert" class="calendar-config-message"></div>
        </div>
        <div class="confirm-actions">
            <button type="button" class="confirm-button confirm-cancel" onclick="closeCalendarSettings()">Zrušit</button>
            <button type="button" class="confirm-button confirm-ok-blue" onclick="saveCalendarSettings()">OK</button>
        </div>
    </div>
</div>

<!-- Watering dialog -->
<div id="watering-dialog" class="confirm-dialog-overlay">
    <div class="confirm-dialog" style="max-width: 700px;">
        <div class="watering-dialog-header"><h3 id="watering-dialog-title">Zalévání</h3></div>
        <div class="watering-dialog-body">
    <div class="watering-calc-open-row"><button type="button" class="watering-calc-open-btn" onclick="openWateringCalculator()">🧮 Automatické vypočítání zalití</button></div>
    <div id="watering-pots-row" class="watering-pots-row"></div>
    <div id="watering-total" class="watering-total">Celkem: 0 L</div>

    <div class="watering-ph-panel">
        <div class="watering-ph-row">
            <label for="watering-ph-value">pH vody (společné pro všechny květináče)</label>
            <select id="watering-ph-value" class="watering-ph-select" aria-label="pH vody od 1 do 10">
                <option value="">Nevybráno</option>
                <?php for ($phOption = 2; $phOption <= 20; $phOption++): $phDisplay = $phOption / 2; ?>
                    <option value="<?= $phDisplay ?>"><?= str_replace('.', ',', (string)$phDisplay) ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div id="watering-ph-saved" class="watering-ph-saved">Jedna hodnota pH pro celé zalití.</div>
    </div>

    <div class="watering-ph-panel">
        <div class="watering-ph-row">
            <label for="watering-runoff-ph-value">pH výtok vody z pod květináče</label>
            <select id="watering-runoff-ph-value" class="watering-ph-select" aria-label="pH výtokové vody od 1 do 10">
                <option value="">Nevybráno</option>
                <?php for ($phOption = 2; $phOption <= 20; $phOption++): $phDisplay = $phOption / 2; ?>
                    <option value="<?= $phDisplay ?>"><?= str_replace('.', ',', (string)$phDisplay) ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div id="watering-runoff-ph-saved" class="watering-ph-saved">Jedna hodnota pH výtokové vody pro celé zalití.</div>
        <div class="watering-ph-row" style="margin-top:12px;">
            <label for="watering-runoff-liters-picker">Množství výtoku (litry)</label>
            <div id="watering-runoff-liters-picker" class="sp-wrap" style="width:76px; margin:0 auto;" aria-label="Objem výtoku od 0,1 do 10 litrů"></div>
        </div>
        <div id="watering-runoff-liters-saved" class="watering-ph-saved">Zaznamenej množství výtoku od 0,1 do 10 litrů.</div>
    </div>

    <div id="watering-saved-wrap" style="margin-top:12px;">
        <div style="font-weight:700; color:#1565C0; margin-bottom:6px;">Uložené hodnoty:</div>
        <div id="watering-saved-list" style="font-size:14px; color:#222;"></div>
    </div>
</div>
        <div class="confirm-actions">
            <button type="button" id="watering-cancel" class="confirm-button confirm-cancel">Zrušit</button>
          <button type="button" id="watering-ok" class="confirm-button confirm-ok-blue">Uložit hodnotu</button>
        </div>
    </div>
</div>

<!-- Kalkulačka doporučeného zalití; používá fázi týdne a objemy nastavených květináčů. -->
<div id="watering-calculator-dialog" class="confirm-dialog-overlay watering-calc-overlay" role="dialog" aria-modal="true" aria-labelledby="watering-calc-title" onclick="if(event.target===this)closeWateringCalculator()">
    <div class="watering-calc-dialog">
        <div class="watering-calc-header">
            <h3 id="watering-calc-title">Automatické vypočítání zalití</h3>
            <button type="button" class="watering-calc-close" onclick="closeWateringCalculator()" aria-label="Zavřít">&times;</button>
        </div>
        <div class="watering-calc-body">
            <section class="watering-calc-section">
                <h4>1. Vyber fázi rostlin</h4>
                <div class="watering-calc-phase-row">
                    <button type="button" id="watering-calc-phase-growth" class="watering-calc-phase-btn" onclick="setWateringCalculatorPhase('growth')">🌿 Růst</button>
                    <button type="button" id="watering-calc-phase-flower" class="watering-calc-phase-btn" onclick="setWateringCalculatorPhase('flower')">🌼 Květ</button>
                </div>
                <p id="watering-calc-phase-note" class="watering-calc-phase-note">Fáze se předvybere podle aktuálního týdne Growreportu; můžeš ji změnit.</p>
            </section>
            <section class="watering-calc-section">
                <h4>2. Vyber aktuální velikost rostliny kliknutím na obrázek</h4>
                <div id="watering-calc-stage-label" style="font-size:13px;font-weight:700;color:#2e7d32;margin-bottom:8px;">Růst – vyber stupeň 1 až 9</div>
                <div id="watering-calc-stage-grid" class="watering-calc-stage-grid"></div>
                <p class="watering-calc-help">Každý obrázek představuje orientační vývojovou fázi. Vyber jeden stupeň, který nejlépe odpovídá rostlinám.</p>
            </section>
            <section class="watering-calc-section">
                <h4>3. Zadej objem substrátu v jednotlivých květináčích (litry)</h4>
                <div id="watering-calc-volume-rows" class="watering-calc-volume-rows"></div>
                <p class="watering-calc-help">Objemy jsou předvyplněné z nastavení květináčů a zde je můžeš pro tento výpočet upravit.</p>
            </section>
            <section class="watering-calc-section">
                <h4>4. Vyber interval zalévání</h4>
                <div class="watering-calc-frequency-row">
                    <label class="watering-calc-frequency-option"><input type="radio" name="watering-calc-frequency" value="5"> Jednou za 5 dní</label>
                    <label class="watering-calc-frequency-option"><input type="radio" name="watering-calc-frequency" value="4" checked> Jednou za 4 dny</label>
                    <label class="watering-calc-frequency-option"><input type="radio" name="watering-calc-frequency" value="3"> Jednou za 3 dny</label>
                    <label class="watering-calc-frequency-option"><input type="radio" name="watering-calc-frequency" value="2"> Jednou za 2 dny</label>
                </div>

                <p class="watering-calc-help">Automatická dávka na jedno zalití se určuje podle týdne a objemu substrátu: květináče od 7 l – Růst 1–4: 0,5 l na 10 l, Růst 5–9: 1 l na 10 l, Květ 1–7: 1,5 l na 10 l, Květ 8–9: 2 l na 10 l, Květ 10: 2,5 l na 10 l. Květináče do 6,9 l – postupně 0,1 / 0,2 / 0,3 / 0,4 / 0,5 l na 1 l substrátu pro stejné fáze.</p>
                <div class="watering-calc-help" style="border:1px solid #cbd5e1;border-radius:8px;padding:12px;margin-top:10px;">
                    <strong>Import vlastních pravidel zálivky</strong>
                    <p style="margin:6px 0;">Nahraj CSV nebo JSON s dávkami. CSV sloupce: <code>interval_days;phase;dose_l_per_20l</code>. Fáze: growth1, growth2, flower1, flower2, flower3. Data se uloží v tomto prohlížeči a použijí se při dalších výpočtech.</p>
                    <input type="file" id="watering-calc-data-file" accept=".csv,.json,text/csv,application/json">
                    <button type="button" class="btn btn-secondary" id="watering-calc-export-data" style="margin-left:6px;">Exportovat uložená data</button>
                    <span id="watering-calc-data-status" style="display:block;margin-top:6px;"></span>
                </div>
                <details class="watering-calc-help" style="border:1px solid #93c5fd;border-radius:8px;padding:12px;margin-top:10px;background:#eff6ff;">
                    <summary style="cursor:pointer;font-weight:700;">Skutečná historie zalévání tohoto Growreportu – rozkliknutím zobrazíš podrobnosti</summary>
                    <div style="margin-top:8px;">
                    <p style="margin:6px 0;">Načte uložené záznamy ze všech týdnů právě otevřeného Growreportu, vytvoří přehled a použije skutečné dávky jako základ tabulek i dalších výpočtů automatické zálivky.</p>
                    <button type="button" class="watering-calc-action-btn watering-calc-action-calculate" id="watering-calc-load-history" onclick="loadCurrentGrowreportWateringHistory()">Načíst historii zalévání z tohoto Growreportu</button>
                    <?php if ($isAdmin && !$isPublicView): ?>
                    <button type="button" class="watering-calc-action-btn" id="watering-calc-save-history" style="margin-left:6px;" onclick="saveCurrentWateringHistorySnapshot()">Uložit historii Growreportu</button>
                    <?php else: ?>
                    <span style="display:inline-block;margin-left:6px;color:#64748b;font-size:13px;">Ukládání do globálního seznamu je dostupné pouze administrátorovi.</span>
                    <?php endif; ?>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:10px;">
                        <label style="display:flex;align-items:center;gap:7px;font-weight:600;"><input type="checkbox" id="watering-calc-use-current-history" checked onchange="handleWateringHistorySourceChange()"> Skutečná historie zalévání tohoto Growreportu</label>
                        <span style="color:#64748b;">Vypnutím přepneš výpočet na vybraný uložený Growreport.</span>
                    </div>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:10px;">
                        <label for="watering-calc-saved-history-select" style="font-weight:600;">Uložený Growreport:</label>
                        <select id="watering-calc-saved-history-select" style="min-width:220px;max-width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:6px;"><option value="">— Vyber uloženou historii —</option></select>
                        <button type="button" class="watering-calc-action-btn watering-calc-action-calculate" id="watering-calc-apply-saved-history" onclick="applySelectedWateringHistorySnapshot()">Počítat podle vybraného Growreportu</button>
                    </div>
                    <div id="watering-calc-history-status" style="margin-top:8px;" aria-live="polite"></div>
                    <div id="watering-calc-history-summary" style="margin-top:10px;"></div>
                    <div id="watering-calc-history-table" style="margin-top:10px;overflow-x:auto;"></div>
                    </div>
                </details>
                <div class="watering-calc-consumption-wrap">
                    <h5 id="watering-calc-consumption-title">Odhad spotřeby za 19 týdnů – podle objemu substrátu v květináčích</h5>
                    <div class="watering-calc-consumption-scroll">
                        <table class="watering-calc-consumption-table">
                            <thead><tr>
                                <th>Fáze</th>
                                <th data-frequency="5">Každých 5 dní</th>
                                <th data-frequency="4">Každé 4 dny</th>
                                <th data-frequency="3">Každé 3 dny</th>
                                <th data-frequency="2">Každé 2 dny</th>
                            </tr></thead>
                            <tbody>
                                <tr><td>Růst 1–4</td><td data-frequency="5" data-phase-budget="growth1">21 L</td><td data-frequency="4" data-phase-budget="growth1">21 L</td><td data-frequency="3" data-phase-budget="growth1">21 L</td><td data-frequency="2" data-phase-budget="growth1">21 L</td></tr>
                                <tr><td>Růst 5–9</td><td data-frequency="5" data-phase-budget="growth2">16 L</td><td data-frequency="4" data-phase-budget="growth2">16 L</td><td data-frequency="3" data-phase-budget="growth2">16 L</td><td data-frequency="2" data-phase-budget="growth2">16 L</td></tr>
                                <tr><td>Květ 1–7</td><td data-frequency="5" data-phase-budget="flower1">12 L</td><td data-frequency="4" data-phase-budget="flower1">12 L</td><td data-frequency="3" data-phase-budget="flower1">12 L</td><td data-frequency="2" data-phase-budget="flower1">12 L</td></tr>
                                <tr><td>Květ 8–9</td><td data-frequency="5" data-phase-budget="flower2">42 L</td><td data-frequency="4" data-phase-budget="flower2">42 L</td><td data-frequency="3" data-phase-budget="flower2">42 L</td><td data-frequency="2" data-phase-budget="flower2">42 L</td></tr>
                                <tr><td>Květ 10</td><td data-frequency="5" data-phase-budget="flower3">24 L</td><td data-frequency="4" data-phase-budget="flower3">24 L</td><td data-frequency="3" data-phase-budget="flower3">24 L</td><td data-frequency="2" data-phase-budget="flower3">24 L</td></tr>
                                <tr class="calc-result-total"><td>Celkem – odhad za 133 dní</td><td data-frequency="5" data-phase-budget="total">115 L</td><td data-frequency="4" data-phase-budget="total">115 L</td><td data-frequency="3" data-phase-budget="total">115 L</td><td data-frequency="2" data-phase-budget="total">115 L</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="watering-calc-help" id="watering-calc-consumption-help">Odhad spotřeby se přepočítává podle celkového objemu substrátu z bodu 3. Dávky v následující tabulce jsou uvedeny jako základ na 20 l substrátu a při výpočtu se přepočítají na každý květináč.</p>
                    <h5 id="watering-calc-dose-title">Dávka na jedno zalití – podle objemu substrátu v bodu 3</h5>
                    <div class="watering-calc-dose-legend" aria-label="Legenda doporučení intervalů zalévání">
                        <span class="watering-calc-dose-legend-item"><span class="watering-calc-dose-legend-square optimal"></span> Zelená – optimální</span>
                        <span class="watering-calc-dose-legend-item"><span class="watering-calc-dose-legend-square possible"></span> Žlutá – ještě jde</span>
                        <span class="watering-calc-dose-legend-item"><span class="watering-calc-dose-legend-square not-recommended"></span> Červená – nedoporučeno zvolit</span>
                    </div>
                    <div class="watering-calc-consumption-scroll">
                        <table class="watering-calc-consumption-table">
                            <thead><tr><th>Fáze</th><th data-frequency="5">Každých 5 dní</th><th data-frequency="4">Každé 4 dny</th><th data-frequency="3">Každé 3 dny</th><th data-frequency="2">Každé 2 dny</th></tr></thead>
                            <tbody>
                                <tr><td>První den cyklu</td><td data-frequency="5" data-first-cycle-dose="1">2 L</td><td data-frequency="4" data-first-cycle-dose="1">2 L</td><td data-frequency="3" data-first-cycle-dose="1">2 L</td><td data-frequency="2" data-first-cycle-dose="1">2 L</td></tr>
                                <tr><td>Růst 1–4 – další zálivka</td><td data-frequency="5" data-phase-dose="growth1">2 L</td><td data-frequency="4" data-phase-dose="growth1">1,67 L</td><td data-frequency="3" data-phase-dose="growth1">1,11 L</td><td data-frequency="2" data-phase-dose="growth1">0,77 L</td></tr>
                                <tr><td>Růst 5–9</td><td data-frequency="5" data-phase-dose="growth2">4 L</td><td data-frequency="4" data-phase-dose="growth2">3,11 L</td><td data-frequency="3" data-phase-dose="growth2">2,33 L</td><td data-frequency="2" data-phase-dose="growth2">1,56 L</td></tr>
                                <tr><td>Květ 1–7</td><td data-frequency="5" data-phase-dose="flower1">6 L</td><td data-frequency="4" data-phase-dose="flower1">4,62 L</td><td data-frequency="3" data-phase-dose="flower1">3,53 L</td><td data-frequency="2" data-phase-dose="flower1">2,4 L</td></tr>
                                <tr><td>Květ 8–9</td><td data-frequency="5" data-phase-dose="flower2">8 L</td><td data-frequency="4" data-phase-dose="flower2">6 L</td><td data-frequency="3" data-phase-dose="flower2">4,8 L</td><td data-frequency="2" data-phase-dose="flower2">3,43 L</td></tr>
                                <tr><td>Květ 10</td><td data-frequency="5" data-phase-dose="flower3">10 L</td><td data-frequency="4" data-phase-dose="flower3">10 L</td><td data-frequency="3" data-phase-dose="flower3">6,67 L</td><td data-frequency="2" data-phase-dose="flower3">5 L</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
            <section class="watering-calc-section">
                <h4>5. Doporučené množství vody</h4>
                <div id="watering-calc-result" class="watering-calc-result" aria-live="polite"></div>
            </section>
            <section class="watering-calc-section">
                <h4>6. Automatický rozpis zalévání do všech týdnů</h4>
                <p class="watering-calc-help">Rozpis začne v týdnu 1, den 1 a použije vybraný interval. Fáze a stupeň se odvodí z nastavení jednotlivých týdnů Growreportu; u týdnů bez fáze se použije růst do týdne 9 a poté květ. Týdny označené Sušina se přeskočí. Náhled můžeš zkontrolovat před uložením.</p>
                <button type="button" id="watering-calc-build-schedule" class="watering-calc-action-btn watering-calc-action-calculate" onclick="previewAutoWateringSchedule()">Vytvořit rozpis pro všechny týdny</button>
                <div id="watering-calc-schedule-preview" class="watering-calc-result watering-calc-schedule-preview" aria-live="polite"></div>
            </section>
        </div>
        <div class="watering-calc-actions">
            <button type="button" class="watering-calc-action-btn watering-calc-action-close" onclick="closeWateringCalculator()">Zavřít</button>
            <button type="button" id="watering-calc-calculate" class="watering-calc-action-btn watering-calc-action-calculate" onclick="calculateWateringRecommendation()">OK – vypočítat</button>
            <button type="button" id="watering-calc-apply" class="watering-calc-action-btn watering-calc-action-apply" onclick="applyWateringRecommendation()" style="display:none;">Použít hodnoty v zalévání</button>
            <button type="button" id="watering-calc-save-schedule" class="watering-calc-action-btn watering-calc-action-apply" onclick="saveAutoWateringSchedule()" style="display:none;">Zapsat plán do tabulky Zalévání</button>
        </div>
    </div>
</div>

<!-- Galerie fotografií a videí konkrétního týdne -->
<div id="gallery-dialog" class="gallery-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="gallery-dialog-title">
    <div class="gallery-modal">
        <div class="gallery-modal-header">
            <h3 id="gallery-dialog-title">Galerie týdne</h3>
            <button type="button" class="gallery-modal-close" onclick="closeGallery()" aria-label="Zavřít galerii">×</button>
        </div>
        <div class="gallery-modal-body">
            <div class="gallery-upload-row">
                <label for="gallery-file-input"><strong>Přidat fotografie nebo videa (celkem nejvýše 10):</strong></label>
                <input type="file" id="gallery-file-input" accept="image/*,video/mp4,video/webm,video/quicktime,video/x-m4v" multiple <?= $canEdit ? '' : 'disabled aria-disabled="true"' ?>>
                <button type="button" id="gallery-upload-button" <?= $canEdit ? '' : 'disabled aria-disabled="true"' ?> onclick="uploadGalleryFiles()">Nahrát vybrané</button>
            </div>
            <div id="gallery-status" class="gallery-status" role="status"></div>
            <div id="gallery-items" class="gallery-grid"><div class="gallery-empty">Načítám galerii…</div></div>
        </div>
        <div class="confirm-actions"><button type="button" class="confirm-button confirm-cancel" onclick="closeGallery()">Zavřít</button></div>
    </div>
</div>

<!-- CO2 tip -->
<div id="co2-tip"></div>

<!-- TEPLOTA tip -->
<div id="temp-tip"></div>
<!-- VLHKOST tip -->
<div id="humidity-tip"></div>
<div id="lights-tip"></div>
<div id="airflow-tip"></div>

<footer><p>&copy; 2025 Growreports.cz - Vytvořil Viktor Dufka</p></footer>

<script>
    const reportId = <?= (int)$growreport['id'] ?>;
    const reportName = <?= json_encode((string)($growreport['name'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const PUBLIC_VIEW_MODE = <?= $isPublicView ? 'true' : 'false' ?>;
    const CAN_EDIT = <?= $canEdit ? 'true' : 'false' ?>;
    const API = <?= json_encode($_SERVER['PHP_SELF'], JSON_UNESCAPED_SLASHES) ?> + '?id=' + encodeURIComponent(reportId) + (PUBLIC_VIEW_MODE ? '&public_view=1' : '') + '&action=';
    // Musí být inicializováno před obsluhou ukládání/prvního vykreslení kalendáře.
    var calendarDayNames = ["pondělí", "úterý", "středa", "čtvrtek", "pátek", "sobota", "neděle"];
    let calendarStartDate = <?= json_encode($calendarStartDate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    let pruningRecords = <?= json_encode($pruningRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    let pruningCurrentWeek = 1;
    const pruningActionNames = {1:'Zastřižení', 2:'FIM', 3:'LST', 4:'Kameny', 5:'Přivázání', 6:'Kolínka', 8:'Defoliace', 9:'Sklizeň', 10:'Přesazení'};
    let pruningRecordDates = new Set((Array.isArray(pruningRecords) ? pruningRecords : []).map(function(row){ return String(row.record_date || ''); }));
    let customFunctionSettings = <?= json_encode($customFunctionSettings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    let customFunctionMarks = <?= json_encode($customFunctionMarks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    let customFunctionCurrentWeek = 1;
    const customFunctionColors = {'#ff9100':'Svítivě oranžová','#ffea00':'Svítivě žlutá','#00b0ff':'Svítivě modrá'};
    // Stejné barvy jako při označení příslušných funkcí v kalendáři Growreportu.
    function appendGrowreportHistoryColorCell(row, color, label) {
        var cell = document.createElement('td');
        cell.textContent = label;
        cell.style.backgroundColor = color;
        cell.style.color = '#263238';
        cell.style.fontWeight = '700';
        cell.style.textAlign = 'center';
        cell.style.whiteSpace = 'nowrap';
        row.appendChild(cell);
        return cell;
    }
    function pruningActionColor(actionType) {
        var type = Number(actionType);
        if (type === 8) return {color:'#39ff14', label:'Svítivě zelená'};
        if (type === 9) return {color:'#e53935', label:'Červená'};
        if (type === 10) return {color:'#5d4037', label:'Tmavě hnědá'};
        if ([4, 5, 6].includes(type)) return {color:'#ffffff', label:'Bílá'};
        return {color:'#dcedc8', label:'Světle zelená'};
    }
    let actionTaskStates = [];
    let sprayRecords = [];
    let sprayRecordDates = new Set();
    let sprayCurrentWeek = 1;
    let netRecords = [];
    let netRecordDates = new Set();
    let netCurrentWeek = 1;
    let vzRecords = <?= json_encode($vzRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    let vzRecordDates = new Set((Array.isArray(vzRecords) ? vzRecords : []).map(function(row){ return String(row.record_date || ''); }));
    let vzCurrentWeek = 1;
    let vzDialHours = 0;
    let vzPendingAnchorDate = '';
    const vzRecordNames = {vanicka:'Vanička', zvlhcovac:'Zvlhčovač'};
    const fullMoonDateList = <?= json_encode($fullMoonDates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    // Všechna ručně uložená data jsou přesná označení; nejstarší slouží jako výchozí datum automatického výpočtu.
    const fullMoonDateSet = new Set(Array.isArray(fullMoonDateList) ? fullMoonDateList : []);
    const fullMoonAnchorDate = (Array.isArray(fullMoonDateList) && fullMoonDateList.length)
        ? fullMoonDateList.slice().sort()[0] : '';
    // Průměrná synodická perioda mezi úplňky je 29,530588853 dne (NASA), nikoli 28 dní.
    const SYNODIC_MONTH_DAYS = 29.530588853;
    // Zapnutí zobrazení úplňků si stránka pamatuje pro tento konkrétní growreport.
    const fullMoonStateStorageKey = 'growreport_full_moon_active_' + reportId;
    let fullMoonHighlightActive = (function () {
        try { return window.localStorage.getItem(fullMoonStateStorageKey) === '1'; }
        catch (e) { return false; }
    })();
    const compressedFiles = {};
    let weekToDelete = null;
    let co2Ctx = null;
    let wateringCtx = null;

    // Pot config
    let potsConfig = <?= json_encode(array_map(function($p){ return ['pot_number'=>(int)$p['pot_number'],'volume'=>(float)$p['volume'],'pot_name'=>(string)($p['pot_name'] ?? '')]; }, $potsConfig), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    function getPotDisplayName(potNumber) {
        const number = Number(potNumber);
        const pot = Array.isArray(potsConfig) ? potsConfig.find(function(item) { return Number(item.pot_number) === number; }) : null;
        const customName = pot && typeof pot.pot_name === 'string' ? pot.pot_name.trim() : '';
        return customName || ('Květináč ' + number);
    }

    function rebuildGrowreportPotWaterMetrics() {
        if (!Array.isArray(growreportPotWaterMetrics)) return;
        const colors = ['#00838f','#6a1b9a','#ef6c00','#2e7d32','#c62828','#3949ab','#8d6e63','#0277bd'];
        const nextMetrics = (Array.isArray(potsConfig) ? potsConfig.slice() : [])
            .sort(function(a,b) { return Number(a.pot_number) - Number(b.pot_number); })
            .map(function(pot, index) {
                const potNumber = Number(pot.pot_number);
                return {key:'pot_' + potNumber + '_cumulative_l', label:getPotDisplayName(potNumber) + ' – Zalito celkem', unit:'L', color:colors[index % colors.length], beginAtZero:true};
            });
        growreportPotWaterMetrics.splice(0, growreportPotWaterMetrics.length);
        nextMetrics.forEach(function(metric) { growreportPotWaterMetrics.push(metric); });
        if (typeof growreportGraphBuilt !== 'undefined') growreportGraphBuilt = false;
    }

    // Watering data: {week: {day: {pot: amount}}}
    const wateringData = <?= json_encode($wateringJS, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const wateringLifetimeTotals = <?= json_encode($potWateringLifetimeTotals, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const wateringPHData = <?= json_encode($wateringPHJS, JSON_UNESCAPED_UNICODE) ?>;
    const wateringRunoffPHData = <?= json_encode($wateringRunoffPHJS, JSON_UNESCAPED_UNICODE) ?>;
    const wateringRunoffLitersData = <?= json_encode($wateringRunoffLitersJS, JSON_UNESCAPED_UNICODE) ?>;
    const dailyTaskFertilizersData = <?= json_encode($fertilizersForTasksJS, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const dailyTaskHnojenoData = <?= json_encode($hnojenoForTasksJS, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    // Effective lighting configuration per week (inherited from the latest prior change).
    const lightsData = <?= json_encode($lightsJS, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const lightsLoadError = <?= json_encode($lightsLoadError, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    // Účinné nastavení větráků a odtahů podle konkrétního dne (děděno od poslední změny).
    const airflowData = <?= json_encode($airflowJS, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const airflowLoadError = <?= json_encode($airflowLoadError, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const vzElectricityRecords = <?= json_encode($vzRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const electricityInitialSettings = <?= json_encode($electricitySettings, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const electricityPriceHistoryInitial = <?= json_encode($electricityPriceHistory, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const graphWeeksData = <?= json_encode($graphWeeksData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

    const TARGET_COMPRESSION_RATIO = 6.0;
    const COMPRESSION_QUALITY_START = 85;
    const COMPRESSION_MAX_WIDTH = 1500;
    const MAX_COMPRESSION_ATTEMPTS = 3;
// ========= GRAF A TÝDENNÍ SOUHRNY =========
const growreportGraphMetrics = [
 {key:'temperature_avg',label:'Průměrná teplota',unit:'°C',color:'#e65100',beginAtZero:false},
 {key:'humidity_avg',label:'Průměrná vlhkost',unit:'%',color:'#1565c0',beginAtZero:true},
 {key:'co2_avg',label:'Průměr CO₂',unit:'ppm',color:'#757575',beginAtZero:true},
 {key:'water_total_l',label:'Voda zalitá celkem',unit:'L',color:'#0288d1',beginAtZero:true},
 {key:'liquid_fertilizer_ml',label:'Tekutá hnojiva celkem',unit:'ml',color:'#8d6e63',beginAtZero:true},
 {key:'dry_fertilizer_scoops',label:'Suchá hnojiva celkem',unit:'odměrek',color:'#2e7d32',beginAtZero:true},
 {key:'watering_ph_avg',label:'Průměr pH vody',unit:'pH',color:'#43a047',beginAtZero:false,ph:true},
 {key:'runoff_ph_avg',label:'Průměr pH výtoku',unit:'pH',color:'#ad1457',beginAtZero:false,ph:true},
 {key:'light_watts',label:'Příkon světel',unit:'W',color:'#f9a825',beginAtZero:true},
 {key:'airflow_watts_avg',label:'Průměrný příkon vzduchu',unit:'W',color:'#00897b',beginAtZero:true},
 {key:'problem_days_count',label:'Dny označené jako problém',unit:'dní',color:'#c62828',beginAtZero:true}
];
// Každý nakonfigurovaný květináč dostane vlastní trvale zobrazený graf vody.
// Původní graf „Voda zalitá celkem“ zůstává mezi běžně volitelnými grafy.
const potWaterGraphColors = ['#00838f','#6a1b9a','#ef6c00','#2e7d32','#c62828','#3949ab','#8d6e63','#0277bd'];
const growreportPotWaterMetrics = (Array.isArray(potsConfig) ? potsConfig.slice() : [])
 .sort((a,b) => Number(a.pot_number) - Number(b.pot_number))
 .map((pot, index) => {
  const potNumber = Number(pot.pot_number);
  return {
   key:'pot_' + potNumber + '_cumulative_l',
   label:getPotDisplayName(potNumber) + ' – Zalito celkem',
   unit:'L', color:potWaterGraphColors[index % potWaterGraphColors.length], beginAtZero:true
  };
 });
let growreportChartInstances = [];
let growreportGraphBuilt = false;

function openGrowreportGraph() {
 const overlay = document.getElementById('growreport-graph-overlay');
 if (!overlay) { alert('Chybí okno grafu. Nahraj prosím celou opravenou stránku.'); return; }
 overlay.style.display = 'flex';
 if (!growreportGraphBuilt) { buildGrowreportGraphFilters(); buildGrowreportWeekSummaries(); growreportGraphBuilt = true; }
 renderGrowreportGraphCharts();
}
function closeGrowreportGraph() {
 const overlay = document.getElementById('growreport-graph-overlay'); if (overlay) overlay.style.display = 'none';
}
function placeGrowreportGraphButton() {
 const button = document.getElementById('open-growreport-graph');
 const nav = document.querySelector('.header-container nav');
 if (!button || !nav) return;
 const list = nav.querySelector('ul');
 if (!list) return;
 const items = Array.from(list.querySelectorAll('li'));
 const galleryItem = items.find(li => /galerie/i.test(li.textContent || '') || /galerie/i.test(li.querySelector('a')?.getAttribute('href') || ''));
 const li = document.createElement('li'); li.appendChild(button);
 if (galleryItem) galleryItem.insertAdjacentElement('afterend', li); else list.appendChild(li);
}
document.addEventListener('DOMContentLoaded', placeGrowreportGraphButton);

function buildGrowreportGraphFilters() {
 const wrap = document.getElementById('gr-graph-filters'); if (!wrap) return;
 wrap.innerHTML = '';
 // V seznamu výběru zobrazit také samostatný graf vody pro každý nakonfigurovaný květináč.
 const allMetrics = growreportGraphMetrics.concat(growreportPotWaterMetrics);
 allMetrics.forEach((metric, i) => {
  const label = document.createElement('label'); label.className = 'gr-graph-filter';
  const input = document.createElement('input'); input.type = 'checkbox'; input.checked = true; input.value = metric.key; input.id = 'gr-graph-filter-' + i;
  input.addEventListener('change', renderGrowreportGraphCharts);
  const text = document.createElement('span'); text.textContent = metric.label;
  label.appendChild(input); label.appendChild(text); wrap.appendChild(label);
 });
}
function setGrowreportGraphSelection(checked) {
 document.querySelectorAll('#gr-graph-filters input[type="checkbox"]').forEach(input => { input.checked = !!checked; });
 renderGrowreportGraphCharts();
}
function growreportFormatValue(value, decimals = 1) {
 if (value === null || value === undefined || value === '' || !Number.isFinite(Number(value))) return '—';
 return Number(value).toLocaleString('cs-CZ', {maximumFractionDigits:decimals});
}
function renderGrowreportGraphCharts() {
 const grid = document.getElementById('gr-graph-chart-grid'); const error = document.getElementById('gr-graph-error');
 if (!grid) return;
 growreportChartInstances.forEach(chart => { try { chart.destroy(); } catch(e) {} }); growreportChartInstances = [];
 grid.innerHTML = '';
 if (error) { error.style.display = 'none'; error.textContent = ''; }
 const selectedKeys = Array.from(document.querySelectorAll('#gr-graph-filters input[type="checkbox"]:checked')).map(input => input.value);
 // Vybrané grafy zahrnují běžné statistiky i jednotlivé nakonfigurované květináče.
 const allMetrics = growreportGraphMetrics.concat(growreportPotWaterMetrics);
 const selected = allMetrics.filter(metric => selectedKeys.includes(metric.key));
 if (!selected.length) {
  const empty = document.createElement('div'); empty.className = 'gr-graph-empty';
  empty.textContent = 'Vyber alespoň jeden údaj, který chceš zobrazit. Graf doby svícení světel je zobrazen samostatně níže.';
  grid.appendChild(empty);
 }
 if (typeof window.Chart !== 'function') {
  if (error) { error.style.display='block'; error.textContent='Nepodařilo se načíst knihovnu grafů (Chart.js). Zkontroluj připojení k internetu nebo blokování CDN. Týdenní souhrny jsou dostupné níže.'; }
  grid.innerHTML = '<div class="gr-graph-empty">Grafy nejsou dostupné, ale souhrn týdnů níže zůstává k dispozici.</div>'; return;
 }
 selected.forEach(metric => {
  const card = document.createElement('section'); card.className = 'gr-graph-chart-card';
  const title = document.createElement('h3'); title.textContent = metric.label + ' (' + metric.unit + ')'; card.appendChild(title);
  const canvasWrap = document.createElement('div'); canvasWrap.className = 'gr-graph-canvas-wrap';
  const canvas = document.createElement('canvas'); canvas.setAttribute('aria-label', title.textContent); canvasWrap.appendChild(canvas); card.appendChild(canvasWrap); grid.appendChild(card);
  const source = Array.isArray(graphWeeksData) ? graphWeeksData : [];
  const values = source.map(week => { const value=week[metric.key]; return value===null || value===undefined || value==='' || !Number.isFinite(Number(value)) ? null : Number(value); });
  const labels = source.map(week => 'Týden ' + week.week);
  const yScale = {beginAtZero:!!metric.beginAtZero, title:{display:true,text:metric.unit}, ticks:{callback:value => growreportFormatValue(value, metric.ph ? 1 : 2)}};
  if (metric.ph) { yScale.min = 1; yScale.max = 10; }
  const chart = new Chart(canvas.getContext('2d'), {
   type:'line',
   data:{labels, datasets:[{label:metric.label, data:values, borderColor:metric.color, backgroundColor:metric.color+'22', fill:true, tension:.25, spanGaps:false, pointRadius:3, pointHoverRadius:5, borderWidth:2}]},
   options:{responsive:true, maintainAspectRatio:false, interaction:{mode:'index',intersect:false}, plugins:{legend:{display:false}, tooltip:{callbacks:{label:ctx=>metric.label+': '+growreportFormatValue(ctx.parsed.y, metric.ph ? 1 : 2)+' '+metric.unit}}}, scales:{x:{title:{display:true,text:'Týdny'}},y:yScale}}
  });
  growreportChartInstances.push(chart);
 });
 // Grafy květináčů se nyní vykreslují podle výběru nahoře, aby šly zapínat/vypínat
 // stejně jako ostatní veličiny a aby se nekreslily dvakrát.
 renderLightHoursChart(grid);
}

// Samostatné grafy kumulativní vody – jeden graf pro každý nakonfigurovaný květináč.
// Nejsou závislé na zaškrtnutí běžných filtrů, takže zůstávají viditelné vedle původního grafu vody.
function renderPotWaterCharts(grid) {
 const source = Array.isArray(graphWeeksData) ? graphWeeksData : [];
 if (!source.length || !growreportPotWaterMetrics.length || typeof window.Chart !== 'function') return;
 const heading = document.createElement('h3');
 heading.className = 'gr-graph-section-heading';
 heading.style.cssText = 'grid-column:1 / -1;margin:8px 0 0;color:#37474f;font-size:16px;';
 heading.textContent = 'Zalito celkem – jednotlivé květináče';
 grid.appendChild(heading);
 growreportPotWaterMetrics.forEach(metric => {
  const card = document.createElement('section'); card.className = 'gr-graph-chart-card';
  const title = document.createElement('h3'); title.textContent = metric.label + ' (' + metric.unit + ')'; card.appendChild(title);
  const canvasWrap = document.createElement('div'); canvasWrap.className = 'gr-graph-canvas-wrap';
  const canvas = document.createElement('canvas'); canvas.setAttribute('aria-label', title.textContent); canvasWrap.appendChild(canvas); card.appendChild(canvasWrap); grid.appendChild(card);
  const values = source.map(week => {
   const value = week[metric.key];
   return value === null || value === undefined || value === '' || !Number.isFinite(Number(value)) ? null : Number(value);
  });
  const labels = source.map(week => 'Týden ' + week.week);
  const chart = new Chart(canvas.getContext('2d'), {
   type:'line',
   data:{labels, datasets:[{label:metric.label, data:values, borderColor:metric.color, backgroundColor:metric.color+'22', fill:true, tension:.25, spanGaps:false, pointRadius:3, pointHoverRadius:5, borderWidth:2}]},
   options:{responsive:true, maintainAspectRatio:false, interaction:{mode:'index',intersect:false}, plugins:{legend:{display:false}, tooltip:{callbacks:{label:ctx=>metric.label+': '+growreportFormatValue(ctx.parsed.y, 2)+' '+metric.unit}}}, scales:{x:{title:{display:true,text:'Týdny'}},y:{beginAtZero:true,title:{display:true,text:metric.unit},ticks:{callback:value=>growreportFormatValue(value, 2)}}}}
  });
  growreportChartInstances.push(chart);
 });
}

// Samostatný graf odpracovaných hodin pro každé použité světlo podle jednotlivých týdnů.
// lightsData už obsahuje účinné nastavení pro každý den, včetně přenosu poslední změny dopředu.
function renderLightHoursChart(grid) {
 const source = Array.isArray(graphWeeksData) ? graphWeeksData : [];
 if (!source.length) return;
 const weekTotals = [];
 const usedKeys = new Set();
 const addHour = (totals, key, value) => {
  if (value === null || value === undefined || value === '' || !Number.isFinite(Number(value))) return;
  const hours = Number(value);
  if (hours < 0) return;
  totals[key] = (totals[key] || 0) + hours;
  usedKeys.add(key);
 };
 source.forEach(week => {
  const weekNo = Number(week.week);
  const dailySettings = (lightsData && (lightsData[String(weekNo)] || lightsData[weekNo])) || {};
  const totals = {};
  for (let day = 1; day <= 7; day++) {
   const dayLights = dailySettings[String(day)] || dailySettings[day] || [];
   if (!Array.isArray(dayLights)) continue;
   dayLights.forEach(light => {
    if (!light || typeof light !== 'object') return;
    const name = String(light.name || '').trim();
    if (!name) return;
    if (name === 'LED') {
     // LED má oddělený čas pro minimální a maximální výkon; vykazujeme je samostatně.
     addHour(totals, 'LED_MIN', light.min_hours);
     addHour(totals, 'LED_MAX', light.max_hours);
    } else {
     addHour(totals, name, light.hours);
    }
   });
  }
  weekTotals.push({week: weekNo, totals});
 });

 const definitions = [
  {key:'HPS', label:'HPS', color:'#ef6c00'},
  {key:'MH', label:'MH', color:'#039be5'},
  {key:'CMH/LEC', label:'CMH/LEC', color:'#f9a825'},
  {key:'UVA/UVB', label:'UVA/UVB', color:'#8e24aa'},
  {key:'LED_MIN', label:'LED – min. výkon', color:'#00897b'},
  {key:'LED_MAX', label:'LED – max. výkon', color:'#004d40'},
  {key:'CFL', label:'CFL', color:'#fbc02d'},
  {key:'T5', label:'T5', color:'#9e9d24'},
  {key:'Plazmová výbojka', label:'Plazmová výbojka', color:'#6d4c41'}
 ];
 const datasets = definitions.filter(def => usedKeys.has(def.key)).map(def => ({
  label:def.label,
  data:weekTotals.map(row => Math.round((row.totals[def.key] || 0) * 100) / 100),
  borderColor:def.color,
  backgroundColor:def.color + '22',
  fill:false,
  tension:.2,
  pointRadius:3,
  pointHoverRadius:5,
  borderWidth:2
 }));
 if (!datasets.length) {
  const empty = document.createElement('div'); empty.className = 'gr-graph-empty';
  empty.textContent = 'Zatím nejsou zaznamenána žádná světla s nastavenou dobou svícení.';
  grid.appendChild(empty);
  return;
 }
 const card = document.createElement('section'); card.className = 'gr-graph-chart-card';
 const title = document.createElement('h3'); title.textContent = 'Doba svícení jednotlivých světel podle týdne (hodiny za týden)'; card.appendChild(title);
 const note = document.createElement('p'); note.className = 'gr-graph-intro';
 note.textContent = 'Součet nastavených hodin za všech 7 dní každého týdne. U LED jsou časy minimálního a maximálního výkonu zobrazené zvlášť.';
 card.appendChild(note);
 const canvasWrap = document.createElement('div'); canvasWrap.className = 'gr-graph-canvas-wrap';
 const canvas = document.createElement('canvas'); canvas.setAttribute('aria-label', title.textContent); canvasWrap.appendChild(canvas); card.appendChild(canvasWrap); grid.appendChild(card);
 const chart = new Chart(canvas.getContext('2d'), {
  type:'line',
  data:{labels:weekTotals.map(row => 'Týden ' + row.week), datasets},
  options:{
   responsive:true,
   maintainAspectRatio:false,
   interaction:{mode:'index',intersect:false},
   plugins:{legend:{display:true,position:'bottom'},tooltip:{callbacks:{label:ctx=>ctx.dataset.label+': '+growreportFormatValue(ctx.parsed.y, 1)+' h'}}},
   scales:{x:{title:{display:true,text:'Týdny'}},y:{beginAtZero:true,title:{display:true,text:'Hodin za týden'},ticks:{callback:value=>growreportFormatValue(value, 1)}}}
  }
 });
 growreportChartInstances.push(chart);
}
function buildGrowreportWeekSummaries() {
 const wrap = document.getElementById('gr-week-summary-list'); if (!wrap) return;
 wrap.innerHTML = '';
 if (!Array.isArray(graphWeeksData) || !graphWeeksData.length) { wrap.innerHTML = '<div class="gr-graph-empty">Growreport zatím nemá vytvořené týdny.</div>'; return; }
 graphWeeksData.forEach(week => {
  const card = document.createElement('section'); card.className='gr-week-summary-card';
  const phaseColors = {green:'#2e7d32', red:'#c62828', pink:'#d81b60', gray:'#78909c'};
  const phaseColor = phaseColors[week.phase_color] || phaseColors.gray; card.style.setProperty('--phase', phaseColor);
  const bar = document.createElement('div'); bar.className='gr-week-summary-phasebar'; card.appendChild(bar);
  const inner = document.createElement('div'); inner.className='gr-week-summary-inner';
  const top = document.createElement('div'); top.className='gr-week-summary-top';
  const title = document.createElement('strong'); title.textContent='Týden '+week.week; top.appendChild(title);
  const phase = document.createElement('span'); phase.className='gr-week-phase-label'; phase.textContent=week.phase || 'Bez fáze'; phase.style.borderColor=phaseColor; top.appendChild(phase);
  if (week.is_flower && week.flowering_weeks) { const flower=document.createElement('span'); flower.className='gr-flower-duration'; flower.textContent='Doba květu: '+week.flowering_weeks+'. týden'; top.appendChild(flower); }
  inner.appendChild(top);
  const stats = document.createElement('div'); stats.className='gr-week-summary-stats';
  const statItems = [
   ['Průměrná teplota', growreportFormatValue(week.temperature_avg,1)+' °C'],
   ['Průměrná vlhkost', growreportFormatValue(week.humidity_avg,1)+' %'],
   ['Průměrné CO₂', growreportFormatValue(week.co2_avg,0)+' ppm'],
   ['Zalito celkem', growreportFormatValue(week.water_total_l,1)+' L'],
   ['Hnojiva celkem', growreportFormatValue(week.liquid_fertilizer_ml,0)+' ml + '+growreportFormatValue(week.dry_fertilizer_scoops,0)+' odměrek'],
   ['pH vody / výtoku', growreportFormatValue(week.watering_ph_avg,1)+' / '+growreportFormatValue(week.runoff_ph_avg,1)],
   ['Příkon světel', week.light_watts===null ? '—' : growreportFormatValue(week.light_watts,0)+' W'],
   ['Příkon vzduchu (průměr)', week.airflow_watts_avg===null ? '—' : growreportFormatValue(week.airflow_watts_avg,0)+' W'],
   ['Dny s problémem', growreportFormatValue(week.problem_days_count,0)]
  ];
  statItems.forEach(item => { const span=document.createElement('span'); const strong=document.createElement('strong'); strong.textContent=item[0]+': '; span.appendChild(strong); const value=document.createElement('b'); value.className='gr-week-summary-value'; value.textContent=item[1]; span.appendChild(value); stats.appendChild(span); });
  if (Array.isArray(week.lights_names) && week.lights_names.length) { const span=document.createElement('span'); const strong=document.createElement('strong'); strong.textContent='Světla: '; span.appendChild(strong); const value=document.createElement('b'); value.className='gr-week-summary-value'; value.textContent=week.lights_names.join(', '); span.appendChild(value); stats.appendChild(span); }
  inner.appendChild(stats); card.appendChild(inner); wrap.appendChild(card);
 });
}
(function setupGrowreportGraphDismiss() {
 document.addEventListener('click', function(event) { const overlay=document.getElementById('growreport-graph-overlay'); if (overlay && event.target===overlay) closeGrowreportGraph(); });
 document.addEventListener('keydown', function(event) { if (event.key==='Escape') closeGrowreportGraph(); });
})();

function wKey(v) { return String(v); }

  function postJson(action, payload) {
    return fetch(API + encodeURIComponent(action), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(payload || {})
    }).then(async (response) => {
        const raw = await response.text();
        let data;
        try {
            data = JSON.parse(raw);
        } catch (e) {
            console.error('Neplatná odpověď API:', action, response.status, raw);
            const clean = raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().substring(0, 180);
            throw new Error('Server nevrátil platnou JSON odpověď (HTTP ' + response.status + '). ' + (clean || 'Zkontroluj PHP chybu a adresu stránky.'));
        }
        if (!response.ok) {
            throw new Error((data && data.message) ? data.message : ('Požadavek selhal (HTTP ' + response.status + ').'));
        }
        return data;
    });
}

    // ========== INIT ==========
    document.addEventListener("DOMContentLoaded", function() {
        // Výběr počátečního data synchronizuje den v týdnu, aby datum a názvy dnů souhlasily.
        const calendarDateInput = document.getElementById('calendar-start-date');
        if (calendarDateInput) calendarDateInput.addEventListener('change', syncWeekdayFromCalendarDate);
        document.querySelectorAll('input[name="calendar-first-weekday"]').forEach(function(radio) {
            radio.addEventListener('change', syncDateFromWeekday);
        });
        const fullMoonHelp = document.getElementById('full-moon-help');
        if (fullMoonHelp && calendarStartDate) fullMoonHelp.textContent = 'Začátek: ' + formatIsoDateCz(calendarStartDate) + '. Dny s úplňkem označí toto tlačítko.';

        // Denní úkoly předvyplň z uložené zálivky a hnojiv, aby odpovídaly aktuálním datům.
        initializeDailyTaskCards();

        // Nejdřív vykresli dny. Tato část nesmí záviset na inicializaci novějších dialogů.
        <?php foreach ($weeks as $week): ?>
            <?php if ($calendarFirstWeekday !== '' || !empty($week['start_day'])): ?>
                updateDays(<?= (int)$week['week_number'] ?>);
            <?php endif; ?>
        <?php endforeach; ?>
        // Obnov vzhled obou tlačítek podle uloženého stavu úplňků.
        syncFullMoonButtons();

        // Ostatní dialogy inicializuj až po vykreslení dnů.
        var tempCancel = document.getElementById('temp-cancel');
        var tempOk = document.getElementById('temp-ok');
        var humidityCancel = document.getElementById('humidity-cancel');
        var humidityOk = document.getElementById('humidity-ok');
        if (tempCancel) tempCancel.onclick = closeTemperature;
        if (tempOk) tempOk.onclick = saveTemperature;
        if (humidityCancel) humidityCancel.onclick = closeHumidity;
        if (humidityOk) humidityOk.onclick = saveHumidity;
        ['humidity-value1', 'humidity-value2'].forEach(id => {
            var input = document.getElementById(id);
            if (input) input.addEventListener('input', function() {
                this.value = this.value.replace(',', '.').replace(/[^0-9.]/g, '').replace(/(\..*)\./g, '$1').replace(/^(\d{0,3})(\.\d?).*$/, '$1$2');
            });
        });

        document.getElementById('cancel-delete').addEventListener('click', hideDeleteConfirmation);
        document.getElementById('confirm-delete').addEventListener('click', confirmDeleteGrowreport);
        document.getElementById('cancel-delete-week').addEventListener('click', hideDeleteWeekConfirmation);
        document.getElementById('confirm-delete-week').addEventListener('click', confirmDeleteWeek);

        // CO2
        document.getElementById('co2-cancel').addEventListener('click', closeCo2);
        document.getElementById('co2-ok').addEventListener('click', saveCo2);
        ['co2-value1', 'co2-value2'].forEach(id => {
            document.getElementById(id).addEventListener('input', function() {
                this.value = this.value.replace(/\D/g, '').slice(0, 4);
            });
        });

        // CO2 tip: reagovat pouze na skutečná tlačítka CO2, nikoli na teplotu či vlhkost.
        document.addEventListener('mouseover', function(e) {
            const btn = e.target.closest ? e.target.closest('.co2-cell .co2-btn') : null;
            if (!btn || (btn.id && btn.id.startsWith('temp-')) || btn.classList.contains('humidity-btn')) return;
            showCo2Tip(btn);
        });
        document.addEventListener('mouseout', function(e) {
            const btn = e.target.closest ? e.target.closest('.co2-cell .co2-btn') : null;
            if (!btn || (btn.id && btn.id.startsWith('temp-')) || btn.classList.contains('humidity-btn')) return;
            if (!(e.relatedTarget && btn.contains(e.relatedTarget))) hideCo2Tip();
        });
        window.addEventListener('scroll', hideCo2Tip, true);

        // Teplota: zvětšené okénko používá stejnou barvu jako malé hodnoty (18–38 °C zeleně, jinak červeně).
        document.addEventListener('mouseover', function(e) {
            const btn = e.target.closest ? e.target.closest('.co2-cell .co2-btn[id^="temp-"]') : null;
            if (btn) showTempTip(btn);
        });
        document.addEventListener('mouseout', function(e) {
            const btn = e.target.closest ? e.target.closest('.co2-cell .co2-btn[id^="temp-"]') : null;
            if (btn && !(e.relatedTarget && btn.contains(e.relatedTarget))) hideTempTip();
        });
        window.addEventListener('scroll', hideTempTip, true);

        // Vlhkost
        document.addEventListener('mouseover', function(e) {
            const btn = e.target.closest ? e.target.closest('.humidity-btn') : null;
            if (btn) showHumidityTip(btn);
        });
        document.addEventListener('mouseout', function(e) {
            const btn = e.target.closest ? e.target.closest('.humidity-btn') : null;
            if (btn && !(e.relatedTarget && btn.contains(e.relatedTarget))) hideHumidityTip();
        });
        window.addEventListener('scroll', hideHumidityTip, true);

        // Světla a vzduch: plovoucí zvětšené okno stejného typu jako Vlhkost.
        document.addEventListener('mouseover', function(e) {
            const lightBtn = e.target.closest ? e.target.closest('.lights-btn') : null;
            const airflowBtn = e.target.closest ? e.target.closest('.airflow-btn') : null;
            if (lightBtn) showLightsTip(lightBtn);
            else if (airflowBtn) showAirflowTip(airflowBtn);
        });
        document.addEventListener('mouseout', function(e) {
            const lightBtn = e.target.closest ? e.target.closest('.lights-btn') : null;
            const airflowBtn = e.target.closest ? e.target.closest('.airflow-btn') : null;
            if (lightBtn && !(e.relatedTarget && lightBtn.contains(e.relatedTarget))) hideLightsTip();
            if (airflowBtn && !(e.relatedTarget && airflowBtn.contains(e.relatedTarget))) hideAirflowTip();
        });
        window.addEventListener('scroll', hideLightsTip, true);
        window.addEventListener('scroll', hideAirflowTip, true);

        // Pots dialog
        document.getElementById('pots-cancel').addEventListener('click', closePotsConfig);
        document.getElementById('pots-save').addEventListener('click', savePotsConfig);

        // Watering dialog
        document.getElementById('watering-cancel').addEventListener('click', closeWatering);
        document.getElementById('watering-ok').addEventListener('click', saveWatering);
        document.getElementById('watering-ph-value').addEventListener('change', function() { updateWateringPhStyle('watering-ph-value', 'watering-ph-saved', 'water'); });
        document.getElementById('watering-runoff-ph-value').addEventListener('change', function() { updateWateringPhStyle('watering-runoff-ph-value', 'watering-runoff-ph-saved', 'runoff'); });

        // Lights configuration dialog: bind safely so a missing light control cannot break page init.
        [
            ['lights-cancel', closeLights],
            ['lights-next', moveToLightValues],
            ['lights-back', backToLightSelection],
            ['lights-save', saveLights]
        ].forEach(function(pair) {
            var control = document.getElementById(pair[0]);
            if (control) control.addEventListener('click', pair[1]);
            else if (window.showLightsDebug) window.showLightsDebug('Na stránce chybí ovládací prvek #' + pair[0] + '.');
        });

        [
            ['airflow-cancel', closeAirflow],
            ['airflow-next', moveToAirflowValues],
            ['airflow-back', backToAirflowCounts],
            ['airflow-save', saveAirflow]
        ].forEach(function(pair) {
            var control = document.getElementById(pair[0]);
            if (control) control.addEventListener('click', pair[1]);
            else console.error('Na stránce chybí ovládací prvek #' + pair[0] + '.');
        });
    });



    // ========== POT CONFIG ==========
    function openPotsConfig() {
        const countSel = document.getElementById('pots-count');
        countSel.value = potsConfig.length > 0 ? potsConfig.length : 1;
        renderPotRows();
        document.getElementById('pots-dialog').style.display = 'flex';
    }

    function closePotsConfig() {
        document.getElementById('pots-dialog').style.display = 'none';
    }

    function renderPotRows() {
        const count = parseInt(document.getElementById('pots-count').value);
        const container = document.getElementById('pots-rows');
        container.innerHTML = '';
        for (let i = 1; i <= count; i++) {
            const existing = potsConfig.find(p => Number(p.pot_number) === i);
            const vol = existing ? existing.volume : 10;
            const savedName = existing && typeof existing.pot_name === 'string' ? existing.pot_name : '';
            const row = document.createElement('div');
            row.className = 'pot-row';

            const nameLabel = document.createElement('label');
            nameLabel.className = 'pot-name-label';
            nameLabel.htmlFor = 'pot-name-' + i;
            nameLabel.textContent = 'Název ' + i + ':';

            const nameInput = document.createElement('input');
            nameInput.type = 'text';
            nameInput.id = 'pot-name-' + i;
            nameInput.className = 'pot-name-input';
            nameInput.maxLength = 100;
            nameInput.value = savedName;
            nameInput.placeholder = 'Květináč ' + i;
            nameInput.autocomplete = 'off';

            const volumeLabel = document.createElement('label');
            volumeLabel.className = 'pot-volume-label';
            volumeLabel.htmlFor = 'pot-vol-' + i;
            volumeLabel.textContent = 'Objem:';

            const volumeInput = document.createElement('input');
            volumeInput.type = 'number';
            volumeInput.id = 'pot-vol-' + i;
            volumeInput.className = 'pot-volume-input';
            volumeInput.step = '0.5';
            volumeInput.min = '0.5';
            volumeInput.max = '100';
            volumeInput.value = vol;

            const volumeUnit = document.createElement('span');
            volumeUnit.textContent = 'L';

            row.appendChild(nameLabel);
            row.appendChild(nameInput);
            row.appendChild(volumeLabel);
            row.appendChild(volumeInput);
            row.appendChild(volumeUnit);
            container.appendChild(row);
        }
    }

    function savePotsConfig() {
        const count = parseInt(document.getElementById('pots-count').value);
        const pots = [];
        for (let i = 1; i <= count; i++) {
            const input = document.getElementById('pot-vol-' + i);
            const nameInput = document.getElementById('pot-name-' + i);
            let vol = parseFloat(input.value) || 0;
            vol = Math.round(vol * 2) / 2;
            if (vol < 0.5) vol = 0.5;
            if (vol > 100) vol = 100;
            const potName = nameInput ? Array.from(String(nameInput.value || '').trim()).slice(0, 100).join('') : '';
            pots.push({ pot_number: i, volume: vol, pot_name: potName });
        }

        postJson('save_pots', { pots: pots })
        .then(data => {
            if (data.success) {
                potsConfig = pots;
                rebuildGrowreportPotWaterMetrics();
                // Překreslit názvy v již zobrazených denních plánech; data a stavy zůstávají stejné.
                document.querySelectorAll('.daily-task-card').forEach(function(card) {
                    const week = Number(card.dataset.week);
                    const day = Number(card.dataset.day);
                    if (week >= 0 && day > 0 && typeof updateDailyTaskNote === 'function') {
                        updateDailyTaskNote(week, day, true);
                    }
                });
                // Pokud je okno grafů otevřené, aktualizovat seznam grafů a jejich názvy.
                const graphOverlay = document.getElementById('growreport-graph-overlay');
                if (graphOverlay && graphOverlay.style.display === 'flex') {
                    buildGrowreportGraphFilters();
                    buildGrowreportWeekSummaries();
                    growreportGraphBuilt = true;
                    renderGrowreportGraphCharts();
                }
                closePotsConfig();
            } else {
                alert(data.message || 'Chyba při ukládání.');
            }
        })
        .catch(err => {
            console.error('Chyba:', err);
            alert('Nastala chyba.');
        });
    }

    // ========== WATERING ==========
    function wateringPhColors(value) {
        if (value === '' || value === null || value === undefined || !Number.isFinite(Number(value))) {
            return { color: '#333333', background: '#ffffff', label: 'Vyber pH v rozsahu 1 až 10.' };
        }
        const ph = Math.max(1, Math.min(10, Number(value)));
        const stops = [
            {v:1,c:[190,0,0],name:'červená'}, {v:2,c:[225,35,0],name:'červeno-oranžová'},
            {v:3,c:[245,105,0],name:'oranžová'}, {v:4,c:[255,180,0],name:'oranžovo-žlutá'},
            {v:5,c:[245,225,0],name:'žlutá'}, {v:6,c:[170,215,35],name:'žlutozelená'},
            {v:7,c:[35,160,65],name:'zelená'}, {v:8,c:[0,150,145],name:'tyrkysová'},
            {v:9,c:[30,105,205],name:'modrá'}, {v:10,c:[100,55,170],name:'fialová'}
        ];
        const lo = stops[Math.floor(ph)-1], hi = stops[Math.min(9, Math.ceil(ph)-1)];
        const t = ph - Math.floor(ph);
        const rgb = lo.c.map((c,i)=>Math.round(c+(hi.c[i]-c)*t));
        const background = 'rgb(' + rgb.join(',') + ')';
        const luminance = (0.299*rgb[0]+0.587*rgb[1]+0.114*rgb[2]);
        const color = luminance < 145 ? '#ffffff' : '#202020';
        return { color: color, background: background, label: 'Barva odpovídá orientační stupnici univerzálního indikátoru pH.' };
    }

    function updateWateringPhStyle(selectId, noteId, kind) {
        const select = document.getElementById(selectId);
        const note = document.getElementById(noteId);
        if (!select) return;
        const colors = wateringPhColors(select.value);
        select.style.color = colors.color;
        select.style.backgroundColor = colors.background;
        if (note) {
            const prefix = kind === 'runoff' ? 'pH výtokové vody: ' : 'pH vody: ';
            note.textContent = select.value === '' ? colors.label : prefix + select.value + '. ' + colors.label;
        }
    }

    function openWatering(week, day) {
    if (potsConfig.length === 0) {
        openPotsConfig();
        return;
    }

    wateringCtx = { week: week, day: day };
    const dayNum = week * 7 - (7 - day);
    document.getElementById('watering-dialog-title').innerText = 'Zalévání – Den ' + dayNum;

    const wkForPh = String(week);
    const dyForPh = String(day);
    const phSelect = document.getElementById('watering-ph-value');
    const runoffPhSelect = document.getElementById('watering-runoff-ph-value');
    const savedPh = (wateringPHData[wkForPh] && wateringPHData[wkForPh][dyForPh] !== undefined) ? String(wateringPHData[wkForPh][dyForPh]) : '';
    const savedRunoffPh = (wateringRunoffPHData[wkForPh] && wateringRunoffPHData[wkForPh][dyForPh] !== undefined) ? String(wateringRunoffPHData[wkForPh][dyForPh]) : '';
    if (phSelect) phSelect.value = savedPh;
    if (runoffPhSelect) runoffPhSelect.value = savedRunoffPh;
    const savedRunoffLiters = (wateringRunoffLitersData[wkForPh] && wateringRunoffLitersData[wkForPh][dyForPh] !== undefined) ? Number(wateringRunoffLitersData[wkForPh][dyForPh]) : 0;
    const runoffLitersPicker = document.getElementById('watering-runoff-liters-picker');
    if (runoffLitersPicker) initRunoffLitersPicker(runoffLitersPicker, savedRunoffLiters);
    updateWateringPhStyle('watering-ph-value', 'watering-ph-saved', 'water');
    updateWateringPhStyle('watering-runoff-ph-value', 'watering-runoff-ph-saved', 'runoff');

    const row = document.getElementById('watering-pots-row');
    row.innerHTML = '';

    // Dialog nejprve zobrazíme. Prohlížeč pak správně změří výšku válců
    // a zachová jejich počáteční scroll pozici i při prvním otevření.
    const wateringDialog = document.getElementById('watering-dialog');
    if (wateringDialog) wateringDialog.style.display = 'flex';

    const existingDay = getSavedWateringAmounts(week, day);

    potsConfig.forEach(function(pot) {
        const card = document.createElement('div');
        card.className = 'pot-card';

        const lbl = document.createElement('div');
        lbl.className = 'pot-lbl';
        lbl.textContent = getPotDisplayName(pot.pot_number);
        lbl.title = getPotDisplayName(pot.pot_number);

        const vol = document.createElement('div');
        vol.className = 'pot-vol';
        vol.textContent = pot.volume + 'L';

        const picker = document.createElement('div');
        picker.className = 'sp-wrap';
        picker.id = 'sp-pot-' + pot.pot_number;

        card.appendChild(lbl);
        card.appendChild(vol);
        card.appendChild(picker);
        row.appendChild(card);

        const potKey = String(pot.pot_number);
        const initVal = (existingDay[potKey] !== undefined && existingDay[potKey] !== null)
            ? parseFloat(existingDay[potKey])
            : 0;

        initScrollPicker(picker, pot.volume, 0.5, initVal);
    });

   
    updateWateringTotal();
    renderSavedWateringRows(week, day);
}

    // Jednotné a odolné načtení uložených dávek: funguje pro číselné i textové
    // klíče týdnů/dnů/květináčů a pro číselné hodnoty vrácené databází.
    function getSavedWateringAmounts(week, day) {
        const allWeeks = (wateringData && typeof wateringData === 'object') ? wateringData : {};
        const weekKey = String(Number(week));
        const dayKey = String(Number(day));
        const weekData = allWeeks[weekKey] || allWeeks[Number(week)] || {};
        const dayData = weekData[dayKey] || weekData[Number(day)] || {};
        const amounts = {};

        if (!dayData || typeof dayData !== 'object') return amounts;
        Object.keys(dayData).forEach(function(key) {
            const potNumber = Number(key);
            const rawAmount = dayData[key];
            const amount = Number(String(rawAmount).replace(',', '.'));
            if (!Number.isInteger(potNumber) || potNumber < 1 || !Number.isFinite(amount) || amount < 0) return;
            amounts[String(potNumber)] = amount;
        });
        return amounts;
    }

    function closeWatering() {
        document.getElementById('watering-dialog').style.display = 'none';
        wateringCtx = null;
    }

    // ========== WATERING CALCULATOR ==========
    let wateringCalculatorPhase = 'growth';
    let wateringCalculatorStage = null;
    let wateringCalculatorRecommendations = null;
    let automaticWateringSchedule = null;

    function getWeekGrowthPhase(weekNumber) {
        const card = document.querySelector('.growreport[data-week="' + Number(weekNumber) + '"]');
        const phaseText = String(card && card.dataset ? (card.dataset.phase || '') : '')
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
        return phaseText.includes('kvet') ? 'flower' : 'growth';
    }

    function setWateringCalculatorPhase(phase) {
        wateringCalculatorPhase = phase === 'flower' ? 'flower' : 'growth';
        wateringCalculatorStage = null;
        document.getElementById('watering-calc-phase-growth').classList.toggle('active', wateringCalculatorPhase === 'growth');
        document.getElementById('watering-calc-phase-flower').classList.toggle('active', wateringCalculatorPhase === 'flower');
        const label = document.getElementById('watering-calc-stage-label');
        if (label) label.textContent = wateringCalculatorPhase === 'flower' ? 'Květ – vyber stupeň 1 až 10' : 'Růst – vyber stupeň 1 až 9';
        renderWateringCalculatorStages();
        const result = document.getElementById('watering-calc-result');
        if (result) result.innerHTML = '';
        const apply = document.getElementById('watering-calc-apply');
        if (apply) apply.style.display = 'none';
        wateringCalculatorRecommendations = null;
    }

    function makeWateringPlantSvg(phase, stage) {
        const flowering = phase === 'flower';
        const n = Math.max(1, Number(stage) || 1);
        const potTop = 92;
        const pot = '<path d="M36 91 L64 91 L59 109 Q50 113 41 109 Z" fill="#b86f3f" stroke="#804522" stroke-width="1.5" stroke-linejoin="round"/><ellipse cx="50" cy="91" rx="14" ry="3.4" fill="#563722"/><ellipse cx="50" cy="90.5" rx="11.5" ry="2" fill="#35291f"/><path d="M42 106 Q50 109 58 106" fill="none" stroke="#d69564" stroke-width="1.2"/>  <path d="M39 94 L42 106 M61 94 L58 106" stroke="#9b572f" stroke-width=".7" opacity=".7"/>';
        let svg = '<svg viewBox="0 0 100 116" role="img" aria-label="' + (flowering ? 'Konopí v květu' : 'Konopí v růstu') + ', stupeň ' + n + '" xmlns="http://www.w3.org/2000/svg">';
        svg += '<ellipse cx="50" cy="112" rx="19" ry="2.2" fill="#000" opacity=".09"/>';

        // Úzké, výrazně zubaté lístky v typickém dlanitém tvaru listu konopí.
        const leafletPath = 'M0 0 L-2.4 -3.8 L-6.8 -2.0 L-4.8 -6.5 L-9.4 -5.0 L-7.0 -9.6 L-4.6 -9.2 L0 -29 L4.6 -9.2 L7.0 -9.6 L9.4 -5.0 L4.8 -6.5 L6.8 -2.0 L2.4 -3.8 Z';
        function cannabisLeaf(x, y, size, rotation, baseColor) {
            const colorSet = [baseColor || '#2e873a', '#399747', '#2f8138', '#48a34e', '#287533', '#3d9141', '#2a7934'];
            const blades = [
                {angle:0, scale:1.00}, {angle:-34, scale:.84}, {angle:34, scale:.84},
                {angle:-61, scale:.68}, {angle:61, scale:.68},
                {angle:-84, scale:.54}, {angle:84, scale:.54}
            ];
            let out = '<g transform="translate(' + x.toFixed(2) + ' ' + y.toFixed(2) + ') rotate(' + rotation + ') scale(' + size.toFixed(3) + ')" stroke-linejoin="round">';
            blades.forEach(function(blade, i) {
                out += '<g transform="rotate(' + blade.angle + ') scale(' + blade.scale + ')">';
                out += '<path d="' + leafletPath + '" fill="' + colorSet[i] + '" stroke="#1e622b" stroke-width=".62"/>';
                out += '<path d="M0 -1 L0 -25" fill="none" stroke="#9bc96d" stroke-width=".75" stroke-linecap="round"/>';
                out += '</g>';
            });
            out += '<circle cx="0" cy="0" r="1.3" fill="#347b35" stroke="#1e622b" stroke-width=".5"/>';
            out += '</g>';
            return out;
        }

        // Vykreslí jednu zdravou rostlinu s bočními větvemi a dlanitými listy.
        // Flower používá stále stejnou velikost rostliny; mění se už jen palice.
        function plantBody(growthStage) {
            const s = Math.max(1, Math.min(9, growthStage));
            const topY = 82 - (s - 1) * 6.6;
            const sizeByStage = [0.22, 0.29, 0.39, 0.51, 0.66, 0.75, 0.84, 0.93, 1.02];
            const leafSize = sizeByStage[s - 1];
            const pairs = s <= 1 ? 0 : s === 2 ? 1 : s === 3 ? 2 : s === 4 ? 3 : s === 5 ? 4 : s === 6 ? 4 : s === 7 ? 5 : 6;
            let out = '<path d="M50 ' + potTop + ' C ' + (48.5 + s * .18).toFixed(1) + ' 77 ' + (51 - s * .12).toFixed(1) + ' ' + (topY + 11).toFixed(1) + ' 50 ' + topY.toFixed(1) + '" fill="none" stroke="#276b32" stroke-width="2.2" stroke-linecap="round"/>';

            if (s === 1) {
                // Úplně mladý semenáček: tenký stonek a dva děložní lístky.
                out += '<path d="M50 91 C50 87 50 83 50 80" fill="none" stroke="#34823a" stroke-width="1.8" stroke-linecap="round"/>';
                out += '<path d="M50 83 Q42 75 37 79 Q41 86 50 83 Z" fill="#59a94b" stroke="#2b7734" stroke-width=".8"/>';
                out += '<path d="M50 83 Q58 75 63 79 Q59 86 50 83 Z" fill="#4c9e45" stroke="#2b7734" stroke-width=".8"/>';
                out += '<path d="M50 83 L42 80 M50 83 L58 80" fill="none" stroke="#a6cf79" stroke-width=".7"/>';
                return out;
            }

            if (s === 2) {
                out += cannabisLeaf(50, topY + 4, leafSize * .90, 0, '#3b9744');
                out += '<path d="M50 86 Q45 81 43 78 M50 86 Q55 81 57 78" fill="none" stroke="#347c37" stroke-width="1.1"/>';
                out += '<ellipse cx="43" cy="80" rx="4.5" ry="2" transform="rotate(24 43 80)" fill="#5da94c" stroke="#2f7a36" stroke-width=".6"/><ellipse cx="57" cy="80" rx="4.5" ry="2" transform="rotate(-24 57 80)" fill="#5da94c" stroke="#2f7a36" stroke-width=".6"/>';
                return out;
            }

            // Délka postranních větví i velikost listů postupně rostou. Od stupně 5
            // přesahuje koruna šířku květináče a stupně 5–9 se dál rozšiřují.
            for (let i = 0; i < pairs; i++) {
                const fraction = (i + 1) / (pairs + 1);
                const y = 84 - fraction * (84 - (topY + 8));
                const stageSpread = s < 5 ? 4 + s * 1.25 : 7.5 + (s - 5) * 2.25;
                const outerFactor = .48 + .52 * fraction;
                const dx = stageSpread * outerFactor;
                const leafY = y + 1.5;
                const colorLeft = i % 2 ? '#32853a' : '#399746';
                const colorRight = i % 2 ? '#3b9844' : '#2c7f36';
                out += '<path d="M50 ' + (y + 6).toFixed(2) + ' Q ' + (50 - dx * .55).toFixed(2) + ' ' + (y + 2).toFixed(2) + ' ' + (50 - dx).toFixed(2) + ' ' + leafY.toFixed(2) + ' M50 ' + (y + 6).toFixed(2) + ' Q ' + (50 + dx * .55).toFixed(2) + ' ' + (y + 2).toFixed(2) + ' ' + (50 + dx).toFixed(2) + ' ' + leafY.toFixed(2) + '" fill="none" stroke="#2c7334" stroke-width="1.35" stroke-linecap="round"/>';
                const scale = leafSize * (0.83 + 0.18 * fraction);
                out += cannabisLeaf(50 - dx, leafY, scale, -54, colorLeft);
                out += cannabisLeaf(50 + dx, leafY, scale, 54, colorRight);
                if (s >= 5) {
                    // Menší vějířovité listy mezi patry zahušťují korunu, aby nepůsobila jako holá jehličnatá větvička.
                    out += cannabisLeaf(50 - dx * .56, leafY + 4.2, scale * .48, -35, '#31823a');
                    out += cannabisLeaf(50 + dx * .56, leafY + 4.2, scale * .48, 35, '#368c3d');
                }
            }
            // Vrcholový vějíř je dobře čitelný a stále typický pro konopí.
            out += cannabisLeaf(50, topY + 2, leafSize * (s >= 5 ? .70 : .90), 0, '#3b9845');
            if (s >= 4) {
                out += cannabisLeaf(50 - 5.8, topY + 13, leafSize * .66, -22, '#2d7e37');
                out += cannabisLeaf(50 + 5.8, topY + 13, leafSize * .66, 22, '#32863a');
            }
            return out;
        }

        if (!flowering) {
            // Rostoucí rostliny mají od stupně 5 postupně větší a širší korunu než květináč.
            svg += plantBody(n);
        } else {
            const flowerStage = Math.max(1, Math.min(10, n));
            // Květ 1 je nejmenší, květ 2 je o něco větší a od květu 3 zůstává
            // velikost celé rostliny stejná; dále se mění pouze palice.
            const flowerPlantScale = flowerStage === 1 ? 0.82 : (flowerStage === 2 ? 0.92 : 1);
            svg += '<g transform="translate(50 92) scale(' + flowerPlantScale + ') translate(-50 -92)">';
            svg += '<g transform="translate(50 92) scale(1.08 1.06) translate(-50 -92)">';
            svg += plantBody(9);
            svg += '</g>';
            const budPoints = [
                {x:50,y:25},{x:42,y:34},{x:58,y:34},{x:34,y:43},{x:66,y:43},
                {x:29,y:53},{x:71,y:53},{x:31,y:64},{x:69,y:64},{x:40,y:73},{x:60,y:73}
            ];
            // V každém stupni květu zůstává stejný počet palic jako u stupně 10,
            // rovnoměrně rozložených po celé rostlině; mění se pouze jejich velikost.
            const budCount = budPoints.length;
            const budSizeByStage = [0.8,1.45,2.0,2.55,3.15,3.75,4.35,4.9,5.5,6.1];
            const budBaseSize = budSizeByStage[flowerStage - 1];
            // Stupně květu 2–7 mají stejné velikosti palic, pouze stříbřitě bílé zbarvení.
            // Stupně 8–10 ponechávají původní zelené/oranžové zbarvení.
            const silverBudStage = flowerStage >= 2 && flowerStage <= 7;
            const budMainFill = silverBudStage ? '#e9edf2' : '#719b40';
            const budMainStroke = silverBudStage ? '#9ca8b5' : '#416d2c';
            const budHighlightFill = silverBudStage ? '#fbfcfd' : '#8eae51';
            const budVeinStroke = silverBudStage ? '#b6c0ca' : '#b4c975';
            const orangeRatio = flowerStage < 8 ? 0 : (flowerStage === 8 ? .40 : flowerStage === 9 ? .67 : .92);
            for (let i = 0; i < budCount; i++) {
                const b = budPoints[i];
                const size = budBaseSize * (i === 0 ? 1.18 : (i < 5 ? 1 : .88));
                // Kompaktní zelený květní shluk s kalichy a drobnými listenci.
                svg += '<g transform="translate(' + b.x + ' ' + b.y + ')">';
                svg += '<path d="M0 ' + (size * 1.18).toFixed(2) + ' C ' + (-size * 1.12).toFixed(2) + ' ' + (size * .62).toFixed(2) + ' ' + (-size * .88).toFixed(2) + ' ' + (-size * .38).toFixed(2) + ' 0 ' + (-size * 1.35).toFixed(2) + ' C ' + (size * .9).toFixed(2) + ' ' + (-size * .45).toFixed(2) + ' ' + (size * 1.12).toFixed(2) + ' ' + (size * .65).toFixed(2) + ' 0 ' + (size * 1.18).toFixed(2) + ' Z" fill="' + budMainFill + '" stroke="' + budMainStroke + '" stroke-width=".75"/>';
                svg += '<path d="M' + (-size * .76).toFixed(2) + ' ' + (size * .28).toFixed(2) + ' Q ' + (-size * .35).toFixed(2) + ' ' + (-size * .85).toFixed(2) + ' 0 ' + (-size * 1.17).toFixed(2) + ' Q ' + (size * .52).toFixed(2) + ' ' + (-size * .52).toFixed(2) + ' ' + (size * .78).toFixed(2) + ' ' + (size * .32).toFixed(2) + ' Q0 ' + (size * .8).toFixed(2) + ' ' + (-size * .76).toFixed(2) + ' ' + (size * .28).toFixed(2) + 'Z" fill="' + budHighlightFill + '" opacity=".75"/>';
                svg += '<path d="M0 ' + (size * .8).toFixed(2) + ' L0 ' + (-size * .98).toFixed(2) + ' M' + (-size * .42).toFixed(2) + ' ' + (size * .4).toFixed(2) + ' L' + (size * .35).toFixed(2) + ' ' + (-size * .55).toFixed(2) + ' M' + (size * .42).toFixed(2) + ' ' + (size * .42).toFixed(2) + ' L' + (-size * .22).toFixed(2) + ' ' + (-size * .66).toFixed(2) + '" fill="none" stroke="' + budVeinStroke + '" stroke-width=".55"/>';
                // Oranžové pestíky se zřetelně objevují od stupně 7 a přibývají až do 10.
                const hairCount = Math.round(orangeRatio * 18);
                for (let h = 0; h < hairCount; h++) {
                    const angle = (h / Math.max(1, hairCount)) * Math.PI * 2 + (i % 3) * .18;
                    const x1 = Math.cos(angle) * size * .27;
                    const y1 = Math.sin(angle) * size * .33;
                    const x2 = Math.cos(angle - .25) * size * 1.18;
                    const y2 = Math.sin(angle - .25) * size * 1.22 - size * .15;
                    svg += '<path d="M' + x1.toFixed(2) + ' ' + y1.toFixed(2) + ' Q' + (x2 * .55).toFixed(2) + ' ' + (y2 * .55).toFixed(2) + ' ' + x2.toFixed(2) + ' ' + y2.toFixed(2) + '" fill="none" stroke="' + (h % 4 === 0 ? '#ffad48' : '#e87526') + '" stroke-width=".72" stroke-linecap="round"/>';
                }
                // Na dozrávajících palicích je více světlých oranžových špiček.
                if (flowerStage >= 8) {
                    for (let h = 0; h < flowerStage - 6; h++) {
                        const xx = ((h % 3) - 1) * size * .42;
                        const yy = -size * (.58 + .18 * (h % 2));
                        svg += '<path d="M' + xx.toFixed(2) + ' ' + yy.toFixed(2) + ' l' + (xx < 0 ? -size * .45 : size * .45).toFixed(2) + ' ' + (-size * .62).toFixed(2) + '" fill="none" stroke="#f5a14a" stroke-width=".8" stroke-linecap="round"/>';
                    }
                }
                svg += '</g>';
            }
            svg += '</g>'; // Společné měřítko květu 1 a 2; květináč zůstává stejně velký.
        }
        svg += pot + '</svg>';
        return svg;
    }

    function renderWateringCalculatorStages() {
        const grid = document.getElementById('watering-calc-stage-grid');
        if (!grid) return;
        const flowering = wateringCalculatorPhase === 'flower';
        const maxStage = flowering ? 10 : 9;
        const growthNames = ['Semenáček','Větší semenáček','Malá rostlinka','Větší rostlinka','Rostlinka','Ještě větší','Malé křoví','Větší křoví','Velké křoví'];
        const flowerNames = ['Velký keř','Náznaky palic','Malé palice','Větší palice','Výraznější palice','Rostoucí palice','Pestíky oranžoví','Většina pestíků oranžová','Mnoho oranžových pestíků','Nejzralejší palice'];
        const names = flowering ? flowerNames : growthNames;
        grid.classList.toggle('flower-grid', flowering);
        grid.innerHTML = '';
        for (let stage = 1; stage <= maxStage; stage++) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'watering-calc-stage-btn' + (flowering ? ' flower-stage' : '');
            button.dataset.stage = String(stage);
            button.setAttribute('aria-pressed', 'false');
            button.setAttribute('aria-label', names[stage - 1] + ', stupeň ' + stage);
            button.innerHTML = makeWateringPlantSvg(wateringCalculatorPhase, stage) + '<span class="watering-calc-stage-num">' + stage + '</span><span class="watering-calc-stage-name"></span>';
            button.querySelector('.watering-calc-stage-name').textContent = names[stage - 1];
            button.addEventListener('click', function() {
                wateringCalculatorStage = Number(this.dataset.stage);
                // U růstových obrázků jde současně o počáteční týdenní stupeň celého Growreportu.
                grid.querySelectorAll('.watering-calc-stage-btn').forEach(function(el) {
                    const selected = Number(el.dataset.stage) === wateringCalculatorStage;
                    el.classList.toggle('selected', selected);
                    el.setAttribute('aria-pressed', selected ? 'true' : 'false');
                });
                const result = document.getElementById('watering-calc-result');
                if (result) result.innerHTML = '';
                const apply = document.getElementById('watering-calc-apply');
                if (apply) apply.style.display = 'none';
                wateringCalculatorRecommendations = null;
                resetAutoWateringSchedulePreview();
            });
            grid.appendChild(button);
        }
    }

    function renderWateringCalculatorVolumes() {
        const container = document.getElementById('watering-calc-volume-rows');
        if (!container) return;
        container.innerHTML = '';
        if (!Array.isArray(potsConfig) || !potsConfig.length) {
            container.textContent = 'Nejdříve nastav alespoň jeden květináč.';
            return;
        }
        potsConfig.slice().sort(function(a, b) { return Number(a.pot_number) - Number(b.pot_number); }).forEach(function(pot) {
            const row = document.createElement('div');
            row.className = 'watering-calc-volume-row';
            const label = document.createElement('label');
            label.htmlFor = 'watering-calc-volume-' + pot.pot_number;
            label.textContent = getPotDisplayName(pot.pot_number) + ' (L)';
            const input = document.createElement('input');
            input.type = 'number';
            input.id = 'watering-calc-volume-' + pot.pot_number;
            input.min = '0.5'; input.max = '100'; input.step = '0.5';
            input.value = String(Number(pot.volume || 10));
            input.setAttribute('inputmode', 'decimal');
            input.addEventListener('input', function() {
                const result = document.getElementById('watering-calc-result');
                if (result) result.innerHTML = '';
                const apply = document.getElementById('watering-calc-apply');
                if (apply) apply.style.display = 'none';
                wateringCalculatorRecommendations = null;
                renderWateringFrequencyTables();
                resetAutoWateringSchedulePreview();
            });
            row.appendChild(label); row.appendChild(input); container.appendChild(row);
        });
    }

    // Základní dávky pro interval 5 dní (na 20 l substrátu).
    // Při kratším intervalu zůstává celkový rozpočet každé fáze stejný;
    // rozpočítá se pouze do většího počtu menších zálivek.
    const wateringCalcPhaseDefinitions = [
        {key:'growth1', label:'Růst 1–4', start:1, end:28, baseTotal:6, baseRegularEventCount:5, fixedFirstDose:1, baseDose:1},
        {key:'growth2', label:'Růst 5–9', start:29, end:63, baseTotal:14, baseRegularEventCount:7, fixedFirstDose:0, baseDose:2},
        {key:'flower1', label:'Květ 1–7', start:64, end:112, baseTotal:30, baseRegularEventCount:10, fixedFirstDose:0, baseDose:3},
        {key:'flower2', label:'Květ 8–9', start:113, end:126, baseTotal:12, baseRegularEventCount:3, fixedFirstDose:0, baseDose:4},
        {key:'flower3', label:'Květ 10', start:127, end:133, baseTotal:10, baseRegularEventCount:2, fixedFirstDose:0, baseDose:5}
    ];

    let currentWateringHistoryData = null;
    let savedWateringHistoryProfiles = [];

    function renderWateringHistorySnapshot(data) {
        const summary = document.getElementById('watering-calc-history-summary');
        const table = document.getElementById('watering-calc-history-table');
        if (summary) summary.innerHTML = '';
        if (table) table.innerHTML = '';
            const fmt = function(value, digits) {
                if (value === null || value === undefined || !Number.isFinite(Number(value))) return '—';
                return Number(value).toLocaleString('cs-CZ', {minimumFractionDigits:0, maximumFractionDigits:digits === undefined ? 2 : digits});
            };
            const cards = [
                ['Celkem zalito', fmt(data.total_water_l, 2) + ' l'],
                ['Objem substrátu celkem', fmt(data.substrate_total_l, 1) + ' l'],
                ['Voda na 1 l substrátu', data.water_per_substrate_l === null ? '—' : fmt(data.water_per_substrate_l, 3) + ' l'],
                ['Počet zalévacích dnů', fmt(data.watering_count, 0)],
                ['Průměrný interval', data.average_interval_days === null ? '—' : fmt(data.average_interval_days, 2) + ' dní']
            ];
            if (summary) {
                const grid = document.createElement('div');
                grid.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:8px;';
                cards.forEach(function(item) {
                    const card = document.createElement('div');
                    card.style.cssText = 'background:#fff;border:1px solid #bfdbfe;border-radius:7px;padding:9px;';
                    const label = document.createElement('div'); label.style.cssText = 'font-size:12px;color:#475569;'; label.textContent = item[0];
                    const value = document.createElement('strong'); value.style.cssText = 'display:block;font-size:17px;color:#1e3a8a;margin-top:3px;'; value.textContent = item[1];
                    card.appendChild(label); card.appendChild(value); grid.appendChild(card);
                });
                summary.appendChild(grid);
                const potHeading = document.createElement('div'); potHeading.style.cssText = 'margin-top:10px;font-weight:700;'; potHeading.textContent = 'Celková spotřeba podle květináče'; summary.appendChild(potHeading);
                const potLine = document.createElement('div'); potLine.style.cssText = 'font-size:13px;margin-top:4px;';
                const potParts = (data.pots || []).map(function(pot) {
                    const total = data.pot_totals && data.pot_totals[String(pot.pot_number)] !== undefined ? data.pot_totals[String(pot.pot_number)] : 0;
                    return 'Květináč ' + pot.pot_number + ' (' + fmt(pot.volume,1) + ' l substrátu): ' + fmt(total,2) + ' l vody';
                });
                potLine.textContent = potParts.length ? potParts.join(' · ') : 'V Growreportu nejsou nastavené objemy květináčů.';
                summary.appendChild(potLine);
            }
            if (table) {
                if (!Array.isArray(data.events) || !data.events.length) {
                    table.textContent = 'V tomto Growreportu zatím nejsou uložené žádné záznamy zalévání.';
                } else {
                    const tbl = document.createElement('table');
                    tbl.style.cssText = 'width:100%;border-collapse:collapse;background:#fff;font-size:13px;';
                    const thead = document.createElement('thead'); const hr = document.createElement('tr');
                    ['Týden','Datum','Den týdne'].concat((data.pots || []).map(function(p){return 'Květináč ' + p.pot_number;}), ['Celkem za den']).forEach(function(label) {
                        const th = document.createElement('th'); th.textContent = label; th.style.cssText = 'padding:7px;border:1px solid #cbd5e1;background:#dbeafe;white-space:nowrap;'; hr.appendChild(th);
                    });
                    thead.appendChild(hr); tbl.appendChild(thead);
                    const tbody = document.createElement('tbody');
                    data.events.forEach(function(event) {
                        const tr = document.createElement('tr');
                        const dateText = event.date ? new Date(event.date + 'T12:00:00').toLocaleDateString('cs-CZ') : '—';
                        [event.week, dateText, event.day].concat((data.pots || []).map(function(p){ return fmt(event.amounts && event.amounts[String(p.pot_number)] !== undefined ? event.amounts[String(p.pot_number)] : 0, 2) + ' l'; }), [fmt(event.total,2) + ' l']).forEach(function(value) {
                            const td = document.createElement('td'); td.textContent = value; td.style.cssText = 'padding:7px;border:1px solid #cbd5e1;text-align:center;white-space:nowrap;'; tr.appendChild(td);
                        });
                        tbody.appendChild(tr);
                    });
                    tbl.appendChild(tbody); table.appendChild(tbl);
                    const weeklyHeading = document.createElement('div'); weeklyHeading.style.cssText = 'font-weight:700;margin:12px 0 5px;'; weeklyHeading.textContent = 'Souhrn podle týdnů'; table.appendChild(weeklyHeading);
                    const weeklyTable = document.createElement('table'); weeklyTable.style.cssText = 'width:100%;border-collapse:collapse;background:#fff;font-size:13px;';
                    const wh = document.createElement('tr'); ['Týden','Počet zalévacích dnů','Voda celkem'].forEach(function(x){const th=document.createElement('th');th.textContent=x;th.style.cssText='padding:7px;border:1px solid #cbd5e1;background:#dbeafe;';wh.appendChild(th);});
                    const wthead=document.createElement('thead');wthead.appendChild(wh);weeklyTable.appendChild(wthead);
                    const wbody=document.createElement('tbody');(data.weekly||[]).forEach(function(w){const tr=document.createElement('tr');[w.week,w.events,fmt(w.total,2)+' l'].forEach(function(v){const td=document.createElement('td');td.textContent=v;td.style.cssText='padding:7px;border:1px solid #cbd5e1;text-align:center;';tr.appendChild(td);});wbody.appendChild(tr);});weeklyTable.appendChild(wbody);table.appendChild(weeklyTable);
                }
            }
    }

    function readSavedWateringHistoryProfiles() {
        return Array.isArray(savedWateringHistoryProfiles) ? savedWateringHistoryProfiles : [];
    }

    function refreshSavedWateringHistorySelect(selectedId) {
        const select = document.getElementById('watering-calc-saved-history-select');
        if (!select) return Promise.resolve();
        select.innerHTML = '';
        const placeholder = document.createElement('option'); placeholder.value = ''; placeholder.textContent = '— Vyber společně uloženou historii —'; select.appendChild(placeholder);
        return postJson('watering_profiles_list', {}).then(function(result) {
            if (!result || !result.success) throw new Error(result && result.message ? result.message : 'Společné historie se nepodařilo načíst.');
            savedWateringHistoryProfiles = Array.isArray(result.profiles) ? result.profiles : [];
            savedWateringHistoryProfiles.forEach(function(profile) {
                const option = document.createElement('option'); option.value = String(profile.id);
                option.textContent = String(profile.name || 'Uložený Growreport') + ' · ' + new Date(profile.savedAt || Date.now()).toLocaleDateString('cs-CZ') + ' · Growreport #' + String(profile.sourceReportId || '—');
                select.appendChild(option);
            });
            if (selectedId) select.value = String(selectedId);
        }).catch(function(error) {
            const status = document.getElementById('watering-calc-history-status');
            if (status) status.textContent = 'Společné uložené historie se nepodařilo načíst: ' + error.message;
        });
    }

    function saveCurrentWateringHistorySnapshot() {
        if (!currentWateringHistoryData || !currentWateringHistoryData.success) {
            alert('Nejdříve načti historii zalévání z tohoto Growreportu.'); return;
        }
        const suggested = String(currentWateringHistoryData.report_name || reportName || ('Growreport ' + reportId));
        const name = window.prompt('Pod jakým názvem chceš tuto historii uložit do společné databáze?', suggested);
        if (name === null) return;
        const cleanName = String(name).trim();
        if (!cleanName) { alert('Zadej název uložené historie.'); return; }
        const baseline = buildWateringHistoryBaseline(currentWateringHistoryData);
        if (!baseline || !baseline.phaseDoses || !Object.keys(baseline.phaseDoses).length) {
            alert('Z této historie nelze vytvořit dávkovací základ.'); return;
        }
        const button = document.getElementById('watering-calc-save-history');
        if (button) { button.disabled = true; button.textContent = 'Ukládám do databáze…'; }
        postJson('watering_profiles_save', {name: cleanName, data: currentWateringHistoryData, baseline: baseline}).then(function(result) {
            if (!result || !result.success) throw new Error(result && result.message ? result.message : 'Historii se nepodařilo uložit.');
            return refreshSavedWateringHistorySelect(result.id).then(function() {
                const status = document.getElementById('watering-calc-history-status');
                if (status) status.textContent = 'Historie „' + cleanName + '“ byla uložena do společné databáze a je dostupná ostatním uživatelům.';
            });
        }).catch(function(error) {
            alert('Historii se nepodařilo uložit do společné databáze: ' + error.message);
        }).finally(function() {
            if (button) { button.disabled = false; button.textContent = 'Uložit historii Growreportu'; }
        });
    }

    function applySelectedWateringHistorySnapshot() {
        const sourceToggle = document.getElementById('watering-calc-use-current-history');
        if (sourceToggle && sourceToggle.checked) {
            const status = document.getElementById('watering-calc-history-status');
            if (status) status.textContent = 'Pro použití uloženého Growreportu nejprve vypni volbu „Skutečná historie zalévání tohoto Growreportu“. ';
            return;
        }
        const select = document.getElementById('watering-calc-saved-history-select');
        const selectedId = select ? String(select.value || '') : '';
        const profile = readSavedWateringHistoryProfiles().find(function(item) { return String(item.id) === selectedId; });
        if (!profile || !profile.baseline || !profile.data) {
            const status = document.getElementById('watering-calc-history-status');
            if (status) status.textContent = 'Vyber platnou uloženou historii Growreportu.';
            return;
        }
        currentWateringHistoryData = profile.data;
        // Základnu vždy znovu sestavíme z uložených skutečných událostí.
        // Tím se opraví i starší záznamy uložené před doplněním dávek podle jednotlivých stupňů.
        const rebuiltBaseline = buildWateringHistoryBaseline(profile.data);
        historyWateringRules = rebuiltBaseline || profile.baseline;
        renderWateringHistorySnapshot(profile.data);
        renderWateringFrequencyTables();
        resetAutoWateringSchedulePreview();
        const status = document.getElementById('watering-calc-history-status');
        if (status) status.textContent = 'Načtena uložená historie Growreportu „' + String(profile.name || '') + '“. Historické záznamy jsou zobrazeny v přehledu; dávkovací tabulka zachovává původní dávky pro interval 5 dní a při kratším intervalu rozděluje stejný celkový objem do menších dávek.';
    }

    function handleWateringHistorySourceChange() {
        const sourceToggle = document.getElementById('watering-calc-use-current-history');
        if (sourceToggle && sourceToggle.checked) {
            loadCurrentGrowreportWateringHistory();
        } else {
            applySelectedWateringHistorySnapshot();
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function(){
        refreshSavedWateringHistorySelect();
        loadCurrentGrowreportWateringHistory();
    });
    else {
        refreshSavedWateringHistorySelect();
        loadCurrentGrowreportWateringHistory();
    }

    function loadCurrentGrowreportWateringHistory() {
        const button = document.getElementById('watering-calc-load-history');
        const status = document.getElementById('watering-calc-history-status');
        const summary = document.getElementById('watering-calc-history-summary');
        const table = document.getElementById('watering-calc-history-table');
        if (button) { button.disabled = true; button.textContent = 'Načítám historii…'; }
        // Při návratu na skutečnou historii nesmí zůstat aktivní pravidla z dříve vybraného Growreportu.
        historyWateringRules = null;
        try { localStorage.removeItem('growreport_watering_history_rules_v1_' + reportId); } catch (e) {}
        if (status) status.textContent = 'Načítám skutečně uložené zálivky ze všech týdnů tohoto Growreportu…';
        if (summary) summary.innerHTML = '';
        if (table) table.innerHTML = '';
        postJson('watering_history', {}).then(function(data) {
            if (!data || !data.success) throw new Error(data && data.message ? data.message : 'Historii zalévání se nepodařilo načíst.');
            currentWateringHistoryData = data;
            renderWateringHistorySnapshot(data);
            // Historii načteme a zobrazíme; základní dávky tabulky zůstávají zachované.
            const baseline = buildWateringHistoryBaseline(data);
            if (baseline && Object.keys(baseline.phaseDoses).length) {
                historyWateringRules = baseline;
                localStorage.setItem('growreport_watering_history_rules_v1_' + reportId, JSON.stringify(baseline));
                const budgetHelp = document.querySelector('.watering-calc-consumption-wrap > p.watering-calc-help');
                if (budgetHelp) budgetHelp.textContent = 'Celková spotřeba vychází z původních dávek při zalévání každých 5 dní. Při kratším intervalu se stejný objem vody v každé fázi rozdělí do většího počtu menších dávek. Hodnoty se přepočítávají podle objemu substrátu z bodu 3.';
            }
            renderWateringFrequencyTables();
            resetAutoWateringSchedulePreview();
            if (status) status.textContent = 'Načteno ' + (data.events ? data.events.length : 0) + ' zalévacích dnů. Historie je zobrazena jako přehled; dávkovací tabulka zachovává původní dávky pro interval 5 dní a při kratším intervalu rozděluje stejný celkový objem do menších dávek. Původní záznamy nebyly změněny.';
        }).catch(function(error) {
            if (status) status.textContent = 'Chyba při načítání historie: ' + error.message;
            console.error('Načtení historie zalévání Growreportu:', error);
        }).finally(function() {
            if (button) { button.disabled = false; button.textContent = 'Načíst historii zalévání z tohoto Growreportu'; }
        });
    }

    function wateringCalcFloorTenth(value) {
        return Math.max(0, Math.floor((Number(value) + 1e-8) * 10) / 10);
    }

    function getWateringDoseRatesForStage(phase, stage) {
        const stageNumber = Number(stage);
        if (phase === 'growth') {
            return stageNumber <= 4
                ? {perTenLiters: 0.5, perOneLiter: 0.1}
                : {perTenLiters: 1, perOneLiter: 0.2};
        }
        if (phase === 'flower') {
            if (stageNumber <= 7) return {perTenLiters: 1.5, perOneLiter: 0.3};
            if (stageNumber <= 9) return {perTenLiters: 2, perOneLiter: 0.4};
            return {perTenLiters: 2.5, perOneLiter: 0.5};
        }
        return null;
    }

    function calculateWateringAmountForPot(volume, legacyDose, phase, stage) {
        const substrateLiters = Number(volume);
        if (!Number.isFinite(substrateLiters) || substrateLiters <= 0) return 0;
        // Vstupní dávka je již vypočítaná podle rozpočtu fáze a intervalu;
        // pro jiný objem květináče ji pouze poměrně přepočítáme.
        return Math.max(0, Number(legacyDose || 0)) * (substrateLiters / 20);
    }

    function wateringCalcFormatLiters(value) {
        const rounded = Math.round(Number(value || 0) * 10) / 10;
        return rounded.toLocaleString('cs-CZ', {minimumFractionDigits:0, maximumFractionDigits:1}) + ' L';
    }

    function wateringCalcFormatDoseLiters(value) {
        const amount = Math.max(0, Number(value || 0));
        return amount.toLocaleString('cs-CZ', {minimumFractionDigits:0, maximumFractionDigits:2}) + ' L';
    }

    function getWateringRoundingMode() {
        const halfOption = document.getElementById('watering-calc-round-half-liters');
        const wholeOption = document.getElementById('watering-calc-round-whole-liters');
        if (wholeOption && wholeOption.checked) return 'whole';
        if (halfOption && halfOption.checked) return 'half';
        return 'none';
    }

    function roundWateringDose(value, roundingMode) {
        const dose = Math.max(0, Number(value || 0));
        // Dávky pod 1 litr nezaokrouhlujeme na 0,5 ani 1 litr.
        if (dose < 1 || roundingMode === 'none') return wateringCalcFloorTenth(dose);
        const step = roundingMode === 'whole' ? 1 : 0.5;
        return Math.max(0, Math.round((dose / step) + 1e-8) * step);
    }

    let automaticWateringScheduleFrequency = 4;
    let importedWateringRules = {};
    try { importedWateringRules = JSON.parse(localStorage.getItem('growreport_watering_rules_v1') || '{}') || {}; } catch (e) { importedWateringRules = {}; }
    // Historická základna je oddělená pro každý Growreport a přežije obnovení stránky.
    let historyWateringRules = null;
    try {
        const savedHistoryRules = JSON.parse(localStorage.getItem('growreport_watering_history_rules_v1_' + reportId) || 'null');
        if (savedHistoryRules && savedHistoryRules.phaseDoses && typeof savedHistoryRules.phaseDoses === 'object') historyWateringRules = savedHistoryRules;
    } catch (e) { historyWateringRules = null; }

    function buildWateringHistoryBaseline(data) {
        const pots = Array.isArray(data && data.pots) ? data.pots : [];
        const events = Array.isArray(data && data.events) ? data.events : [];
        const substrateByPot = Object.create(null);
        pots.forEach(function(pot) {
            const volume = Number(pot.volume || 0);
            if (volume > 0) substrateByPot[String(pot.pot_number)] = volume;
        });
        if (!events.length || !Object.keys(substrateByPot).length) return null;

        // Historii vždy zařazujeme podle jejího původního kalendáře od Růstu 1.
        // Kliknutý obrázek mění pouze začátek nového rozpisu, nikoli význam
        // historických týdnů, ze kterých se učí dávky jednotlivých stupňů.
        const phaseInfo = getAutomaticWateringWeekStages(1);
        const sums = Object.create(null);
        const counts = Object.create(null);
        const stageSums = Object.create(null);
        const stageCounts = Object.create(null);
        let firstDose20Sum = 0;
        let firstDosePotCount = 0;
        const globalDays = [];
        events.forEach(function(event) {
            const globalDay = ((Number(event.week) - 1) * 7) + Number(event.day);
            if (Number.isFinite(globalDay)) globalDays.push(globalDay);
            const stageInfo = phaseInfo.weeks[Number(event.week)];
            if (!stageInfo || stageInfo.phase === 'dry') return;
            let eventDose20Sum = 0;
            let eventPotCount = 0;
            Object.keys(substrateByPot).forEach(function(potNumber) {
                const amount = Number(event.amounts && event.amounts[potNumber] !== undefined ? event.amounts[potNumber] : 0);
                const volume = substrateByPot[potNumber];
                if (amount > 0 && volume > 0) {
                    eventDose20Sum += amount * 20 / volume;
                    eventPotCount++;
                }
            });
            if (eventPotCount <= 0) return;
            const eventDose20 = eventDose20Sum / eventPotCount;
            // První skutečná zálivka má vlastní historickou dávku; nesmí se nahrazovat obecným pravidlem 5 l / 20 l.
            if (globalDay === 1) {
                firstDose20Sum += eventDose20;
                firstDosePotCount++;
                return;
            }
            const phaseKey = getWateringPhasePlanKey(stageInfo.phase, stageInfo.stage);
            if (!sums[phaseKey]) { sums[phaseKey] = 0; counts[phaseKey] = 0; }
            sums[phaseKey] += eventDose20;
            counts[phaseKey]++;
            // Vedle souhrnné fáze ukládáme i přesný stupeň (např. Květ 8, 9 a 10 zvlášť),
            // aby se při generování rozpisu nezprůměrovaly odlišné historické dávky.
            const stageKey = stageInfo.phase + ':' + Number(stageInfo.stage);
            if (!stageSums[stageKey]) { stageSums[stageKey] = 0; stageCounts[stageKey] = 0; }
            stageSums[stageKey] += eventDose20;
            stageCounts[stageKey]++;
        });
        globalDays.sort(function(a,b){ return a-b; });
        const intervals = [];
        for (let i = 1; i < globalDays.length; i++) intervals.push(globalDays[i] - globalDays[i-1]);
        const calculatedInterval = intervals.length ? intervals.reduce(function(a,b){return a+b;},0) / intervals.length : Number(data.average_interval_days || 5);
        const referenceIntervalDays = Number.isFinite(calculatedInterval) && calculatedInterval > 0 ? calculatedInterval : 5;
        const phaseDoses = Object.create(null);
        const phaseTotals20 = Object.create(null);
        Object.keys(sums).forEach(function(key) {
            if (counts[key] > 0) {
                phaseDoses[key] = Math.round((sums[key] / counts[key]) * 1000) / 1000;
                phaseTotals20[key] = Math.round(sums[key] * 1000) / 1000;
            }
        });
        const stageDoses = Object.create(null);
        Object.keys(stageSums).forEach(function(key) {
            if (stageCounts[key] > 0) stageDoses[key] = Math.round((stageSums[key] / stageCounts[key]) * 1000) / 1000;
        });
        if (!Object.keys(phaseDoses).length && firstDosePotCount <= 0) return null;
        return {
            reportId: Number(reportId),
            referenceIntervalDays: Math.round(referenceIntervalDays * 1000) / 1000,
            firstDose20: firstDosePotCount > 0 ? Math.round((firstDose20Sum / firstDosePotCount) * 1000) / 1000 : null,
            phaseDoses: phaseDoses,
            phaseTotals20: phaseTotals20,
            stageDoses: stageDoses,
            sampleCounts: counts,
            stageSampleCounts: stageCounts,
            loadedAt: new Date().toISOString()
        };
    }

    function getWaterDoseForFrequency(definition, frequency) {
        const interval = Number(frequency);
        const phaseKey = String(definition && definition.key || '');
        if (![2, 3, 4, 5].includes(interval) || !phaseKey) {
            throw new Error('Nelze určit interval nebo fázi pro dávku zalití.');
        }
        // Automatický rozpis používá stejnou dávku, jaká je zobrazena v tabulce níže.
        const importedKey = String(interval) + '|' + phaseKey;
        const importedDose = importedWateringRules[importedKey];
        if (importedDose !== undefined && Number.isFinite(Number(importedDose)) && Number(importedDose) >= 0) {
            return Number(importedDose);
        }

        // Pokud historie pro konkrétní fázi neobsahuje žádné zalévání, použije se stávající tabulková hodnota.
        // Nebereme starší pevné hodnoty ani výchozí dávku pro čtyřdenní interval.
        // Importovaná pravidla se do této tabulky promítnou při jejím vykreslení,
        // takže kalkulačka i automatický rozpis čtou totožnou výslednou hodnotu.
        const doseCells = Array.from(document.querySelectorAll(
            '.watering-calc-consumption-table td[data-frequency="' + interval + '"][data-phase-dose="' + phaseKey + '"]'
        ));
        if (doseCells.length !== 1) {
            throw new Error('V tabulce „Dávka na jedno zalití“ chybí jednoznačná dávka pro interval ' + interval + ' dní a fázi ' + phaseKey + '.');
        }
        const rawText = String(doseCells[0].textContent || '').trim();
        const normalized = rawText.replace(/\s/g, '').replace(/l$/i, '').replace(',', '.');
        const tableDose = Number(normalized);
        if (!Number.isFinite(tableDose) || tableDose < 0) {
            throw new Error('Dávka v tabulce pro interval ' + interval + ' dní a fázi ' + phaseKey + ' není platné číslo.');
        }
        const referenceVolume = (getWateringCalculatorPotVolumes()[0] || {}).volume || 20;
        return tableDose / (referenceVolume / 20);
    }
    function initWateringDataImport() {
        const fileInput = document.getElementById('watering-calc-data-file');
        const status = document.getElementById('watering-calc-data-status');
        const exportButton = document.getElementById('watering-calc-export-data');
        if (fileInput && !fileInput.dataset.bound) {
            fileInput.dataset.bound = '1';
            fileInput.addEventListener('change', function() {
                const file = fileInput.files && fileInput.files[0]; if (!file) return;
                const reader = new FileReader();
                reader.onload = function() {
                    try {
                        let rows = [];
                        const content = String(reader.result || '');
                        if (/\.json$/i.test(file.name)) {
                            const parsed = JSON.parse(content);
                            if (Array.isArray(parsed)) { rows = parsed; } else if (Array.isArray(parsed.rules)) { rows = parsed.rules; } else if (parsed.rules && typeof parsed.rules === 'object') { rows = Object.keys(parsed.rules).map(function(key){ const parts=key.split('|'); return {interval_days:parts[0], phase:parts[1], dose_l_per_20l:parsed.rules[key]}; }); } else { rows = []; }
                        } else {
                            const lines = content.replace(/^\uFEFF/, '').split(/\r?\n/).filter(function(line){return line.trim()!=='';});
                            if (lines.length < 2) throw new Error('Soubor neobsahuje žádné řádky s daty.');
                            const delimiter = lines[0].indexOf(';') >= 0 ? ';' : ',';
                            const headers = lines.shift().split(delimiter).map(function(v){return v.trim().toLowerCase();});
                            rows = lines.map(function(line){const values=line.split(delimiter);const obj={};headers.forEach(function(h,i){obj[h]=(values[i]||'').trim();});return obj;});
                        }
                        const phaseAliases = {growth1:'growth1','růst 1–6':'growth1','rust 1-6':'growth1',growth2:'growth2','růst 7–9':'growth2','rust 7-9':'growth2',flower1:'flower1','květ 1–2':'flower1','kvet 1-2':'flower1',flower2:'flower2','květ 3–7':'flower2','kvet 3-7':'flower2',flower3:'flower3','květ 8–10':'flower3','kvet 8-10':'flower3'};
                        const next = {};
                        rows.forEach(function(row){
                            const interval = Number(row.interval_days || row.frequency || row.interval);
                            const phaseRaw = String(row.phase || row.phase_key || '').trim().toLowerCase();
                            const phase = phaseAliases[phaseRaw] || phaseRaw;
                            const dose = Number(String(row.dose_l_per_20l || row.dose || row.liters || '').replace(',','.'));
                            if (![2,3,4,5].includes(interval) || !['growth1','growth2','flower1','flower2','flower3'].includes(phase) || !Number.isFinite(dose) || dose < 0 || dose > 20) throw new Error('Neplatný řádek. Zkontroluj interval_days, phase a dose_l_per_20l.');
                            next[String(interval)+'|'+phase] = dose;
                        });
                        if (!Object.keys(next).length) throw new Error('V souboru nejsou platná pravidla zálivky.');
                        importedWateringRules = next; localStorage.setItem('growreport_watering_rules_v1', JSON.stringify(next));
                        if (status) status.textContent = 'Načteno a uloženo '+Object.keys(next).length+' pravidel zálivky. Pravidla se použijí v dalších výpočtech.';
                        renderWateringFrequencyTables(); resetAutoWateringSchedulePreview();
                    } catch (error) { if (status) status.textContent = 'Import se nepodařil: '+error.message; }
                };
                reader.readAsText(file, 'UTF-8');
            });
        }
        if (exportButton && !exportButton.dataset.bound) {
            exportButton.dataset.bound='1'; exportButton.addEventListener('click',function(){
                const blob=new Blob([JSON.stringify({rules:importedWateringRules},null,2)],{type:'application/json'});
                const url=URL.createObjectURL(blob); const a=document.createElement('a'); a.href=url; a.download='growreport_pravidla_zalivky.json'; a.click(); URL.revokeObjectURL(url);
            });
        }
    }

    function getWateringFrequencyPlan(frequency, roundingMode) {
        frequency = [2,3,4,5].includes(Number(frequency)) ? Number(frequency) : 5;
        roundingMode = ['half','whole'].includes(roundingMode) && frequency < 5 ? roundingMode : 'none';
        const phases = wateringCalcPhaseDefinitions.map(function(definition) {
            const durationDays = definition.end - definition.start + 1;
            const eventCount = Math.max(1, Math.ceil(durationDays / frequency));
            const hasFirstDose = definition.key === 'growth1';
            const firstDose = hasFirstDose ? Math.max(0, Number(definition.fixedFirstDose || 0)) : 0;

            // Pevný rozpočet fáze vychází z původní dávky při zalévání každých 5 dní.
            // První den cyklu zůstává samostatně 1 l / 20 l substrátu.
            const phaseBudget = Math.max(0, Number(definition.baseTotal || 0));
            const regularEventCount = Math.max(0, eventCount - (hasFirstDose ? 1 : 0));
            const remainingBudget = Math.max(0, phaseBudget - firstDose);
            const dose = regularEventCount > 0 ? remainingBudget / regularEventCount : 0;
            return Object.assign({}, definition, {
                eventCount: eventCount,
                regularEventCount: regularEventCount,
                firstDose: firstDose,
                dose: dose,
                targetTotal: phaseBudget,
                total: phaseBudget
            });
        });
        const totalLiters = Math.round(phases.reduce(function(sum, phase) { return sum + phase.total; }, 0) * 10) / 10;
        return {frequency:frequency, phases:phases, totalLiters:totalLiters, roundingMode:roundingMode, roundWholeLiters:roundingMode === 'whole', roundHalfLiters:roundingMode === 'half'};
    }

    function getWateringCalculatorPotVolumes() {
        const volumes = [];
        (Array.isArray(potsConfig) ? potsConfig : []).forEach(function(pot) {
            const input = document.getElementById('watering-calc-volume-' + pot.pot_number);
            const raw = input ? Number(String(input.value).replace(',', '.')) : Number(pot.volume || 0);
            if (Number.isFinite(raw) && raw > 0) volumes.push({pot_number: Number(pot.pot_number), volume: raw});
        });
        return volumes;
    }

    function getWateringCalculatorTotalSubstrateLiters() {
        return getWateringCalculatorPotVolumes().reduce(function(sum, pot) { return sum + pot.volume; }, 0);
    }

    function renderWateringFrequencyTables() {
        const roundingMode = getWateringRoundingMode();
        const potVolumes = getWateringCalculatorPotVolumes();
        const totalSubstrateLiters = potVolumes.reduce(function(sum, pot) { return sum + pot.volume; }, 0);
        const substrateScale = totalSubstrateLiters > 0 ? totalSubstrateLiters / 20 : 1;
        const doseReferenceVolume = potVolumes.length ? potVolumes[0].volume : 20;
        const doseReferenceScale = doseReferenceVolume / 20;
        const doseTitle = document.getElementById('watering-calc-dose-title');
        if (doseTitle) doseTitle.textContent = 'Dávka na jedno zalití – květináč č. ' + (potVolumes[0] ? potVolumes[0].pot_number : '1') + ' s ' + doseReferenceVolume.toLocaleString('cs-CZ', {maximumFractionDigits:1}) + ' l substrátu' + (potVolumes.length > 1 ? ' (ostatní květináče se přepočítají podle svého objemu)' : '');
        const title = document.getElementById('watering-calc-consumption-title');
        if (title) title.textContent = 'Odhad spotřeby za 19 týdnů – ' +
            (potVolumes.length === 1 ? 'květináč s ' + totalSubstrateLiters.toLocaleString('cs-CZ', {maximumFractionDigits:1}) + ' l substrátu'
                : 'všechny květináče, celkem ' + totalSubstrateLiters.toLocaleString('cs-CZ', {maximumFractionDigits:1}) + ' l substrátu');
        const help = document.getElementById('watering-calc-consumption-help');
        if (help) help.textContent = 'Odhad spotřeby za 19 týdnů se počítá podle celkového objemu substrátu z bodu 3 (' +
            totalSubstrateLiters.toLocaleString('cs-CZ', {maximumFractionDigits:1}) +
            ' l celkem). Tabulka dávek níže zobrazuje dávku pro ' + doseReferenceVolume.toLocaleString('cs-CZ', {maximumFractionDigits:1}) +
            ' l substrátu; všechny hodnoty dávkové tabulky jsou přepočtené právě na tento objem. U ostatních květináčů se dávka přepočítá podle jejich vlastního objemu.';
        [5,4,3,2].forEach(function(frequency) {
            const plan = getWateringFrequencyPlan(frequency, roundingMode);
            document.querySelectorAll('.watering-calc-consumption-table [data-phase-budget][data-frequency="' + frequency + '"]').forEach(function(cell) {
                const key = cell.dataset.phaseBudget;
                const baseValue = key === 'total' ? plan.totalLiters : ((plan.phases.find(function(phase) { return phase.key === key; }) || {}).total || 0);
                cell.textContent = wateringCalcFormatLiters(baseValue * substrateScale);
            });
            document.querySelectorAll('.watering-calc-consumption-table [data-phase-dose][data-frequency="' + frequency + '"]').forEach(function(cell) {
                const key = cell.dataset.phaseDose;
                const phasePlan = plan.phases.find(function(phase) { return phase.key === key; });
                const amount = phasePlan ? phasePlan.dose * doseReferenceScale : 0;
                cell.textContent = wateringCalcFormatDoseLiters(amount);
            });
            const firstDayCell = document.querySelector('.watering-calc-consumption-table td[data-first-cycle-dose][data-frequency="' + frequency + '"]');
            if (firstDayCell) {
                const growthPlan = plan.phases.find(function(phase) { return phase.key === 'growth1'; });
                firstDayCell.textContent = wateringCalcFormatDoseLiters((growthPlan ? growthPlan.firstDose : 0) * doseReferenceScale);
            }
        });
        const selected = Number((document.querySelector('input[name="watering-calc-frequency"]:checked') || {}).value || 5);
        const recommendationVolume = doseReferenceVolume;
        let recommendation = null;
        if (recommendationVolume >= 20) {
            recommendation = {5:'optimal', 4:'optimal', 3:'possible', 2:'not-recommended'};
        } else if (recommendationVolume >= 7.5 && recommendationVolume <= 19.5) {
            // Objem 7,5 až 19,5 l: interval 2 dny je nedoporučený.
            recommendation = {5:'optimal', 4:'optimal', 3:'optimal', 2:'not-recommended'};
        } else if (recommendationVolume > 7 && recommendationVolume < 7.5) {
            recommendation = {5:'optimal', 4:'optimal', 3:'optimal', 2:'possible'};
        } else if (recommendationVolume >= 5) {
            // Objem 5 až 7 l: interval 2 dny je nedoporučený.
            recommendation = {5:'possible', 4:'optimal', 3:'optimal', 2:'not-recommended'};
        } else if (recommendationVolume > 0) {
            recommendation = {5:'not-recommended', 4:'possible', 3:'optimal', 2:'optimal'};
        }
        document.querySelectorAll('.watering-calc-consumption-table [data-frequency]').forEach(function(cell) {
            cell.classList.toggle('is-selected-frequency', Number(cell.dataset.frequency) === selected);
            cell.classList.remove('watering-rec-optimal', 'watering-rec-possible', 'watering-rec-not-recommended');
            const level = recommendation ? recommendation[String(cell.dataset.frequency)] : null;
            if (level) cell.classList.add('watering-rec-' + level);
        });
    }

    function getWateringPhasePlanKey(phase, stage) {
        if (phase === 'growth') return Number(stage) <= 4 ? 'growth1' : 'growth2';
        if (Number(stage) <= 7) return 'flower1';
        if (Number(stage) <= 9) return 'flower2';
        return 'flower3';
    }

    function openWateringCalculator() {
        if (!wateringCtx) {
            alert('Nejdříve otevři zalévání konkrétního dne.');
            return;
        }
        const detectedPhase = getWeekGrowthPhase(wateringCtx.week);
        setWateringCalculatorPhase(detectedPhase);
        const weekCard = document.querySelector('.growreport[data-week="' + Number(wateringCtx.week) + '"]');
        const weekPhase = String(weekCard && weekCard.dataset ? (weekCard.dataset.phase || '') : '').trim();
        const note = document.getElementById('watering-calc-phase-note');
        if (note) note.textContent = weekPhase ? ('Fáze týdne ' + wateringCtx.week + ' je nastavena na „' + weekPhase + '“. Podle ní se vybrala výchozí fáze; můžeš ji změnit.') : ('Týden ' + wateringCtx.week + ' nemá výslovně nastavenou fázi, proto je předvolen Růst. Fázi můžeš změnit.');
        renderWateringCalculatorVolumes();
        const result = document.getElementById('watering-calc-result');
        if (result) result.innerHTML = '';
        const apply = document.getElementById('watering-calc-apply');
        if (apply) apply.style.display = 'none';
        wateringCalculatorRecommendations = null;
        resetAutoWateringSchedulePreview();
        const frequency = document.querySelector('input[name="watering-calc-frequency"][value="5"]');
        if (frequency) frequency.checked = true;
        document.querySelectorAll('input[name="watering-calc-frequency"]').forEach(function(radio) {
            radio.onchange = function() {
                renderWateringFrequencyTables();
                resetAutoWateringSchedulePreview();
            };
        });
        const roundHalfLitersOption = document.getElementById('watering-calc-round-half-liters');
        const roundWholeLitersOption = document.getElementById('watering-calc-round-whole-liters');
        if (roundHalfLitersOption) roundHalfLitersOption.onchange = function() {
            if (roundHalfLitersOption.checked && roundWholeLitersOption) roundWholeLitersOption.checked = false;
            renderWateringFrequencyTables();
            resetAutoWateringSchedulePreview();
        };
        if (roundWholeLitersOption) roundWholeLitersOption.onchange = function() {
            if (roundWholeLitersOption.checked && roundHalfLitersOption) roundHalfLitersOption.checked = false;
            renderWateringFrequencyTables();
            resetAutoWateringSchedulePreview();
        };
        renderWateringFrequencyTables();
        document.getElementById('watering-calculator-dialog').style.display = 'flex';
    }

    function resetAutoWateringSchedulePreview() {
        automaticWateringSchedule = null;
        const preview = document.getElementById('watering-calc-schedule-preview');
        if (preview) preview.innerHTML = '';
        const saveButton = document.getElementById('watering-calc-save-schedule');
        if (saveButton) saveButton.style.display = 'none';
    }

    function getAutomaticWateringWeekStages(startGrowthStage) {
        // Vybraný růstový obrázek určuje počáteční stupeň prvního týdne Growreportu.
        // Např. stupeň 5 znamená: týden 1 = růst 5, týden 2 = růst 6 atd.
        // Květ začne podle fáze nastavené u konkrétního týdne; jeho číslování začne od 1.
        const initialGrowthStage = Math.max(1, Math.min(9, Number(startGrowthStage) || 1));
        const cards = Array.from(document.querySelectorAll('.growreport[data-week]'))
            .map(function(card) { return {card: card, week: Number(card.dataset.week)}; })
            .filter(function(item) { return Number.isInteger(item.week) && item.week >= 1; })
            .sort(function(a, b) { return a.week - b.week; });
        const result = Object.create(null);
        let growthStage = initialGrowthStage - 1;
        let flowerStage = 0;
        cards.forEach(function(item) {
            const rawPhase = String(item.card.dataset.phase || '').trim();
            const normalized = rawPhase.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
            let phase = '';
            if (normalized.includes('susina') || normalized.includes('dry')) {
                result[item.week] = {phase: 'dry', stage: 0, label: rawPhase || 'Sušina'};
                return;
            }
            if (normalized.includes('kvet') || normalized.includes('flower')) {
                // Květ se řídí výhradně fází konkrétního týdne v Growreportu.
                // Jeho číslování začíná od 1 a je omezené na maximálně 10. týden.
                phase = 'flower';
            } else {
                // Počáteční obrázek a prodlužování se vztahují pouze na RŮST.
                // Dosažení růstového stupně 9 samo o sobě nikdy nepřepne týden na KVĚT.
                phase = 'growth';
            }
            if (phase === 'flower') {
                flowerStage++;
                result[item.week] = {phase: 'flower', stage: Math.min(10, flowerStage), label: 'Květ'};
            } else {
                growthStage++;
                result[item.week] = {phase: 'growth', stage: Math.min(9, growthStage), label: 'Růst'};
            }
        });
        return {weeks: result, weekNumbers: cards.map(function(item) { return item.week; })};
    }

    function splitWateringBudgetToTenths(totalLiters, count) {
        if (count <= 0) return [];
        const units = Math.max(0, Math.round(Number(totalLiters || 0) * 10));
        const base = Math.floor(units / count);
        const remainder = units - base * count;
        const values = [];
        let carry = 0;
        for (let i = 0; i < count; i++) {
            carry += remainder;
            let addUnit = 0;
            if (carry >= count) { addUnit = 1; carry -= count; }
            values.push((base + addUnit) / 10);
        }
        return values;
    }

    function buildAutomaticWateringSchedule() {
        const frequencyEl = document.querySelector('input[name="watering-calc-frequency"]:checked');
        const frequency = Number(frequencyEl && frequencyEl.value || 4);
        automaticWateringScheduleFrequency = frequency;
        // Aktualizovat tabulku pro právě zvolený interval ještě před čtením dávek.
        // Automatický zápis tak vždy používá přesně čísla, která jsou v tabulce vidět.
        renderWateringFrequencyTables();
        if (![2, 3, 4, 5].includes(frequency)) throw new Error('Vyber interval zalévání 2, 3, 4 nebo 5 dní.');
        const roundingMode = 'none';
        // Důležité: předat skutečně vybraný růstový obrázek. Bez argumentu
        // funkce použije výchozí stupeň 1 a rozpis ignoruje kliknutí uživatele.
        const startingGrowthStage = wateringCalculatorPhase === 'growth'
            ? Math.max(1, Math.min(9, Number(wateringCalculatorStage) || 1))
            : 1;
        const phaseInfo = getAutomaticWateringWeekStages(startingGrowthStage);
        const weekNumbers = phaseInfo.weekNumbers;
        if (!weekNumbers.length) throw new Error('Growreport nemá vytvořené týdny od týdne 1.');
        const maxWeek = Math.max.apply(null, weekNumbers);
        const volumeItems = [];
        potsConfig.slice().sort(function(a, b) { return Number(a.pot_number) - Number(b.pot_number); }).forEach(function(pot) {
            const input = document.getElementById('watering-calc-volume-' + pot.pot_number);
            const volume = input ? Number(String(input.value).replace(',', '.')) : Number(pot.volume);
            if (!Number.isFinite(volume) || volume < 0.5 || volume > 100) throw new Error('Objem květináče „' + getPotDisplayName(pot.pot_number) + '“ musí být 0,5 až 100 litrů.');
            volumeItems.push({pot_number: Number(pot.pot_number), name: getPotDisplayName(pot.pot_number), volume: volume});
        });
        if (!volumeItems.length) throw new Error('Nejdříve nastav alespoň jeden květináč.');

        const planEvents = [];
        for (let globalDay = 1; globalDay <= maxWeek * 7; globalDay += frequency) {
            const week = Math.floor((globalDay - 1) / 7) + 1;
            const day = ((globalDay - 1) % 7) + 1;
            const stageInfo = phaseInfo.weeks[week];
            if (!stageInfo || stageInfo.phase === 'dry') continue;
            const key = getWateringPhasePlanKey(stageInfo.phase, stageInfo.stage);
            planEvents.push({globalDay: globalDay, week: week, day: day, phase: stageInfo.phase, stage: stageInfo.stage, phaseLabel: stageInfo.label, phaseKey: key, dose20: 0, amounts: []});
        }
        if (!planEvents.length) throw new Error('Pro zvolená nastavení nebyla nalezena žádná plánovaná zálivka.');

        // Automatický rozpis přebírá přímo dávky z tabulky pro zvolený interval.
        // Nesmí znovu dělit rozpočet podle počtu termínů v kalendáři, protože tím
        // vznikaly jiné hodnoty než v tabulce „Dávka na jedno zalití“.
        const frequencyPlan = getWateringFrequencyPlan(frequency, roundingMode);
        const phasePlansByKey = Object.create(null);
        frequencyPlan.phases.forEach(function(phasePlan) { phasePlansByKey[phasePlan.key] = phasePlan; });
        planEvents.forEach(function(event) {
            const phasePlan = phasePlansByKey[event.phaseKey];
            if (!phasePlan) throw new Error('Pro fázi ' + event.phaseKey + ' není definována dávka.');
            const isFirstEvent = event.globalDay === 1 && event.phaseKey === 'growth1' && startingGrowthStage === 1;
            event.amounts = volumeItems.map(function(pot) {
                const scale = pot.volume / 20;
                const tableDose = isFirstEvent ? phasePlan.firstDose : phasePlan.dose;
                const amount = Math.round(Number(tableDose || 0) * scale * 100) / 100;
                const safeAmount = Math.max(0, Math.min(pot.volume, amount));
                return {pot_number: pot.pot_number, name: pot.name, amount: safeAmount};
            });
            event.total = Math.round(event.amounts.reduce(function(sum, item) { return sum + item.amount; }, 0) * 100) / 100;
        });

        return {frequency: frequency, roundingMode: roundingMode, maxWeek: maxWeek, pots: volumeItems, rows: planEvents};
    }

    function previewAutoWateringSchedule() {
        const preview = document.getElementById('watering-calc-schedule-preview');
        const saveButton = document.getElementById('watering-calc-save-schedule');
        if (!preview) return;
        resetAutoWateringSchedulePreview();
        try {
            automaticWateringSchedule = buildAutomaticWateringSchedule();
        } catch (error) {
            preview.innerHTML = '';
            const message = document.createElement('p');
            message.className = 'watering-calc-warning';
            message.textContent = error && error.message ? error.message : 'Plán se nepodařilo vypočítat.';
            preview.appendChild(message);
            return;
        }
        const schedule = automaticWateringSchedule;
        const summary = document.createElement('p');
        summary.className = 'watering-calc-help';
        const startingGrowthStage = wateringCalculatorPhase === 'growth'
            ? Math.max(1, Math.min(9, Number(wateringCalculatorStage) || 1))
            : 1;
        summary.textContent = 'Výchozí růstový stupeň: ' + startingGrowthStage + ' (Growreport týden 1 = růst ' + startingGrowthStage + '; květ 1 navazuje podle nastavené fáze týdne). Interval každých ' + schedule.frequency + ' dní · termínů zalévání: ' + schedule.rows.length + ' · květináčů: ' + schedule.pots.length + ' · celkový objem substrátu: ' + schedule.pots.reduce(function(sum, pot) { return sum + pot.volume; }, 0).toLocaleString('cs-CZ', {maximumFractionDigits:1}) + ' l. Všechny dávky včetně první zálivky se přepočítávají podle objemu každého květináče z bodu 3 (základ tabulky je 20 l substrátu).';
        preview.appendChild(summary);

        const table = document.createElement('table');
        const thead = document.createElement('thead');
        const headerRow = document.createElement('tr');
        ['Termín', 'Fáze / stupeň', 'Dávka pro květináče', 'Celkem v den'].forEach(function(label) {
            const th = document.createElement('th'); th.textContent = label; headerRow.appendChild(th);
        });
        thead.appendChild(headerRow); table.appendChild(thead);
        const tbody = document.createElement('tbody');
        schedule.rows.forEach(function(event) {
            const row = document.createElement('tr');
            const when = document.createElement('td'); when.textContent = 'Týden ' + event.week + ', den ' + event.day; row.appendChild(when);
            const stage = document.createElement('td'); stage.textContent = event.globalDay === 1 ? (event.phaseLabel + ' ' + event.stage + ' · první zálivka') : (event.phaseLabel + ' ' + event.stage); row.appendChild(stage);
            const amounts = document.createElement('td');
            amounts.textContent = event.amounts.filter(function(item) { return item.amount > 0; }).map(function(item) {
                return item.name + ' ' + item.amount.toLocaleString('cs-CZ', {minimumFractionDigits: 0, maximumFractionDigits: 2}) + ' L';
            }).join(', ');
            row.appendChild(amounts);
            const total = document.createElement('td'); total.textContent = event.total.toLocaleString('cs-CZ', {minimumFractionDigits: 0, maximumFractionDigits: 2}) + ' L'; row.appendChild(total);
            tbody.appendChild(row);
        });
        table.appendChild(tbody); preview.appendChild(table);
        const warning = document.createElement('p');
        warning.className = 'watering-calc-help';
        warning.textContent = 'Po uložení se tyto množství zapíší do běžné tabulky Zalévání a po obnovení stránky se automaticky zobrazí v příslušných dnech. Případné ruční hodnoty na dnech nového rozpisu budou nahrazeny; ruční hodnoty v ostatních dnech zůstanou.';
        preview.appendChild(warning);
        if (saveButton) saveButton.style.display = 'inline-block';
    }

    function saveAutoWateringSchedule() {
        if (!automaticWateringSchedule || !Array.isArray(automaticWateringSchedule.rows) || !automaticWateringSchedule.rows.length) {
            alert('Nejdříve vytvoř náhled automatického rozpisu.');
            return;
        }
        const payload = automaticWateringSchedule.rows.map(function(event) {
            return {week: event.week, day: event.day, amounts: event.amounts.map(function(item) { return {pot_number: item.pot_number, amount: item.amount}; })};
        });
        if (!confirm('Zapíše se automatické zalévání pro ' + payload.length + ' dnů v celém Growreportu, interval každých ' + automaticWateringSchedule.frequency + ' dní. Předchozí automatické zálivky se obnoví a ručně zadané hodnoty na konkrétních dnech nového rozpisu budou nahrazeny. Pokračovat?')) return;
        const button = document.getElementById('watering-calc-save-schedule');
        if (button) { button.disabled = true; button.textContent = 'Ukládám plán…'; }
        postJson('save_watering', {schedule: payload}).then(function(response) {
            if (!response || !response.success) throw new Error((response && response.message) || 'Automatický plán se nepodařilo uložit.');
            alert('Automatický plán zalévání byl uložen. Naplánované dny: ' + response.generated_days + ', záznamy květináčů: ' + response.generated_values + '. Po obnovení stránky se zobrazí přímo v jednotlivých dnech.');
            location.reload();
        }).catch(function(error) {
            alert('Chyba při ukládání automatického zalévání: ' + (error && error.message ? error.message : 'neznámá chyba'));
        }).finally(function() {
            if (button) { button.disabled = false; button.textContent = 'Zapsat plán do tabulky Zalévání'; }
        });
    }

    function closeWateringCalculator() {
        const dialog = document.getElementById('watering-calculator-dialog');
        if (dialog) dialog.style.display = 'none';
    }

    function calculateWateringRecommendation() {
        const result = document.getElementById('watering-calc-result');
        const applyButton = document.getElementById('watering-calc-apply');
        if (applyButton) applyButton.style.display = 'none';
        wateringCalculatorRecommendations = null;
        if (!wateringCtx) {
            if (result) result.textContent = 'Nejdříve otevři zalévání konkrétního dne.';
            return;
        }
        if (!wateringCalculatorStage) {
            if (result) result.textContent = 'Nejdříve kliknutím vyber stupeň rostliny na obrázku.';
            return;
        }
        const frequencyEl = document.querySelector('input[name="watering-calc-frequency"]:checked');
        const frequency = Number(frequencyEl && frequencyEl.value || 4);
        document.querySelectorAll('.watering-calc-consumption-table [data-frequency]').forEach(function(cell) {
            cell.classList.toggle('is-selected-frequency', Number(cell.dataset.frequency) === frequency);
        });

        // Volitelné zaokrouhlení na 0,5 L nebo celé litry platí pro intervaly 4–2 dny.
        // Dávky menší než 1 L zůstávají nezaokrouhlené na půl nebo celý litr.
        const roundingMode = getWateringRoundingMode();
        const wateringPlan = getWateringFrequencyPlan(frequency, roundingMode);
        const useRounding = roundingMode !== 'none' && frequency < 5;
        const phaseKey = getWateringPhasePlanKey(wateringCalculatorPhase, wateringCalculatorStage);
        const selectedPhasePlan = wateringPlan.phases.find(function(phase) { return phase.key === phaseKey; });
        const phaseBudget = selectedPhasePlan ? selectedPhasePlan.total : 0;
        const isFirstCycleDay = Number(wateringCtx.week) === 1 && Number(wateringCtx.day) === 1;
        const dosePer20Liter = selectedPhasePlan ? (isFirstCycleDay ? selectedPhasePlan.firstDose : selectedPhasePlan.dose) : 0;
        if (!isFirstCycleDay && (!selectedPhasePlan || selectedPhasePlan.regularEventCount <= 0)) {
            if (result) result.textContent = 'Pro zvolený interval se nepodařilo určit počet zálivek. Zvol jiný interval.';
            return;
        }

        const list = [];
        const sortedPots = potsConfig.slice().sort(function(a, b) { return Number(a.pot_number) - Number(b.pot_number); });
        for (const pot of sortedPots) {
            const input = document.getElementById('watering-calc-volume-' + pot.pot_number);
            const volume = input ? Number(String(input.value).replace(',', '.')) : Number(pot.volume);
            if (!Number.isFinite(volume) || volume < 0.5 || volume > 100) {
                if (result) result.textContent = 'Objem každého květináče musí být v rozsahu 0,5 až 100 litrů.';
                return;
            }
            // Dávka se určuje podle konkrétní fáze a stupně a velikosti květináče.
            const exactLiters = dosePer20Liter * (volume / 20);
            // Zaokrouhlení pro intervaly 4–2 dny; dávky pod 1 L ponecháváme na desetiny.
            // Nová dávkovací tabulka má přednost před volitelným zaokrouhlením starých rozpočtů.
            const roundedPickerLiters = wateringCalcFloorTenth(exactLiters);
            const pickerLiters = Math.max(0, Math.min(volume, roundedPickerLiters));
            const projected19WeekLiters = wateringPlan.totalLiters * (volume / 20);
            list.push({
                pot_number: Number(pot.pot_number),
                name: getPotDisplayName(pot.pot_number),
                volume: volume,
                exact_liters: pickerLiters,
                liters: pickerLiters,
                pot_capacity: volume,
                projected_19week_liters: projected19WeekLiters
            });
        }
        wateringCalculatorRecommendations = {
            phase: wateringCalculatorPhase,
            stage: wateringCalculatorStage,
            frequency: frequency,
            dose_per_20liter: dosePer20Liter,
            phase_budget: phaseBudget,
            frequency_plan_total: wateringPlan.totalLiters,
            round_whole_liters: useRounding && roundingMode === 'whole',
            round_half_liters: useRounding && roundingMode === 'half',
            rounding_mode: useRounding ? roundingMode : 'none',
            first_cycle_day: isFirstCycleDay,
            items: list
        };
        if (!result) return;
        result.innerHTML = '';
        const totalProjectedLiters = list.reduce(function(sum, item) { return sum + item.projected_19week_liters; }, 0);
        const summary = document.createElement('div');
        summary.style.cssText = 'border:1px solid #a5d6a7;background:#e8f5e9;color:#1b5e20;border-radius:7px;padding:10px 12px;font-weight:700;';
        const selectedDoseRates = getWateringDoseRatesForStage(wateringCalculatorPhase, wateringCalculatorStage);
        const selectedDoseText = selectedDoseRates
            ? (selectedDoseRates.perTenLiters.toLocaleString('cs-CZ') + ' L na 10 l substrátu od 7 l, nebo ' + selectedDoseRates.perOneLiter.toLocaleString('cs-CZ') + ' L na 1 l substrátu do 6,9 l')
            : 'dávka podle výchozí tabulky';
        summary.textContent = 'Dávka: ' + selectedDoseText + '. Fáze ' + (wateringCalculatorPhase === 'growth' ? 'Růst' : 'Květ') + ', stupeň ' + wateringCalculatorStage + ', interval každých ' + frequency + ' dní.';
        result.appendChild(summary);
        const table = document.createElement('table');
        const thead = document.createElement('thead');
        const trh = document.createElement('tr');
        ['Květináč','Objem substrátu','Dávka na jedno zalití','Odhad za 133 dní'].forEach(function(title) {
            const th = document.createElement('th'); th.textContent = title; trh.appendChild(th);
        });
        thead.appendChild(trh); table.appendChild(thead);
        const tbody = document.createElement('tbody');
        let totalLiters = 0;
        list.forEach(function(item) {
            const tr = document.createElement('tr');
            const values = [
                item.name,
                item.volume.toLocaleString('cs-CZ') + ' L',
                item.liters.toLocaleString('cs-CZ', {minimumFractionDigits:1, maximumFractionDigits:1}) + ' L',
                item.projected_19week_liters.toLocaleString('cs-CZ', {minimumFractionDigits:1, maximumFractionDigits:2}) + ' L'
            ];
            values.forEach(function(value) { const td = document.createElement('td'); td.textContent = value; tr.appendChild(td); });
            tbody.appendChild(tr);
            totalLiters += item.liters;
        });
        const totalRow = document.createElement('tr'); totalRow.className = 'calc-result-total';
        [ 'Celkem', 'Všechny květináče', totalLiters.toLocaleString('cs-CZ', {minimumFractionDigits:1, maximumFractionDigits:1}) + ' L', totalProjectedLiters.toLocaleString('cs-CZ', {minimumFractionDigits:1, maximumFractionDigits:2}) + ' L' ].forEach(function(value) {
            const td = document.createElement('td'); td.textContent = value; totalRow.appendChild(td);
        });
        tbody.appendChild(totalRow); table.appendChild(tbody); result.appendChild(table);
        const hint = document.createElement('p');
        hint.className = 'watering-calc-help';
        hint.textContent = 'Dávky jsou vypočítané podle zvoleného stupně a objemu substrátu podle nové tabulky. Historie Growreportu zůstává dostupná přes přepínač zdroje historie; výše uvedené dávkovací pravidlo se použije pro automatický výpočet.';
        result.appendChild(hint);
        if (applyButton) applyButton.style.display = 'inline-block';
    }

    function applyWateringRecommendation() {
        if (!wateringCalculatorRecommendations || !wateringCtx) return;
        wateringCalculatorRecommendations.items.forEach(function(item) {
            const picker = document.getElementById('sp-pot-' + item.pot_number);
            if (picker) initScrollPicker(picker, item.pot_capacity, 0.1, item.liters);
        });
        updateWateringTotal();
        closeWateringCalculator();
    }

    function initScrollPicker(container, maxVal, step, initialVal) {
        const itemH = 40;
        container.innerHTML = '';

        const pad1 = document.createElement('div');
        pad1.className = 'sp-pad';
        container.appendChild(pad1);

        const vals = [];
        for (let v = 0; v <= maxVal + 0.001; v = Math.round((v + step) * 100) / 100) {
            vals.push(Math.round(v * 100) / 100);
        }
        // Include the requested initial value when it does not fall exactly on the picker step.
        // This is used by the watering calculator; normal manual picking remains unchanged.
        if (Number.isFinite(Number(initialVal)) && !vals.some(function(v) { return Math.abs(v - Number(initialVal)) < 0.005; })) {
            // Zobrazit i starší uloženou dávku, která neodpovídá kroku 0,5 L
            // nebo je vyšší než aktuálně nastavený objem květináče. Historii nemažeme.
            vals.push(Math.max(0, Math.round(Number(initialVal) * 100) / 100));
            vals.sort(function(a, b) { return a - b; });
        }

        vals.forEach(function(v) {
            const el = document.createElement('div');
            el.className = 'sp-item';
            el.dataset.val = v;
            el.textContent = (v % 1 === 0) ? v.toString() : v.toFixed(1);
            container.appendChild(el);
        });

        const pad2 = document.createElement('div');
        pad2.className = 'sp-pad';
        container.appendChild(pad2);

        // Scroll to initial value
        let idx = 0;
        for (let j = 0; j < vals.length; j++) {
            if (Math.abs(vals[j] - initialVal) < 0.01) { idx = j; break; }
        }
        const initialScrollTop = idx * itemH;
        container.scrollTop = initialScrollTop;
        // Po zobrazení dialogu znovu nastavit pozici, protože některé prohlížeče
        // při vytvoření scroll kontejneru uvnitř skrytého okna původní offset zahodí.
        requestAnimationFrame(function() {
            container.scrollTop = initialScrollTop;
            setTimeout(function() {
                container.scrollTop = initialScrollTop;
                highlight();
            }, 40);
        });

        function highlight() {
            const ci = Math.round(container.scrollTop / itemH);
            const items = container.querySelectorAll('.sp-item');
            items.forEach(function(el, i) {
                el.classList.toggle('sp-sel', i === ci);
            });
            updateWateringTotal();
        }

        container.addEventListener('scroll', highlight);
        setTimeout(highlight, 50);

        container.getVal = function() {
            const ci = Math.round(this.scrollTop / itemH);
            const items = this.querySelectorAll('.sp-item');
            return (items[ci]) ? parseFloat(items[ci].dataset.val) : 0;
        };
    }

// Teplota
document.getElementById('temp-cancel').addEventListener('click', closeTemperature);
document.getElementById('temp-ok').addEventListener('click', saveTemperature);

// validace vstupu teploty: číslo, minus, tečka/čárka
['temp-value1', 'temp-value2'].forEach(id => {
    document.getElementById(id).addEventListener('input', function() {
        this.value = this.value
            .replace(',', '.')
            .replace(/[^0-9.-]/g, '')
            .replace(/(?!^)-/g, '')                // minus jen na začátku
            .replace(/^(-?\d{0,2})(\.\d?).*$/, '$1$2'); // max 2 čísla + 1 desetinné
    });
});

    function initRunoffLitersPicker(container, initialVal) {
        const values = [0];
        for (let i = 1; i <= 10; i++) values.push(i / 10);
        for (let v = 1.5; v <= 10.001; v += 0.5) values.push(Number(v.toFixed(1)));
        if (initialVal > 0 && !values.some(v => Math.abs(v - initialVal) < 0.001)) values.push(Number(initialVal));
        values.sort((a,b)=>a-b);
        container.innerHTML = '<div class="sp-pad"></div>';
        values.forEach(function(v) {
            const item = document.createElement('div'); item.className='sp-item'; item.dataset.val=String(v);
            item.textContent=v === 0 ? '—' : v.toLocaleString('cs-CZ',{minimumFractionDigits:1,maximumFractionDigits:1})+' L'; container.appendChild(item);
        });
        container.insertAdjacentHTML('beforeend','<div class="sp-pad"></div>');
        const idx = values.findIndex(v=>Math.abs(v-initialVal)<0.001);
        container.scrollTop = Math.max(0,idx)*40;
        function highlight(){
            const i=Math.max(0,Math.min(values.length-1,Math.round(container.scrollTop/40)));
            container.querySelectorAll('.sp-item').forEach((el,j)=>el.classList.toggle('sp-sel',j===i));
            const note=document.getElementById('watering-runoff-liters-saved');
            if(note) note.textContent = values[i] > 0 ? values[i].toLocaleString('cs-CZ',{minimumFractionDigits:1,maximumFractionDigits:1})+' L výtoku' : 'Množství výtoku není zaznamenáno.';
        }
        container.onscroll=highlight; container.getVal=function(){const i=Math.max(0,Math.min(values.length-1,Math.round(this.scrollTop/40)));return values[i]||0;};
        requestAnimationFrame(function(){container.scrollTop=Math.max(0,idx)*40;highlight();});
    }

    function updateWateringTotal() {
        let total = 0;
        potsConfig.forEach(function(pot) {
            const picker = document.getElementById('sp-pot-' + pot.pot_number);
            if (picker && picker.getVal) {
                total += picker.getVal();
            }
        });
        total = Math.round(total * 10) / 10;
        const el = document.getElementById('watering-total');
        if (el) {
            const str = (total % 1 === 0) ? total.toString() : total.toFixed(1);
            el.textContent = 'Celkem: ' + str + ' L';
        }
    }

    function saveWatering() {
        if (!wateringCtx) return;
        const ctx = wateringCtx;

        const amounts = [];
        potsConfig.forEach(function(pot) {
            const picker = document.getElementById('sp-pot-' + pot.pot_number);
            const amt = picker ? picker.getVal() : 0;
            amounts.push({ pot_number: pot.pot_number, amount: amt });
        });

        const phSelect = document.getElementById('watering-ph-value');
        const runoffPhSelect = document.getElementById('watering-runoff-ph-value');
        const ph = phSelect ? phSelect.value : '';
        const runoffPh = runoffPhSelect ? runoffPhSelect.value : '';
        const runoffLitersPicker = document.getElementById('watering-runoff-liters-picker');
        const runoffLiters = runoffLitersPicker && runoffLitersPicker.getVal ? runoffLitersPicker.getVal() : 0;
        postJson('save_watering', { week: ctx.week, day: ctx.day, amounts: amounts, ph: ph, runoff_ph: runoffPh, runoff_liters: runoffLiters > 0 ? runoffLiters : '' })
        .then(function(data) {
            if (data.success) {
                const total = Math.round((data.total || 0) * 10) / 10;

      const wk = String(ctx.week);
const dy = String(ctx.day);

if (!wateringPHData[wk] || typeof wateringPHData[wk] !== 'object') wateringPHData[wk] = {};
if (data.ph !== null && data.ph !== undefined && data.ph !== '') wateringPHData[wk][dy] = Number(data.ph);
else delete wateringPHData[wk][dy];
if (!wateringRunoffPHData[wk] || typeof wateringRunoffPHData[wk] !== 'object') wateringRunoffPHData[wk] = {};
if (data.runoff_ph !== null && data.runoff_ph !== undefined && data.runoff_ph !== '') wateringRunoffPHData[wk][dy] = Number(data.runoff_ph);
else delete wateringRunoffPHData[wk][dy];
if (!wateringRunoffLitersData[wk] || typeof wateringRunoffLitersData[wk] !== 'object') wateringRunoffLitersData[wk] = {};
if (data.runoff_liters !== null && data.runoff_liters !== undefined && data.runoff_liters !== '') wateringRunoffLitersData[wk][dy] = Number(data.runoff_liters);
else delete wateringRunoffLitersData[wk][dy];

if (!wateringData[wk] || typeof wateringData[wk] !== 'object') {
    wateringData[wk] = {};
}

const previousDayObj = (wateringData[wk] && wateringData[wk][dy]) ? wateringData[wk][dy] : {};
const dayObj = {};
amounts.forEach(function(a) {
    const amt = Number(a.amount || 0);
    const potKey = String(a.pot_number);
    const previousAmount = Number(previousDayObj[potKey] || 0);
    const delta = amt - previousAmount;
    wateringLifetimeTotals[potKey] = Math.max(0, Math.round((Number(wateringLifetimeTotals[potKey] || 0) + delta) * 100) / 100);
    if (amt > 0) {
        dayObj[potKey] = amt;
    }
});

wateringData[wk][dy] = dayObj;
renderSavedWateringRows(ctx.week, ctx.day);
updateDailyTaskNote(ctx.week, ctx.day);
// Po vynulování pH i litrů výtoku ihned přepočítat kalendářní políčko.
if (typeof updateDays === 'function') updateDays(Number(ctx.week));

                // Update button display
                const btn = document.getElementById('zal-' + ctx.week + '-' + ctx.day);
                if (btn) {
                    btn.dataset.total = total;
                    renderZalitoBtn(btn, total);
                }

                // Update day-zalito stripe
                const stripe = document.getElementById('day-zalito-' + ctx.week + '-' + ctx.day);
                if (stripe) {
                    stripe.style.backgroundColor = total > 0 ? 'blue' : '';
                }

                closeWatering();
            } else {
                alert(data.message || 'Chyba při ukládání.');
            }
        })
        .catch(function(err) {
            console.error('Chyba:', err);
            alert('Nastala chyba.');
        });
    }

    function renderZalitoBtn(btn, total) {
        btn.innerHTML = '';
        if (total > 0) {
            const str = (total % 1 === 0) ? total.toString() : total.toFixed(1);
            const s = document.createElement('span');
            s.className = 'zal-total' + (str.length > 2 ? ' small' : '');
            s.textContent = str;
            btn.appendChild(s);
        } else {
            const s = document.createElement('span');
            s.className = 'zal-plus';
            s.textContent = '+';
            btn.appendChild(s);
        }
    }

    // ========== CO2 ==========
    function getCo2Status(value1, value2) {
        var values = [value1, value2]
            .filter(function(v) { return v !== null && v !== undefined && String(v).trim() !== ''; })
            .map(function(v) { return Number(v); })
            .filter(function(v) { return Number.isFinite(v); });
        if (!values.length) return { stripe: false, color: '#c62828' };
        if (values.some(function(v) { return v > 1500; })) return { stripe: true, color: '#1565c0' };
        if (values.some(function(v) { return v >= 600 && v <= 1200; })) return { stripe: true, color: '#757575' };
        if (Math.max.apply(null, values) < 600) return { stripe: false, color: '#c62828' };
        // Keep the existing red text + gray stripe for the unspecified 1201–1500 range.
        return { stripe: true, color: '#c62828' };
    }

    function showCo2Tip(btn) {
        if (!btn) return;
        const items = [
            { l: btn.dataset.l1, v: btn.dataset.v1 },
            { l: btn.dataset.l2, v: btn.dataset.v2 }
        ].filter(function(x) { return x.v !== '' && x.v !== undefined; });
        if (items.length === 0) { hideCo2Tip(); return; }

        const tip = document.getElementById('co2-tip');
        const co2Status = getCo2Status(btn.dataset.v1, btn.dataset.v2);
        tip.innerHTML = '';
        items.forEach(function(x) {
            const d = document.createElement('div');
            d.className = 'tip-line';
            d.style.color = co2Status.color;
            d.textContent = x.l + ': ' + x.v;
            tip.appendChild(d);
        });

        const r = btn.getBoundingClientRect();
        tip.style.display = 'flex';
        tip.style.minWidth = (r.width * 3) + 'px';
        tip.style.minHeight = (r.height * 3) + 'px';

        const tw = tip.offsetWidth;
        const th = tip.offsetHeight;
        let left = r.left + r.width / 2 - tw / 2;
        let top = r.top + r.height / 2 - th / 2;
        left = Math.max(5, Math.min(left, window.innerWidth - tw - 5));
        top = Math.max(5, Math.min(top, window.innerHeight - th - 5));
        tip.style.left = left + 'px';
        tip.style.top = top + 'px';
    }

    function hideCo2Tip() {
        document.getElementById('co2-tip').style.display = 'none';
    }

    function openCo2(btn, week, day) {
        hideCo2Tip();
        co2Ctx = { btn: btn, week: week, day: day };
        document.getElementById('co2-dialog-title').innerText = 'CO2 (PPM) – den ' + (week * 7 - (7 - day));
        document.getElementById('co2-label1').value = btn.dataset.l1 || 'Ráno';
        document.getElementById('co2-value1').value = btn.dataset.v1 || '';
        document.getElementById('co2-label2').value = btn.dataset.l2 || 'Večer';
        document.getElementById('co2-value2').value = btn.dataset.v2 || '';
        document.getElementById('co2-dialog').style.display = 'flex';
        document.getElementById('co2-value1').focus();
    }

    function closeCo2() {
        document.getElementById('co2-dialog').style.display = 'none';
        co2Ctx = null;
    }

    function renderCo2Btn(btn) {
        btn.innerHTML = '';
        var lines = [
            { l: btn.dataset.l1, v: btn.dataset.v1 },
            { l: btn.dataset.l2, v: btn.dataset.v2 }
        ];
        var any = false;
        var co2Status = getCo2Status(btn.dataset.v1, btn.dataset.v2);
        btn.dataset.color = co2Status.color;
        btn.dataset.stripe = co2Status.stripe ? '1' : '0';
        lines.forEach(function(item) {
            if (item.v !== '' && item.v !== undefined && item.v !== null) {
                any = true;
                var s = document.createElement('span');
                s.className = 'co2-line';
                s.style.color = co2Status.color;
                s.textContent = (item.l || '').charAt(0).toUpperCase() + item.v;
                btn.appendChild(s);
            }
        });
        if (!any) {
            var s = document.createElement('span');
            s.className = 'co2-plus';
            s.textContent = '+';
            btn.appendChild(s);
        }

        var stripe = document.getElementById(btn.id.replace('co2-', 'day-co2-'));
        if (stripe) stripe.classList.toggle('on', co2Status.stripe);
    }

    function saveCo2() {
        if (!co2Ctx) return;
        var ctx = co2Ctx;
        var payload = {
            week: ctx.week,
            day: ctx.day,
            label1: document.getElementById('co2-label1').value.trim(),
            value1: document.getElementById('co2-value1').value.trim(),
            label2: document.getElementById('co2-label2').value.trim(),
            value2: document.getElementById('co2-value2').value.trim()
        };

        postJson('save_co2', payload)
        .then(function(data) {
            if (data.success) {
                ctx.btn.dataset.l1 = data.label1;
                ctx.btn.dataset.l2 = data.label2;
                ctx.btn.dataset.v1 = data.value1 === null ? '' : String(data.value1);
                ctx.btn.dataset.v2 = data.value2 === null ? '' : String(data.value2);
                renderCo2Btn(ctx.btn);
                closeCo2();
            } else {
                alert(data.message || 'Chyba při ukládání CO2.');
            }
        })
        .catch(function(error) {
            console.error('Chyba při ukládání CO2:', error);
            alert('Nastala chyba při ukládání CO2.');
        });
    }
<?php
function tempColor($v) {
    $n = (float)$v;
    return ($n >= 18 && $n <= 38) ? '#2e7d32' : '#c62828';
}
?>
    // ========== DELETE ==========
    function showDeleteConfirmation() { document.getElementById('delete-confirmation').style.display = 'flex'; }
    function hideDeleteConfirmation() { document.getElementById('delete-confirmation').style.display = 'none'; }
    function confirmDeleteGrowreport() { hideDeleteConfirmation(); deleteGrowreport(); }

    function deleteGrowreport() {
        postJson('delete', { report_id: reportId })
        .then(function(data) {
            if (data.success) { alert("Growreport byl úspěšně smazán!"); window.location.href = "index.php"; }
            else { alert(data.message || "Chyba při mazání growreportu."); }
        })
        .catch(function(error) { console.error("Chyba:", error); alert("Nastala chyba: " + error); });
    }

    function showDeleteWeekConfirmation(week) {
        weekToDelete = week;
        document.getElementById('delete-week-label').innerText = 'Týden ' + week;
        document.getElementById('delete-week-confirmation').style.display = 'flex';
    }
    function hideDeleteWeekConfirmation() { document.getElementById('delete-week-confirmation').style.display = 'none'; weekToDelete = null; }
    function confirmDeleteWeek() {
        var week = weekToDelete;
        hideDeleteWeekConfirmation();
        if (week === null || week === undefined) return;
        postJson('delete_week', { week: week })
        .then(function(data) { if (data.success) { location.reload(); } else { alert(data.message || "Chyba."); } })
        .catch(function(error) { console.error("Chyba:", error); alert("Nastala chyba."); });
    }

    // ========== SHARE ==========
    function shareGrowreport() {
        var hasImages = false;
        document.querySelectorAll(".growreport img:not(.preview-image)").forEach(function(img) {
            var src = img.getAttribute('src');
            if (src && src !== '#') hasImages = true;
        });
        if (!hasImages) { alert("Nejprve nahrajte alespoň jednu fotku."); return; }

        postJson('share', { report_id: reportId })
        .then(function(data) { if (data.success) { alert("Growreport sdílen!"); } else { alert(data.message || "Chyba."); } })
        .catch(function(error) { console.error("Chyba:", error); alert("Nastala chyba."); });
    }

    // ========== ADD WEEK ==========
    function addWeek() {
        postJson('add_week', {})
        .then(function(data) { if (data.success) { location.reload(); } else { alert(data.message || "Nepodařilo se."); } })
        .catch(function(error) { console.error("Chyba:", error); alert("Nastala chyba."); });
    }

    // Vytvoří přípravný týden 0 před týdnem 1; zobrazené tlačítko existuje pouze do vytvoření.
    function addWeekZero() {
        var button = document.getElementById('add-week-zero');
        if (button) button.disabled = true;
        postJson('add_week_zero', {})
        .then(function(data) {
            if (data && data.success) {
                location.reload();
            } else {
                if (button) button.disabled = false;
                alert((data && data.message) || 'Týden 0 se nepodařilo vytvořit.');
            }
        })
        .catch(function(error) {
            if (button) button.disabled = false;
            console.error('Vytvoření týdne 0 selhalo:', error);
            alert('Týden 0 se nepodařilo vytvořit: ' + (error && error.message ? error.message : error));
        });
    }

    // Počítání týdnů květu podle uložené fáze v každém týdnu (K1, K2, K3...).
    // Čísla se přepočítají i tehdy, když se u některého týdne fáze změní.
    function updateFlowerWeekNumbers() {
        var weekCards = Array.from(document.querySelectorAll('#growreport-container .growreport[data-week]'));
        weekCards.sort(function(a, b) {
            return (parseInt(a.dataset.week || '0', 10) || 0) - (parseInt(b.dataset.week || '0', 10) || 0);
        });
        var flowerIndex = 0;
        weekCards.forEach(function(card) {
            var phase = String(card.dataset.phase || '').trim().toLocaleUpperCase();
            var isFlower = phase === 'KVĚT' || phase === 'KVET';
            var marker = card.querySelector('.flower-week-marker');
            if (!marker) return;
            if (isFlower) {
                flowerIndex++;
                marker.textContent = 'K' + flowerIndex + ' 🌼';
                marker.style.display = 'inline-flex';
            } else {
                marker.textContent = '';
                marker.style.display = 'none';
            }
        });
    }

    // ========== GALERIE TÝDNE ==========
    // Použijeme var, aby otevření galerie nebylo blokováno temporal dead zone,
    // pokud jiná chyba přeruší inicializaci skriptu před touto částí.
    var galleryCurrentWeek = null;
    // Navigační seznam obsahuje pouze fotografie z aktuální galerie (videa zůstávají přehratelná jako dosud).
    var galleryPhotoList = [];
    function openGallery(week) {
        galleryCurrentWeek = Number(week);
        const dialog = document.getElementById('gallery-dialog');
        const title = document.getElementById('gallery-dialog-title');
        const input = document.getElementById('gallery-file-input');
        const status = document.getElementById('gallery-status');
        const container = document.getElementById('gallery-items');
        console.info('[DEBUG GALERIE] Otevírám galerii', { week: galleryCurrentWeek, hasDialog: !!dialog, hasTitle: !!title, hasStatus: !!status, hasItems: !!container, api: API });
        if (!dialog || !title || !status || !container) {
            const missing = ['gallery-dialog', 'gallery-dialog-title', 'gallery-status', 'gallery-items'].filter(function(id) { return !document.getElementById(id); });
            const message = 'DEBUG GALERIE: nelze otevřít galerii; chybí prvky: ' + (missing.join(', ') || 'neznámé');
            console.error(message);
            alert(message);
            return;
        }
        title.textContent = 'Galerie – týden ' + galleryCurrentWeek;
        status.textContent = 'DEBUG GALERIE: dialog se otevřel. Načítám záznamy pro týden ' + galleryCurrentWeek + '…';
        status.style.color = '#333';
        if (input) input.value = '';
        dialog.style.display = 'flex';
        loadGalleryItems();
    }
    // Zpřístupnit obsluhu i pro tlačítka, která volají funkci přes onclick.
    window.openGallery = openGallery;

    function debugOpenGallery(week, button) {
        try {
            console.info('[DEBUG GALERIE] Kliknutí na tlačítko', { week: Number(week), buttonFound: !!button, openGalleryType: typeof window.openGallery });
            if (typeof window.openGallery !== 'function') {
                const message = 'DEBUG GALERIE: funkce openGallery není dostupná. Zkontroluj chybu JavaScriptu v konzoli.';
                console.error(message);
                alert(message);
                return;
            }
            window.openGallery(week);
        } catch (error) {
            console.error('[DEBUG GALERIE] Nepodařilo se otevřít dialog:', error);
            alert('DEBUG GALERIE – otevření selhalo: ' + (error && error.message ? error.message : error));
        }
    }
    window.debugOpenGallery = debugOpenGallery;

    function closeGallery() {
        const dialog = document.getElementById('gallery-dialog');
        if (dialog) dialog.style.display = 'none';
        galleryCurrentWeek = null;
    }

    function setGalleryStatus(message, isError) {
        const status = document.getElementById('gallery-status');
        if (!status) return;
        status.textContent = message || '';
        status.style.color = isError ? '#b71c1c' : '#333';
    }

    function renderGalleryItems(items) {
        const container = document.getElementById('gallery-items');
        if (!container) return;
        const safeItems = Array.isArray(items) ? items : [];
        galleryPhotoList = safeItems.filter(function(item) {
            return item && String(item.media_type || '').toLowerCase() !== 'video' && String(item.file_path || '').trim() !== '';
        }).map(function(item) {
            return {
                id: item.id,
                file_path: String(item.file_path || ''),
                original_name: String(item.original_name || 'Fotografie v galerii')
            };
        });
        container.innerHTML = '';
        if (!safeItems.length) {
            const empty = document.createElement('div');
            empty.className = 'gallery-empty';
            empty.textContent = 'V této galerii zatím nejsou žádné fotografie ani videa.';
            container.appendChild(empty);
            return;
        }
        safeItems.forEach(function(item) {
            const card = document.createElement('div');
            card.className = 'gallery-item';
            const path = String(item.file_path || '');
            if (String(item.media_type || '').toLowerCase() === 'video') {
                const video = document.createElement('video');
                video.src = path;
                video.controls = true;
                video.preload = 'metadata';
                card.appendChild(video);
            } else {
                const mediaWrap = document.createElement('div');
                mediaWrap.className = 'gallery-photo-wrap';
                const img = document.createElement('img');
                img.src = path;
                img.alt = String(item.original_name || 'Fotografie v galerii');
                img.loading = 'lazy';
                mediaWrap.appendChild(img);

                const zoomButton = document.createElement('button');
                zoomButton.type = 'button';
                zoomButton.className = 'gallery-photo-zoom-btn';
                zoomButton.textContent = '⛶';
                zoomButton.title = 'Zvětšit fotografii a procházet galerii';
                zoomButton.setAttribute('aria-label', 'Zvětšit fotografii ' + img.alt);
                zoomButton.addEventListener('click', function(event) {
                    event.preventDefault();
                    event.stopPropagation();
                    const photoIndex = galleryPhotoList.findIndex(function(photo) {
                        return String(photo.id) === String(item.id);
                    });
                    if (photoIndex < 0) {
                        setGalleryStatus('DEBUG GALERIE: fotografii se nepodařilo najít v seznamu pro procházení.', true);
                        return;
                    }
                    if (typeof window.openGalleryPhotoZoom !== 'function') {
                        setGalleryStatus('DEBUG GALERIE: funkce pro zvětšení fotek není dostupná. Zkontroluj chybu JavaScriptu.', true);
                        return;
                    }
                    window.openGalleryPhotoZoom(photoIndex);
                });
                mediaWrap.appendChild(zoomButton);
                card.appendChild(mediaWrap);
            }
            const name = document.createElement('div');
            name.className = 'gallery-item-name';
            name.textContent = String(item.original_name || (String(item.media_type || '').toLowerCase() === 'video' ? 'Video' : 'Fotografie'));
            card.appendChild(name);
            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'gallery-item-delete';
            del.textContent = 'Smazat';
            del.disabled = <?= $canEdit ? 'false' : 'true' ?>;
            del.setAttribute('aria-disabled', del.disabled ? 'true' : 'false');
            del.addEventListener('click', function() { deleteGalleryItem(Number(item.id)); });
            card.appendChild(del);
            container.appendChild(card);
        });
    }

    function loadGalleryItems() {
        if (!galleryCurrentWeek) return Promise.resolve();
        const container = document.getElementById('gallery-items');
        if (container) container.innerHTML = '<div class="gallery-empty">Načítám galerii…</div>';
        return postJson('gallery_list', { week: galleryCurrentWeek }).then(function(data) {
            if (!data || !data.success) throw new Error((data && data.message) || 'Galerii se nepodařilo načíst.');
            renderGalleryItems(data.items || []);
            setGalleryStatus('Obsazeno ' + (Array.isArray(data.items) ? data.items.length : 0) + ' z 10 míst.', false);
        }).catch(function(err) {
            if (container) container.innerHTML = '<div class="gallery-empty">Galerii se nepodařilo načíst.</div>';
            setGalleryStatus('DEBUG GALERIE – načítání selhalo (týden ' + galleryCurrentWeek + '): ' + (err && err.message ? err.message : err), true);
            console.error('Načtení galerie:', err);
        });
    }

    function uploadGalleryFiles() {
        const input = document.getElementById('gallery-file-input');
        const button = document.getElementById('gallery-upload-button');
        if (!galleryCurrentWeek || !input || !button) return;
        const files = Array.from(input.files || []);
        if (!files.length) { setGalleryStatus('Nejdříve vyber fotografie nebo videa.', true); return; }
        if (files.length > 10) { setGalleryStatus('Najednou můžeš vybrat nejvýše 10 souborů.', true); return; }
        const formData = new FormData();
        formData.append('week', String(galleryCurrentWeek));
        files.forEach(function(file) { formData.append('gallery_files[]', file, file.name); });
        button.disabled = true;
        setGalleryStatus('Nahrávám ' + files.length + ' souborů…', false);
        fetch(API + 'gallery_upload', { method: 'POST', credentials: 'same-origin', body: formData })
            .then(async function(response) {
                const raw = await response.text();
                let data;
                try { data = JSON.parse(raw); }
                catch (e) { throw new Error('Server nevrátil platnou JSON odpověď (HTTP ' + response.status + '). ' + raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200)); }
                if (!response.ok || !data.success) throw new Error(data.message || ('Nahrávání selhalo (HTTP ' + response.status + ').'));
                return data;
            })
            .then(function(data) {
                input.value = '';
                renderGalleryItems(data.items || []);
                setGalleryStatus(data.message || 'Soubory byly nahrány.', false);
            })
            .catch(function(err) {
                setGalleryStatus('DEBUG GALERIE – nahrání selhalo: ' + (err && err.message ? err.message : err), true);
                console.error('Nahrávání galerie:', err);
            })
            .finally(function() { button.disabled = false; });
    }

    function deleteGalleryItem(itemId) {
        if (!galleryCurrentWeek || !window.confirm('Opravdu chceš tento soubor z galerie smazat?')) return;
        postJson('gallery_delete', { week: galleryCurrentWeek, item_id: itemId }).then(function(data) {
            if (!data || !data.success) throw new Error((data && data.message) || 'Soubor se nepodařilo smazat.');
            setGalleryStatus(data.message || 'Soubor odstraněn.', false);
            return loadGalleryItems();
        }).catch(function(err) {
            setGalleryStatus('DEBUG GALERIE – mazání selhalo: ' + (err && err.message ? err.message : err), true);
        });
    }

    // ========== PHASE COLOR ==========
    function setColor(week, phase, color) {
        postJson('phase', { week: week, phase: phase, color: color })
        .then(function(data) {
            if (data.success) {
                var weekCard = document.querySelector('.growreport[data-week="' + week + '"]');
                var header = document.querySelector('#week-title-' + week).closest(".growreport-header");
                header.style.backgroundColor = color;
                if (weekCard) weekCard.dataset.phase = phase;
                updateFlowerWeekNumbers();
            } else { alert('Chyba: ' + (data.message || '')); }
        })
        .catch(function(error) { console.error('Chyba:', error); });
    }
    updateFlowerWeekNumbers();

    // ========== IMAGE COMPRESSION ==========
    function compressImage(file, fileId) {
        return new Promise(function(resolve, reject) {
            var URL2 = window.URL || window.webkitURL;
            var originalUrl = URL2.createObjectURL(file);
            var infoElement = document.getElementById('compression-info-' + fileId);
            if (infoElement) { infoElement.innerHTML = '<div class="loading-spinner"></div> Komprimace...'; infoElement.style.display = 'block'; }

            var img = new Image();
            img.onload = function() {
                URL2.revokeObjectURL(originalUrl);
                var width = img.width, height = img.height;
                if (width > COMPRESSION_MAX_WIDTH) { var ratio = COMPRESSION_MAX_WIDTH / width; width = COMPRESSION_MAX_WIDTH; height = Math.round(height * ratio); }

                function compressWithQuality(quality, attempt) {
                    attempt = attempt || 1;
                    var canvas = document.createElement('canvas');
                    canvas.width = width; canvas.height = height;
                    var ctx = canvas.getContext('2d');
                    ctx.fillStyle = 'white'; ctx.fillRect(0, 0, width, height);
                    ctx.drawImage(img, 0, 0, width, height);

                    canvas.toBlob(function(blob) {
                        var newName = file.name.replace(/\.[^.]+$/, '') + '.jpg';
                        var compressedFile = new File([blob], newName, { type: 'image/jpeg', lastModified: Date.now() });
                        var originalSize = file.size, compressedSize = compressedFile.size;
                        var compressionRatio = originalSize / compressedSize;

                        if (infoElement) {
                            infoElement.innerHTML = 'Komprimováno: ' + (originalSize / (1024 * 1024)).toFixed(2) + ' MB → ' + (compressedSize / (1024 * 1024)).toFixed(2) + ' MB<br>Kompresní poměr: ' + compressionRatio.toFixed(1) + '× menší';
                        }

                        if (Math.abs(compressionRatio - TARGET_COMPRESSION_RATIO) < 1.0 || attempt >= MAX_COMPRESSION_ATTEMPTS) {
                            resolve(compressedFile);
                        } else {
                            var newQuality = compressionRatio > TARGET_COMPRESSION_RATIO ? quality + (10 * (attempt === 1 ? 1 : 0.5)) : quality - (10 * (attempt === 1 ? 1 : 0.5));
                            newQuality = Math.max(10, Math.min(95, newQuality));
                            compressWithQuality(newQuality, attempt + 1);
                        }
                    }, 'image/jpeg', quality / 100);
                }
                compressWithQuality(COMPRESSION_QUALITY_START);
            };
            img.onerror = function() { if (infoElement) { infoElement.innerHTML = 'Komprese se nezdařila.'; infoElement.style.color = '#f44336'; } reject(new Error('Chyba')); };
            img.src = originalUrl;
        });
    }

    function previewImage(event, input) {
        var match = input.id.match(/file-input-(\d+)-(\d+)/);
        if (match) {
            var previewId = match[1] + '-' + match[2];
            var previewImg = document.getElementById('preview-' + previewId);
            var file = event.target.files[0];
            if (file) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    previewImg.src = e.target.result;
                    previewImg.style.display = "block";
                    var zoomButton = document.getElementById('photo-zoom-btn-' + previewId);
                    if (zoomButton) zoomButton.style.display = 'inline-flex';
                    var oldImg = input.parentElement.querySelector('img:not(.preview-image)');
                    if (oldImg) oldImg.style.display = 'none';
                    var noPhoto = input.parentElement.querySelector('p');
                    if (noPhoto) noPhoto.style.display = 'none';
                    var label = input.previousElementSibling;
                    if (label && label.tagName === 'LABEL') label.style.display = "none";

                    compressImage(file, previewId)
                    .then(function(cf) { compressedFiles[input.id] = cf; })
                    .catch(function(err) { console.error('Chyba:', err); compressedFiles[input.id] = file; });
                };
                reader.readAsDataURL(file);
            }
        }
    }

    // ========== TOGGLE DAY (only hnojeno now) ==========
    function toggleDayValue(week, day, isChecked, type) {
        var targetElement = document.getElementById('day-' + type + '-' + week + '-' + day);
        if (targetElement) {
            targetElement.style.backgroundColor = isChecked ? 'brown' : '';
        }
        postJson('toggle_day', { week: week, day: day, checked: isChecked, type: type })
        .then(function(data) { if (!data.success) console.log('Chyba:', data.message); })
        .catch(function(error) { console.error('Chyba:', error); });
    }

    // ========== SAVE TEXT ==========
    function saveText(week) {
        var area = document.getElementById('weekText-' + week);
        var msg = document.getElementById('responseMessage-text-' + week);
        if (!area || !msg) { alert('DEBUG ULOŽENÍ TEXTU: Chybí textové pole nebo stavová zpráva pro týden ' + week + '.'); return; }
        msg.textContent = 'DEBUG ULOŽENÍ TEXTU: Odesílám pouze text týdne ' + week + ' (bez nahrávání fotografií)…';
        msg.style.color = '#0d47a1';
        var buttons = document.querySelectorAll('.save-week-text-btn');
        var clickedButton = Array.from(buttons).find(function(btn) { return (btn.getAttribute('onclick') || '').indexOf('saveText(' + week + ')') !== -1; });
        if (clickedButton) clickedButton.disabled = true;
        postJson('save_text', { week: week, text: area.value })
        .then(function(data) {
            if (!data || !data.success) throw new Error((data && data.message) || 'Server nepotvrdil uložení textu.');
            msg.textContent = 'Text uložen. (Týden ' + week + '; fotografie nebyly požadovány.)';
            msg.style.color = '#2e7d32';
        })
        .catch(function(error) {
            console.error('DEBUG ULOŽENÍ TEXTU, týden ' + week + ':', error);
            msg.textContent = 'CHYBA ULOŽENÍ TEXTU – týden ' + week + ': ' + (error && error.message ? error.message : String(error));
            msg.style.color = '#b71c1c';
        })
        .finally(function() { if (clickedButton) clickedButton.disabled = false; });
    }

    // ========== SAVE GROWREPORT ==========
    function saveGrowreport() {
        var saveButton = document.getElementById('save-growreport');
        var debug = document.getElementById('save-growreport-debug');
        function showSaveDebug(message, kind) {
            if (debug) {
                debug.className = kind || '';
                debug.textContent = message;
                debug.style.display = 'block';
            }
            if (kind === 'error') console.error(message);
            else console.log(message);
        }
        try {
            var formData = new FormData();
            var growreports = Array.from(document.querySelectorAll('#growreport-container > .growreport'));
            var selectedPhotoCount = 0;
            if (!growreports.length) throw new Error('Na stránce nebyl nalezen žádný týden growreportu.');

            growreports.forEach(function(growreport, index) {
                var weekNumber = parseInt(growreport.getAttribute('data-week'), 10);
                var textArea = growreport.querySelector('textarea[id^="weekText-"]');
                var text = textArea ? textArea.value : '';
                formData.append('weeks[' + index + '][week_number]', weekNumber);
                formData.append('weeks[' + index + '][text]', text);

                var startDayInput = growreport.querySelector('input[id^="start-day-"]');
                if (startDayInput && startDayInput.value) formData.append('weeks[' + index + '][start_day]', startDayInput.value.trim());

                var hnojenoDays = [];
                growreport.querySelectorAll('input[id^="hnojeno-"]:checked').forEach(function(input) { hnojenoDays.push(input.value); });
                formData.append('weeks[' + index + '][hnojeno]', hnojenoDays.join(','));

                // Fotografie jsou nepovinné. Do požadavku se přidají pouze skutečně vybrané soubory.
                var photoInputs = Array.from(growreport.querySelectorAll('input[type="file"][id^="file-input-"]'));
                photoInputs.forEach(function(input) {
                    if (input.files && input.files.length > 0) {
                        var match = input.id.match(/file-input-\d+-(\d+)$/);
                        var photoNo = match ? Number(match[1]) : 0;
                        if (photoNo < 1 || photoNo > 4) {
                            showSaveDebug('DEBUG ULOŽENÍ: Neznámé pole fotografie ' + input.id + '. Fotografie bude přeskočena.', '');
                            return;
                        }
                        var fileToSend = compressedFiles[input.id] || input.files[0];
                        formData.append('weeks[' + index + '][photo' + photoNo + ']', fileToSend, fileToSend.name);
                        selectedPhotoCount++;
                    }
                });
            });

            showSaveDebug('DEBUG ULOŽENÍ: Zahájeno ukládání. Týdnů: ' + growreports.length + '. Vybraných fotografií: ' + selectedPhotoCount + '. Fotografie nejsou povinné; bez výběru se žádné nahrávat nebudou.', '');
            if (saveButton) saveButton.disabled = true;

            fetch(API + 'save', { method: 'POST', credentials: 'same-origin', body: formData })
                .then(async function(response) {
                    var raw = await response.text();
                    var data;
                    try { data = JSON.parse(raw); }
                    catch (parseError) {
                        var clean = raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().substring(0, 500);
                        throw new Error('Server nevrátil JSON odpověď (HTTP ' + response.status + '). Odpověď: ' + (clean || '(prázdná)'));
                    }
                    if (!response.ok) throw new Error('HTTP ' + response.status + ': ' + ((data && data.message) || raw.substring(0, 500)));
                    return data;
                })
                .then(function(data) {
                    if (!data || !data.success) throw new Error((data && data.message) || 'Server nepotvrdil uložení.');
                    showSaveDebug('DEBUG ULOŽENÍ: Server potvrdil úspěch (report ID ' + (data.report_id || '—') + '). Týdnů: ' + growreports.length + '; odeslaných fotografií: ' + selectedPhotoCount + '. Stránka se obnoví.', 'success');
                    window.setTimeout(function() { location.reload(); }, 900);
                })
                .catch(function(error) {
                    showSaveDebug('CHYBA ULOŽENÍ GROWREPORTU: ' + (error && error.message ? error.message : String(error)) + '\nZkontroluj PHP error log a zprávu výše. Fotografie nejsou povinné.', 'error');
                    alert('Growreport se nepodařilo uložit. Podrobnosti najdeš v červeném poli DEBUG ULOŽENÍ pod tlačítky.\n' + (error && error.message ? error.message : String(error)));
                })
                .finally(function() { if (saveButton) saveButton.disabled = false; });
        } catch (error) {
            showSaveDebug('CHYBA PŘED ODESLÁNÍM: ' + (error && error.message ? error.message : String(error)), 'error');
            if (saveButton) saveButton.disabled = false;
        }
    }

    // ========== KALENDÁŘ DNŮ A ÚPLŇKY ==========

    function addDaysToIsoDate(isoDate, offset) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(String(isoDate || ''))) return '';
        const p = isoDate.split('-').map(Number);
        const dt = new Date(Date.UTC(p[0], p[1] - 1, p[2]));
        dt.setUTCDate(dt.getUTCDate() + Number(offset || 0));
        return dt.getUTCFullYear() + '-' + String(dt.getUTCMonth() + 1).padStart(2, '0') + '-' + String(dt.getUTCDate()).padStart(2, '0');
    }

    function formatIsoDateCz(isoDate) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(String(isoDate || ''))) return '';
        const p = isoDate.split('-');
        return p[2] + '.' + p[1] + '.' + p[0];
    }

    function isoDateDifferenceInDays(fromDate, toDate) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(String(fromDate || '')) ||
            !/^\d{4}-\d{2}-\d{2}$/.test(String(toDate || ''))) return null;
        const a = fromDate.split('-').map(Number);
        const b = toDate.split('-').map(Number);
        const ta = Date.UTC(a[0], a[1] - 1, a[2]);
        const tb = Date.UTC(b[0], b[1] - 1, b[2]);
        return Math.round((tb - ta) / 86400000);
    }

    // Úplňky se dopočítávají od nejstaršího ručně zadaného data. Ručně zadané
    // datum má přednost před výpočtem, aby šlo opravit přibližný průměrný cyklus.
    function isFullMoonDate(isoDate) {
        if (!isoDate) return false;
        if (fullMoonDateSet.has(isoDate)) return true;
        if (!fullMoonAnchorDate) return false;
        const difference = isoDateDifferenceInDays(fullMoonAnchorDate, isoDate);
        if (difference === null) return false;
        const lunationIndex = Math.round(difference / SYNODIC_MONTH_DAYS);
        const estimatedDate = addDaysToIsoDate(fullMoonAnchorDate, Math.round(lunationIndex * SYNODIC_MONTH_DAYS));
        if (isoDate !== estimatedDate) return false;

        // Pokud administrátor zadal pro stejný úplněk přesnější datum (do 2 dnů),
        // neoznačuj navíc sousední automaticky odhadnutý den.
        const hasNearbyManualCorrection = Array.isArray(fullMoonDateList) && fullMoonDateList.some(function (savedDate) {
            if (savedDate === estimatedDate) return false;
            const delta = isoDateDifferenceInDays(estimatedDate, savedDate);
            return delta !== null && Math.abs(delta) <= 2;
        });
        return !hasNearbyManualCorrection;
    }

    function dailyTaskNumberText(value) {
        const n = Number(value || 0);
        return Number.isInteger(n) ? String(n) : n.toLocaleString('cs-CZ', { maximumFractionDigits: 1 });
    }
    function dailyTaskFertilizerText(item) {
        if (!item || typeof item !== 'object') return '';
        const name = String(item.name || '').trim();
        if (!name) return '';
        if (String(item.kind || '').toLowerCase() === 'dry' || Object.prototype.hasOwnProperty.call(item, 'scoops')) {
            const scoops = Math.max(0, Number(item.scoops || 0));
            if (!scoops) return '';
            const unit = scoops === 1 ? 'odměrka' : (scoops >= 2 && scoops <= 4 ? 'odměrky' : 'odměrek');
            return name + ' = ' + scoops + ' ' + unit;
        }
        if (name.toLocaleLowerCase('cs-CZ') === 'melasový čaj' && Number(item.water_l) > 0 && Number(item.teaspoons) > 0) {
            const spoons = Math.round(Number(item.teaspoons));
            const tablespoons = Math.floor(Number(item.water_l)/2);
            const remaining = Math.max(0,spoons-tablespoons*4);
            const water = Number(item.water_l).toLocaleString('cs-CZ', {maximumFractionDigits:1});
            const dose = (tablespoons ? tablespoons + ' polévková lžíce' + (tablespoons===1?'':'e') : '') + (remaining ? (tablespoons?' + ':'') + remaining + ' čajové lžičky' : '');
            return name + ' = ' + water + ' L vody, ' + dose;
        }
        const ml = Math.max(0, Math.round(Number(item.ml || 0)));
        if (!ml) return '';
        return name + ' = ' + (ml >= 100 && ml % 100 === 0 ? (ml / 100) + ' deci' : ml + ' ml');
    }
    function actionTaskIsCompleted(taskType, sourceId, sourceCompleted) {
        var saved = (Array.isArray(actionTaskStates) ? actionTaskStates : []).find(function(row) {
            return String(row.task_type || '') === String(taskType) && Number(row.source_id) === Number(sourceId);
        });
        return saved ? Number(saved.completed) === 1 : Number(sourceCompleted) === 1;
    }
    function updateActionSourceRecord(taskType, sourceId, completed) {
        var collections = {pruning:pruningRecords, vz:vzRecords, spray:sprayRecords, net:netRecords, custom_function:customFunctionMarks};
        var rows = collections[taskType];
        if (!Array.isArray(rows)) return;
        rows.forEach(function(row) { if (Number(row.id) === Number(sourceId)) row.completed = completed ? 1 : 0; });
    }
    function toggleActionTask(taskType, sourceId, button) {
        if (!button || button.disabled || button.dataset.saving === '1') return;
        var next = button.dataset.completed !== '1';
        button.dataset.saving = '1'; button.disabled = true;
        postJson('action_task_toggle', {task_type:taskType, source_id:Number(sourceId), completed:next})
            .then(function(data) {
                if (!data || !data.success || data.verified !== true) throw new Error((data && data.message) || 'Server nepotvrdil uložení stavu úkonu.');
                if (window.console && console.info) console.info('[Growreport DEBUG] Stav úkonu uložen a ověřen:', data);
                var found = (Array.isArray(actionTaskStates) ? actionTaskStates : []).find(function(row) {
                    return String(row.task_type || '') === String(taskType) && Number(row.source_id) === Number(sourceId);
                });
                if (found) found.completed = next ? 1 : 0;
                else actionTaskStates.push({task_type:taskType, source_id:Number(sourceId), completed:next ? 1 : 0});
                updateActionSourceRecord(taskType, sourceId, next);
                button.dataset.completed = next ? '1' : '0';
                button.classList.toggle('completed', next);
                var actionCard = button.closest('.daily-action-note-card');
                if (actionCard) actionCard.classList.toggle('completed', next);
                button.setAttribute('aria-pressed', next ? 'true' : 'false');
                button.textContent = next ? '✓ Splněno — vrátit' : '☐ Splněno';
            })
            .catch(function(error) { console.error('[Growreport DEBUG] Uložení splnění úkonu selhalo:', {task_type:taskType, source_id:Number(sourceId), error:error}); alert('Nepodařilo se uložit stav úkonu: ' + error.message); })
            .finally(function() { button.disabled = false; button.dataset.saving = '0'; });
    }
    function getDailyActionTasks(week, day, date) {
        var tasks = [];
        (Array.isArray(pruningRecords) ? pruningRecords : []).forEach(function(row) {
            if (String(row.record_date || '') === date) {
                var type = Number(row.action_type);
                tasks.push({type:'pruning', id:Number(row.id), completed:Number(row.completed)===1, label:(pruningActionNames[type] || 'Pěstitelský úkon')});
            }
        });
        (Array.isArray(vzRecords) ? vzRecords : []).forEach(function(row) {
            if (String(row.record_date || '') === date) {
                var label = vzRecordNames[String(row.record_type || '')] || 'V/Z';
                if (String(row.record_type) === 'zvlhcovac' && row.runtime_hours !== null && row.runtime_hours !== '') label += ' — ' + String(row.runtime_hours) + ' h';
                tasks.push({type:'vz', id:Number(row.id), completed:Number(row.completed)===1, label:label});
            }
        });
        (Array.isArray(sprayRecords) ? sprayRecords : []).forEach(function(row) {
            if (String(row.record_date || '') === date) {
                var names = (Array.isArray(row.sprays) ? row.sprays : []).map(function(item) {
                    return String(item.name || 'Postřik') + (item.ml ? ' — ' + String(item.ml) + ' ml' : '');
                });
                tasks.push({type:'spray', id:Number(row.id), completed:Number(row.completed)===1, label:'Sprej: ' + (names.join(', ') || 'Postřik')});
            }
        });
        (Array.isArray(netRecords) ? netRecords : []).forEach(function(row) {
            if (String(row.record_date || '') === date) tasks.push({type:'net', id:Number(row.id), completed:Number(row.completed)===1, label:'Síť nasazena'});
        });
        (Array.isArray(customFunctionMarks) ? customFunctionMarks : []).forEach(function(row) {
            if (String(row.record_date || '') === date) {
                var slot = Number(row.slot_number || 0);
                var setting = (Array.isArray(customFunctionSettings) ? customFunctionSettings : []).find(function(item) { return Number(item.slot_number) === slot; });
                var label = setting && String(setting.function_name || '').trim() ? String(setting.function_name).trim() : ('Funkce T ' + slot);
                tasks.push({type:'custom_function', id:Number(row.id), completed:Number(row.completed)===1, label:label});
            }
        });
        return tasks;
    }
    function renderDailyActionTasks(week, day, date) {
        var wrap = document.getElementById('daily-action-task-list-' + week + '-' + day);
        if (!wrap) return 0;
        wrap.innerHTML = '';
        var tasks = getDailyActionTasks(week, day, date);
        var globalDay = ((Number(week) - 1) * 7) + Number(day);
        tasks.forEach(function(task) {
            var completed = actionTaskIsCompleted(task.type, task.id, task.completed);
            // Každý zdrojový úkon je samostatná karta/poznámka – není potomkem
            // karty zalití a hnojiv a jeho stav splnění se ukládá podle vlastního ID.
            var card = document.createElement('div');
            card.className = 'daily-task-card daily-action-note-card' + (completed ? ' completed' : '');
            card.dataset.taskType = task.type;
            card.dataset.sourceId = String(task.id);
            card.dataset.hasData = '1';
            card.dataset.completed = completed ? '1' : '0';
            card.style.display = 'flex';

            var noteArea = document.createElement('div');
            noteArea.className = 'daily-task-text';
            var heading = document.createElement('strong');
            heading.className = 'daily-task-day';
            heading.textContent = 'Den ' + globalDay + '.';
            var entry = document.createElement('div');
            entry.className = 'daily-task-entry daily-task-entry-action';
            var prefix = 'Úkon: ';
            if (task.type === 'vz') prefix = 'V/Z: ';
            else if (task.type === 'spray') prefix = 'Postřik: ';
            else if (task.type === 'net') prefix = 'Síť: ';
            else if (task.type === 'custom_function') prefix = 'Vlastní funkce: ';
            entry.textContent = prefix + task.label;
            noteArea.appendChild(heading);
            noteArea.appendChild(entry);

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'daily-task-complete-btn';
            button.dataset.completed = completed ? '1' : '0';
            button.setAttribute('aria-pressed', completed ? 'true' : 'false');
            button.setAttribute('aria-label', (completed ? 'Vrátit splnění: ' : 'Označit jako splněno: ') + task.label);
            button.title = 'Samostatné splnění záznamu ' + task.label + ' (ID ' + task.id + ')';
            button.textContent = completed ? '✓ Splněno — vrátit' : '☐ Splněno';
            if (!CAN_EDIT) button.disabled = true;
            button.addEventListener('click', function() { toggleActionTask(task.type, task.id, button); });
            card.appendChild(noteArea);
            card.appendChild(button);
            wrap.appendChild(card);
        });
        return tasks.length;
    }
    function updateDailyTaskNote(week, day, preserveServerText) {
        const w = String(week), d = String(day);
        const card = document.getElementById('daily-task-card-' + w + '-' + d);
        const area = document.getElementById('daily-task-text-' + w + '-' + d);
        const section = document.getElementById('daily-tasks-section-' + w);
        if (!card || !area) return;
        const weekWater = wateringData[w] || wateringData[week] || {};
        const water = weekWater[d] || weekWater[day] || {};
        const potKeys = Object.keys(water).filter(function(k){ return Number(water[k]) > 0; }).sort(function(a,b){ return Number(a)-Number(b); });
        const weekFertilizers = dailyTaskFertilizersData[w] || dailyTaskFertilizersData[week] || {};
        const rawItems = weekFertilizers[d] || weekFertilizers[day] || [];
        const fertLines = (Array.isArray(rawItems) ? rawItems : []).map(dailyTaskFertilizerText).filter(Boolean);
        const weekHnojeno = dailyTaskHnojenoData[w] || dailyTaskHnojenoData[week] || {};
        const fertilized = !!(weekHnojeno[d] || weekHnojeno[day]);
        const hasWater = potKeys.length > 0;
        const actionDate = calendarStartDate ? addDaysToIsoDate(calendarStartDate, ((Number(week) - 1) * 7) + Number(day) - 1) : '';
        const actionTasks = actionDate ? getDailyActionTasks(Number(week), Number(day), actionDate) : [];
        const actionTaskCount = actionDate ? renderDailyActionTasks(Number(week), Number(day), actionDate) : 0;
        const calculatedHasData = hasWater || fertLines.length > 0 || fertilized || actionTaskCount > 0;
        const dailyWaterFertilizerHasData = hasWater || fertLines.length > 0 || fertilized;
        const dailyButton = document.getElementById('daily-task-button-' + w + '-' + d);
        if (dailyButton) dailyButton.style.display = dailyWaterFertilizerHasData ? '' : 'none';
        const serverHasData = card.dataset.hasData === '1';

        // Pokud server vykreslil uložené údaje, ale inicializační JSON je prázdný
        // nebo má jiný tvar, ponech serverový text místo jeho přepsání prázdným záznamem.
        // Při následném uložení/mazání už použijeme čerstvý stav z JavaScriptu.
        if (!calculatedHasData && preserveServerText && serverHasData) {
            card.style.display = 'flex';
            return;
        }
        const hasData = calculatedHasData;
        card.dataset.hasData = dailyWaterFertilizerHasData ? '1' : '0';
        const lines = ['Den ' + (((Number(week)-1)*7)+Number(day)) + '.'];
        if (hasWater) {
            lines.push('Zalito: ' + potKeys.map(function(k){ return getPotDisplayName(Number(k)) + ' = ' + dailyTaskNumberText(water[k]) + ' L'; }).join(', '));
        }
        if (fertLines.length) lines.push('Hnojiva: ' + fertLines.join(', '));
        else if (fertilized) lines.push('Hnojiva: záznam označen jako zahnojeno, bez rozepsaných položek.');
        // Voda + hnojiva tvoří společnou poznámku. Každý další úkon je
        // samostatná poznámková položka; nikdy se neslévá do společného textu dne.
        area.textContent = '';
        const dayHeading = document.createElement('strong');
        dayHeading.className = 'daily-task-day';
        dayHeading.textContent = lines[0] || '';
        area.appendChild(dayHeading);
        if (hasWater || fertLines.length || fertilized) {
            const baseEntry = document.createElement('div');
            baseEntry.className = 'daily-task-entry daily-task-entry-water';
            const baseLines = [];
            if (hasWater) baseLines.push('Zalito: ' + potKeys.map(function(k){ return getPotDisplayName(Number(k)) + ' = ' + dailyTaskNumberText(water[k]) + ' L'; }).join(', '));
            if (fertLines.length) baseLines.push('Hnojiva: ' + fertLines.join(', '));
            else if (fertilized) baseLines.push('Hnojiva: záznam označen jako zahnojeno, bez rozepsaných položek.');
            baseEntry.textContent = baseLines.join('\n');
            area.appendChild(baseEntry);
        }
        // Samostatné úkony se vykreslují mimo poznámku vody/hnojiv, každý se svým tlačítkem.
        // Každý denní záznam se zobrazí celý; posuvník patří pouze
        // společnému oknu se všemi denními záznamy, ne jednotlivé kartě.
        area.style.height = 'auto';
        area.style.maxHeight = 'none';
        area.style.overflow = 'visible';
        card.style.display = dailyWaterFertilizerHasData ? 'flex' : 'none';
        if (section) {
            const visible = Array.from(section.querySelectorAll('.daily-task-card')).some(function(el){ return el.style.display !== 'none'; });
            section.style.display = visible ? 'flex' : 'none';
        }
    }
    (function loadActionTaskStates() {
        postJson('action_task_states', {}).then(function(data) {
            if (!data || !data.success || !Array.isArray(data.items)) throw new Error('Server nevrátil stavy splnění úkonů.');
            actionTaskStates = data.items;
            document.querySelectorAll('.daily-task-card').forEach(function(card) {
                updateDailyTaskNote(Number(card.dataset.week), Number(card.dataset.day), false);
            });
            if (window.console && console.info) console.info('[Growreport] Načtené stavy přímo ze zdrojových záznamů:', actionTaskStates);
        }).catch(function(error) { console.error('[Growreport] Načtení stavů splnění selhalo:', error); });
    })();
    function initializeDailyTaskCards() {
        document.querySelectorAll('.daily-task-card').forEach(function(card){
            updateDailyTaskNote(Number(card.dataset.week), Number(card.dataset.day), true);
        });
    }
    function toggleDailyTask(week, day) {
        const card = document.getElementById('daily-task-card-' + week + '-' + day);
        const button = document.getElementById('daily-task-button-' + week + '-' + day);
        if (!card || !button || button.disabled || card.dataset.saving === '1') return;
        const next = card.dataset.completed !== '1';
        card.dataset.saving = '1'; button.disabled = true;
        postJson('daily_task_toggle', { week: Number(week), day: Number(day), completed: next })
            .then(function(data){
                if (!data || !data.success) throw new Error((data && data.message) || 'Stav úkolu se nepodařilo uložit.');
                card.dataset.completed = next ? '1' : '0';
                card.classList.toggle('completed', next);
                button.setAttribute('aria-pressed', next ? 'true' : 'false');
                button.textContent = next ? '✓ Splněno — vrátit' : '☐ Splněno';
            })
            .catch(function(error){ alert('Nepodařilo se uložit stav úkolu: ' + error.message); })
            .finally(function(){ button.disabled = false; card.dataset.saving = '0'; });
    }

    function updateDays(week) {
        var startDayInput = document.getElementById('start-day-' + week);
        var daysContainer = document.getElementById('days-' + week);
        if (!startDayInput || !daysContainer) return;

        var startDay = String(startDayInput.value || '').trim().toLowerCase();
        if (!calendarDayNames.includes(startDay)) {
            daysContainer.innerHTML = "<p style='color: red;'>Nejprve klikni na První den.</p>";
            return;
        }

        daysContainer.innerHTML = '';
        var currentLocalDate = new Date();
        var todayIsoDate = currentLocalDate.getFullYear() + '-' + String(currentLocalDate.getMonth() + 1).padStart(2, '0') + '-' + String(currentLocalDate.getDate()).padStart(2, '0');
        var startIndex = calendarDayNames.indexOf(startDay);
        for (var i = 0; i < 7; i++) {
            var dayElement = document.createElement('div');
            dayElement.className = 'day';
            var dayName = calendarDayNames[(startIndex + i) % 7];
            var nameElement = document.createElement('span');
            nameElement.className = 'calendar-day-name';
            nameElement.textContent = dayName;
            dayElement.appendChild(nameElement);

            var dateForDay = calendarStartDate ? addDaysToIsoDate(calendarStartDate, ((Number(week) - 1) * 7) + i) : '';
            if (dateForDay && dateForDay === todayIsoDate) {
                dayElement.classList.add('today-day');
                dayElement.setAttribute('aria-label', 'Dnešní den');
            }
            if (dateForDay) {
                var dateParts = dateForDay.split('-');
                var dateElement = document.createElement('span');
                dateElement.className = 'calendar-day-date';
                dateElement.textContent = dateParts[2] + '.' + dateParts[1] + '.' + dateParts[0];
                dayElement.appendChild(dateElement);
                dayElement.dataset.calendarDate = dateForDay;
            }

            var dayColors = [];
            var dayDescriptions = [];
            var isMoonDay = !!(fullMoonHighlightActive && dateForDay && isFullMoonDate(dateForDay));
            if (isMoonDay) {
                dayElement.classList.add('full-moon-day');
                dayColors.push('#ffd700');
                dayDescriptions.push('Úplněk');
            }

            var matchingPruning = (Array.isArray(pruningRecords) ? pruningRecords : []).filter(function(row) {
                return String(row.record_date || '') === dateForDay;
            });
            matchingPruning.forEach(function(row) {
                dayElement.classList.add('pruning-day');
                var pruningType = Number(row.action_type);
                if (pruningType === 8) {
                    dayElement.classList.add('defoliation-day');
                    dayColors.push('#39ff14');
                } else if (pruningType === 9) {
                    dayElement.classList.add('harvest-day');
                    dayColors.push('#e53935');
                } else if (pruningType === 10) {
                    dayColors.push('#5d4037');
                } else if ([4, 5, 6].includes(pruningType)) {
                    dayColors.push('#ffffff');
                } else {
                    dayColors.push('#dcedc8');
                }
                dayDescriptions.push(pruningActionNames[pruningType] || 'Pěstitelský úkon');
            });

            var matchingVz = (Array.isArray(vzRecords) ? vzRecords : []).filter(function(row) {
                return String(row.record_date || '') === dateForDay;
            });
            matchingVz.forEach(function(row) {
                dayElement.classList.add('vz-day');
                dayColors.push('#123d73');
                var vzLabel = vzRecordNames[String(row.record_type || '')] || 'V/Z';
                if (String(row.record_type) === 'zvlhcovac' && row.runtime_hours !== null && row.runtime_hours !== '') {
                    vzLabel += ' — ' + String(row.runtime_hours) + ' h';
                }
                dayDescriptions.push(vzLabel);
            });

            var matchingSprays = (Array.isArray(sprayRecords) ? sprayRecords : []).filter(function(row) {
                return String(row.record_date || '') === dateForDay;
            });
            matchingSprays.forEach(function(row) {
                dayElement.classList.add('spray-day');
                dayColors.push('#efdfd3');
                var sprayNames = [];
                (Array.isArray(row.sprays) ? row.sprays : []).forEach(function(item) {
                    sprayNames.push(String(item.name || 'Postřik') + ' ' + String(item.ml || '') + ' ml');
                });
                dayDescriptions.push(sprayNames.length ? 'Postřik: ' + sprayNames.join(', ') : 'Postřik');
            });

            var matchingNet = (Array.isArray(netRecords) ? netRecords : []).filter(function(row) {
                return String(row.record_date || '') === dateForDay;
            });
            matchingNet.forEach(function() {
                dayElement.classList.add('net-day');
                dayColors.push('#d9efff');
                dayDescriptions.push('Síť nasazena');
            });

            // Zaznamenaný výtok zvýrazní příslušný kalendářní den svítivě červeně.
            var runoffWeekKey = String(week);
            var runoffDayKey = String(i + 1);
            var runoffPhWeek = wateringRunoffPHData && wateringRunoffPHData[runoffWeekKey] ? wateringRunoffPHData[runoffWeekKey] : {};
            var runoffLitersWeek = wateringRunoffLitersData && wateringRunoffLitersData[runoffWeekKey] ? wateringRunoffLitersData[runoffWeekKey] : {};
            var runoffPhValueForDay = runoffPhWeek[runoffDayKey];
            var runoffLitersValueForDay = runoffLitersWeek[runoffDayKey];
            var hasRunoffRecord = (runoffPhValueForDay !== undefined && runoffPhValueForDay !== null && runoffPhValueForDay !== '' && Number.isFinite(Number(runoffPhValueForDay))) ||
                (runoffLitersValueForDay !== undefined && runoffLitersValueForDay !== null && runoffLitersValueForDay !== '' && Number(runoffLitersValueForDay) > 0);
            if (hasRunoffRecord) {
                dayElement.classList.add('runoff-day');
                dayColors.push('#ff1744');
                var runoffDescription = 'Výtok';
                if (runoffLitersValueForDay !== undefined && runoffLitersValueForDay !== null && runoffLitersValueForDay !== '') {
                    runoffDescription += ' — ' + String(runoffLitersValueForDay).replace('.', ',') + ' l';
                }
                if (runoffPhValueForDay !== undefined && runoffPhValueForDay !== null && runoffPhValueForDay !== '') {
                    runoffDescription += (runoffDescription === 'Výtok' ? ' — ' : ', pH ') + String(runoffPhValueForDay).replace('.', ',');
                }
                dayDescriptions.push(runoffDescription);
            }

            var matchingCustomFunctions = (Array.isArray(customFunctionMarks) ? customFunctionMarks : []).filter(function(row) {
                return String(row.record_date || '') === dateForDay;
            });
            matchingCustomFunctions.forEach(function(row) {
                var slot = Number(row.slot_number || 0);
                var setting = (Array.isArray(customFunctionSettings) ? customFunctionSettings : []).find(function(item) { return Number(item.slot_number) === slot; });
                var color = setting && customFunctionColors[ String(setting.color || '').toLowerCase() ] ? String(setting.color).toLowerCase() : '#ff9100';
                var label = setting && String(setting.function_name || '').trim() ? String(setting.function_name).trim() : ('Funkce ' + slot);
                dayElement.classList.add('custom-function-day');
                dayColors.push(color);
                dayDescriptions.push(label);
            });

            // Jediný záznam vyplní celé pole jednou barvou. Více záznamů
            // rozdělí celé pole na stejně široké barevné pruhy vedle sebe.
            // !important je potřeba kvůli původním barevným třídám kalendáře.
            if (dayColors.length === 1) {
                dayElement.style.setProperty('background', dayColors[0], 'important');
            } else if (dayColors.length > 1) {
                var gradientStops = [];
                var segmentWidth = 100 / dayColors.length;
                dayColors.forEach(function(color, index) {
                    var from = (index * segmentWidth).toFixed(4);
                    var to = ((index + 1) * segmentWidth).toFixed(4);
                    gradientStops.push(color + ' ' + from + '%', color + ' ' + to + '%');
                });
                dayElement.style.setProperty('background', 'linear-gradient(to right, ' + gradientStops.join(', ') + ')', 'important');
                dayElement.style.setProperty('color', '#263238', 'important');
                dayElement.style.setProperty('border-color', '#78909c', 'important');
                [nameElement].concat(Array.from(dayElement.querySelectorAll('.calendar-day-date'))).forEach(function(label) {
                    label.style.setProperty('color', '#263238', 'important');
                    label.style.setProperty('text-shadow', '0 0 2px #fff, 0 0 3px #fff', 'important');
                });
            }

            // Více událostí v jednom dni zůstává zobrazeno jako barevné pruhy.
            // Defoliace již nepřepisuje celý gradient, aby byl vidět i výtok a další záznamy.

            var baseTitle = dayName + (dateForDay ? ' ' + formatIsoDateCz(dateForDay) : '');
            if (dayDescriptions.length) baseTitle += ' — ' + dayDescriptions.join(' · ');
            dayElement.title = baseTitle;
            daysContainer.appendChild(dayElement);
        }
        for (var taskDay = 1; taskDay <= 7; taskDay++) {
            updateDailyTaskNote(Number(week), taskDay, false);
        }
    }

    function toggleProblemMark(btn, week, day) {
        if (!btn || btn.dataset.saving === '1') return;
        const nextMarked = btn.dataset.marked !== '1';
        btn.dataset.saving = '1';
        btn.disabled = true;
        postJson('set_problem_mark', { week: week, day: day, marked: nextMarked })
            .then(data => {
                if (!data || !data.success) throw new Error((data && data.message) || 'Server neuložil označení problému.');
                btn.dataset.marked = nextMarked ? '1' : '0';
                btn.classList.toggle('is-marked', nextMarked);
                btn.setAttribute('aria-pressed', nextMarked ? 'true' : 'false');
                btn.innerHTML = nextMarked ? '<span class="problem-mark" aria-hidden="true">!</span>' : '';
            })
            .catch(err => {
                console.error('Uložení označení problému selhalo:', err);
                alert('Nepodařilo se uložit označení problému pro týden ' + week + ', den ' + day + ': ' + err.message);
            })
            .finally(() => {
                btn.disabled = false;
                btn.dataset.saving = '0';
            });
    }

    function openCalendarSettings() {
        const dialog = document.getElementById('calendar-config-dialog');
        if (!dialog) return;
        const storedWeekday = document.getElementById('start-day-1')?.value || calendarDayNames[0];
        const checked = document.querySelector('input[name="calendar-first-weekday"]:checked');
        if (!checked) {
            const target = document.querySelector('input[name="calendar-first-weekday"][value="' + storedWeekday + '"]');
            if (target) target.checked = true;
        }
        const dateInput = document.getElementById('calendar-start-date');
        if (dateInput) dateInput.value = calendarStartDate || '';
        showCalendarConfigMessage('', false);
        dialog.style.display = 'flex';
    }

    function closeCalendarSettings() {
        const dialog = document.getElementById('calendar-config-dialog');
        if (dialog) dialog.style.display = 'none';
    }

    function showCalendarConfigMessage(message, isError) {
        const box = document.getElementById('calendar-config-message');
        if (!box) return;
        box.textContent = message || '';
        box.style.display = message ? 'block' : 'none';
        box.style.color = isError ? '#8b0000' : '#1b5e20';
        box.style.background = isError ? '#fff3f3' : '#eef8e9';
        box.style.border = isError ? '1px solid #c62828' : '1px solid #8bc34a';
    }

    function syncWeekdayFromCalendarDate() {
        const dateInput = document.getElementById('calendar-start-date');
        if (!dateInput || !dateInput.value) return;
        const d = dateInput.value.split('-').map(Number);
        const dt = new Date(d[0], d[1] - 1, d[2], 12, 0, 0);
        const jsDay = dt.getDay(); // Sunday=0
        const isoDay = jsDay === 0 ? 7 : jsDay;
        const weekday = calendarDayNames[isoDay - 1];
        const radio = document.querySelector('input[name="calendar-first-weekday"][value="' + weekday + '"]');
        if (radio) radio.checked = true;
    }

    function syncDateFromWeekday() {
        const dateInput = document.getElementById('calendar-start-date');
        const selectedRadio = document.querySelector('input[name="calendar-first-weekday"]:checked');
        if (!dateInput || !dateInput.value || !selectedRadio) return;
        const d = dateInput.value.split('-').map(Number);
        const dt = new Date(d[0], d[1] - 1, d[2], 12, 0, 0);
        const jsDay = dt.getDay();
        const currentIsoDay = jsDay === 0 ? 7 : jsDay;
        const selectedIsoDay = calendarDayNames.indexOf(selectedRadio.value) + 1;
        dt.setDate(dt.getDate() + (selectedIsoDay - currentIsoDay));
        dateInput.value = dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0') + '-' + String(dt.getDate()).padStart(2, '0');
    }

    function saveCalendarSettings() {
        const selectedRadio = document.querySelector('input[name="calendar-first-weekday"]:checked');
        const dateInput = document.getElementById('calendar-start-date');
        const firstWeekday = selectedRadio ? selectedRadio.value : '';
        const startDate = dateInput ? dateInput.value : '';
        if (!firstWeekday) { showCalendarConfigMessage('Vyber počáteční den týdne.', true); return; }
        if (!startDate) { showCalendarConfigMessage('Vyber počáteční datum v kalendáři.', true); return; }
        showCalendarConfigMessage('Ukládám nastavení…', false);
        postJson('save_calendar_settings', { first_weekday: firstWeekday, start_date: startDate })
        .then(function(data) {
            if (!data || !data.success) throw new Error((data && data.message) || 'Server neuložil kalendář.');
            calendarStartDate = data.start_date;
            document.querySelectorAll('input[id^="start-day-"]').forEach(function(el) { el.value = data.first_weekday; });
            document.querySelectorAll('.growreport[data-week]').forEach(function(el) { updateDays(Number(el.getAttribute('data-week'))); });
            closeCalendarSettings();
            const msg = document.getElementById('full-moon-help');
            if (msg) msg.textContent = 'Začátek: ' + formatIsoDateCz(calendarStartDate) + '. Dny s úplňkem označí tlačítko Sklizeň podle úplňku.';
        })
        .catch(function(error) { showCalendarConfigMessage('Nepodařilo se uložit první den: ' + error.message, true); });
    }

    function syncFullMoonButtons() {
        const button = document.getElementById('full-moon-toggle');
        if (button) {
            button.classList.toggle('active', fullMoonHighlightActive);
            button.setAttribute('aria-pressed', fullMoonHighlightActive ? 'true' : 'false');
            button.textContent = fullMoonHighlightActive ? '🌕 Skrýt označení úplňků' : '🌕 Sklizeň podle úplňku';
        }
        document.querySelectorAll('.full-moon-mini-btn').forEach(function(miniButton) {
            miniButton.classList.toggle('active', fullMoonHighlightActive);
            miniButton.setAttribute('aria-pressed', fullMoonHighlightActive ? 'true' : 'false');
            miniButton.title = fullMoonHighlightActive ? 'Skrýt označení úplňků' : 'Sklizeň podle úplňku';
        });
    }

    function toggleFullMoonHarvest() {
        if (!calendarStartDate) {
            alert('Nejprve nastav První den a počáteční datum growreportu.');
            return;
        }
        if (!fullMoonAnchorDate) {
            alert('Administrátor musí nejprve zadat alespoň jedno známé datum úplňku.');
            return;
        }
        fullMoonHighlightActive = !fullMoonHighlightActive;
        try {
            window.localStorage.setItem(fullMoonStateStorageKey, fullMoonHighlightActive ? '1' : '0');
        } catch (e) {
            console.warn('Nepodařilo se uložit stav zobrazení úplňků do místního úložiště.', e);
        }
        document.querySelectorAll('.growreport[data-week]').forEach(function(el) { updateDays(Number(el.getAttribute('data-week'))); });
        syncFullMoonButtons();
    }

    function addFullMoonDate() {
        const input = document.getElementById('full-moon-date');
        const message = document.getElementById('full-moon-admin-message');
        const moonDate = input ? input.value : '';
        if (!moonDate) { if (message) { message.textContent = 'Vyber datum úplňku.'; message.style.color = '#b71c1c'; } return; }
        postJson('full_moon_add', { moon_date: moonDate })
        .then(function(data) {
            if (!data || !data.success) throw new Error((data && data.message) || 'Server neuložil úplněk.');
            if (message) { message.textContent = data.already_exists ? 'Toto datum už je uložené.' : 'Úplněk byl uložen.'; message.style.color = '#1b5e20'; }
            if (!data.already_exists) window.location.reload();
        })
        .catch(function(err) { if (message) { message.textContent = 'Chyba při ukládání úplňku: ' + err.message; message.style.color = '#b71c1c'; } });
    }

    function deleteFullMoonDate(id) {
        if (!confirm('Opravdu chceš smazat toto datum úplňku?')) return;
        postJson('full_moon_delete', { id: id })
        .then(function(data) { if (!data || !data.success) throw new Error((data && data.message) || 'Server nesmazal úplněk.'); window.location.reload(); })
        .catch(function(err) { alert('Nepodařilo se smazat úplněk: ' + err.message); });
    }

    function renderPruningHistory() {
        var wrap = document.getElementById('pruning-history-wrap');
        if (!wrap) return;
        var rows = Array.isArray(pruningRecords) ? pruningRecords.slice() : [];
        if (!rows.length) { wrap.innerHTML = '<p style="color:#777">Zatím nejsou uložené žádné úkony.</p>'; return; }
        var table = document.createElement('table'); table.className = 'pruning-history';
        table.innerHTML = '<thead><tr><th>Datum</th><th>Úkon</th><th>Barva</th><th>Záznam vytvořen</th><th aria-label="Smazat"></th></tr></thead>';
        var tbody = document.createElement('tbody');
        rows.sort(function(a,b){ return String(b.record_date).localeCompare(String(a.record_date)); }).forEach(function(row){
            var tr = document.createElement('tr');
            var date = document.createElement('td'); date.textContent = formatIsoDateCz(String(row.record_date || '')); tr.appendChild(date);
            var action = document.createElement('td'); action.textContent = (pruningActionNames[Number(row.action_type)] || 'Neznámý úkon'); tr.appendChild(action);
            var actionColor = pruningActionColor(row.action_type);
            appendGrowreportHistoryColorCell(tr, actionColor.color, actionColor.label);
            var created = document.createElement('td'); created.textContent = String(row.created_at || ''); tr.appendChild(created);
            var deleteCell = document.createElement('td'); deleteCell.style.textAlign = 'center';
            var del = document.createElement('button'); del.type = 'button'; del.className = 'pruning-history-delete'; del.textContent = '×'; del.title = 'Smazat tento záznam'; del.setAttribute('aria-label', 'Smazat záznam ' + formatIsoDateCz(String(row.record_date || '')));
            del.addEventListener('click', function(){ deletePruningRecord(Number(row.id)); });
            deleteCell.appendChild(del); tr.appendChild(deleteCell);
            tbody.appendChild(tr);
        });
        table.appendChild(tbody); wrap.innerHTML = ''; wrap.appendChild(table);
    }

    function openPruningDialog(week) {
        pruningCurrentWeek = Number(week);
        if (!Number.isInteger(pruningCurrentWeek) || pruningCurrentWeek < 0) pruningCurrentWeek = 1;
        var dateSelect = document.getElementById('pruning-date');
        var dialog = document.getElementById('pruning-dialog');
        var message = document.getElementById('pruning-message');
        if (!calendarStartDate) { alert('Nejprve nastav První den a počáteční datum growreportu.'); return; }
        if (!dateSelect || !dialog) return;
        dateSelect.innerHTML = '';
        for (var day = 1; day <= 7; day++) {
            var iso = addDaysToIsoDate(calendarStartDate, ((pruningCurrentWeek - 1) * 7) + day - 1);
            var option = document.createElement('option'); option.value = String(day) + '|' + iso;
            option.textContent = formatIsoDateCz(iso) + ' — ' + calendarDayNames[(calendarDayNames.indexOf((document.getElementById('start-day-' + pruningCurrentWeek) || {}).value || calendarDayNames[0]) + day - 1 + 7) % 7];
            dateSelect.appendChild(option);
        }
        var existing = (Array.isArray(pruningRecords) ? pruningRecords : []).find(function(row){ return Number(row.week_number) === pruningCurrentWeek; });
        if (existing) dateSelect.value = String(existing.day_number) + '|' + String(existing.record_date);
        if (message) { message.textContent = ''; message.style.color = '#333'; }
        renderPruningHistory();
        dialog.style.display = 'flex';
    }

    function closePruningDialog() {
        var dialog = document.getElementById('pruning-dialog'); if (dialog) dialog.style.display = 'none';
    }
    (function(){ var dialog=document.getElementById('pruning-dialog'); if(dialog) dialog.addEventListener('click',function(event){ if(event.target===dialog) closePruningDialog(); }); })();

    function deletePruningRecord(id) {
        if (!id || !window.confirm('Opravdu chceš smazat tento záznam zastřižení/FIM/LST?')) return;
        postJson('pruning_delete', {id:id}).then(function(data){
            if (!data || !data.success || !Array.isArray(data.items)) throw new Error((data && data.message) || 'Záznam se nepodařilo smazat.');
            pruningRecords = data.items;
            pruningRecordDates = new Set(pruningRecords.map(function(row){ return String(row.record_date || ''); }));
            document.querySelectorAll('.growreport[data-week]').forEach(function(el){ updateDays(Number(el.getAttribute('data-week'))); });
            renderPruningHistory();
            var message = document.getElementById('pruning-message');
            if (message) { message.textContent = 'Záznam byl smazán.'; message.style.color = '#2e7d32'; }
        }).catch(function(error){ alert('Mazání záznamu selhalo: ' + error.message); });
    }

    function savePruningAction() {
        var dateSelect = document.getElementById('pruning-date');
        var typeSelect = document.getElementById('pruning-action-type');
        var message = document.getElementById('pruning-message');
        var selected = dateSelect && dateSelect.value ? dateSelect.value.split('|') : [];
        var day = Number(selected[0] || 0), date = selected[1] || '', type = Number(typeSelect ? typeSelect.value : 0);
        if (!day || !date || !pruningActionNames[type]) { if (message) message.textContent = 'Vyber datum a úkon.'; return; }
        if (message) { message.textContent = 'Ukládám…'; message.style.color = '#333'; }
        postJson('pruning_save', {week:pruningCurrentWeek, day:day, record_date:date, action_type:type}).then(function(data){
            if (!data || !data.success) throw new Error((data && data.message) || 'Záznam se nepodařilo uložit.');
            pruningRecords = Array.isArray(data.items) ? data.items : [];
            pruningRecordDates = new Set(pruningRecords.map(function(row){ return String(row.record_date || ''); }));
            document.querySelectorAll('.growreport[data-week]').forEach(function(el){ updateDays(Number(el.getAttribute('data-week'))); });
            renderPruningHistory();
            if (message) { message.textContent = 'Uloženo: ' + pruningActionNames[type] + ' — ' + formatIsoDateCz(date) + '.'; message.style.color = '#2e7d32'; }
        }).catch(function(error){ if (message) { message.textContent = 'Chyba: ' + error.message; message.style.color = '#b71c1c'; } });
    }

    (function loadPruningRecords() {
        postJson('pruning_list', {}).then(function(data){
            if (!data || !data.success || !Array.isArray(data.items)) return;
            pruningRecords = data.items;
            pruningRecordDates = new Set(pruningRecords.map(function(row){ return String(row.record_date || ''); }));
            document.querySelectorAll('.growreport[data-week]').forEach(function(el){ updateDays(Number(el.getAttribute('data-week'))); });
        }).catch(function(error){ console.warn('Záznamy zastřižení/FIM/LST se nepodařilo načíst:', error); });
    })();

    // Enter potvrzuje právě otevřený dialog stejně jako kliknutí na jeho hlavní OK/Uložit.
    // CO2, teplota a vlhkost ukládají přímo svou funkci; tím se obejde nespolehlivé
    // automatické vyhledávání tlačítka v těchto třech starších modálních oknech.
    // Víceřádkové poznámky a ovládání selectů zůstávají beze změny.
    document.addEventListener('keydown', function(event) {
        if (event.key !== 'Enter' || event.defaultPrevented || event.isComposing || event.shiftKey || event.ctrlKey || event.altKey || event.metaKey) return;
        const target = event.target;
        if (!target || !target.matches || !target.matches('input:not([type="checkbox"]):not([type="radio"]):not([type="button"]):not([type="submit"]):not([type="reset"]):not([type="file"]), [contenteditable="true"]')) return;
        const valueDialog = target.closest('#co2-dialog, #temp-dialog, #humidity-dialog');
        if (valueDialog) {
            const valueDialogStyle = getComputedStyle(valueDialog);
            if (valueDialogStyle.display === 'none' || valueDialogStyle.visibility === 'hidden') return;
            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation();
            if (valueDialog.id === 'co2-dialog') saveCo2();
            else if (valueDialog.id === 'temp-dialog') saveTemperature();
            else if (valueDialog.id === 'humidity-dialog') saveHumidity();
            return;
        }
        if (target.isContentEditable && target.closest('[contenteditable="true"]') && target.closest('[contenteditable="true"]') !== target) return;
        const dialog = target.closest('.confirm-dialog-overlay, .pruning-dialog-backdrop, .spray-dialog-backdrop, .net-dialog-backdrop, .vz-dialog-backdrop, .airflow-modal-backdrop, .calendar-config-dialog, [role="dialog"]');
        if (!dialog) return;
        const dialogStyle = getComputedStyle(dialog);
        if (dialogStyle.display === 'none' || dialogStyle.visibility === 'hidden') return;
        const isVisible = function(button) {
            const style = getComputedStyle(button);
            return !button.disabled && style.display !== 'none' && style.visibility !== 'hidden' && button.getClientRects().length > 0;
        };
        // Nejdříve hledáme výslovně označené potvrzovací tlačítko.
        let candidates = Array.from(dialog.querySelectorAll('button.confirm-ok, button.confirm-ok-blue, button[id$="-ok"], button[id$="-save"], input[type="submit"]')).filter(isVisible);
        // U vícekrokových oken (např. Světla/Vzduch) je potvrzením i tlačítko Další/OK.
        if (!candidates.length) {
            candidates = Array.from(dialog.querySelectorAll('.confirm-actions button, .confirm-actions input[type="submit"], button[type="submit"]')).filter(function(button) {
                if (!isVisible(button)) return false;
                const label = String(button.textContent || button.value || '').trim().toLowerCase();
                return !/zrušit|zavřít|zpět|smazat|odstranit|cancel|close|back|delete/.test(label);
            });
        }
        const primary = candidates[candidates.length - 1];
        if (primary) {
            event.preventDefault();
            event.stopPropagation();
            primary.click();
        }
    }, true);

    function refreshCustomFunctionSettingsUI() {
        for (var slot = 1; slot <= 3; slot++) {
            var row = (Array.isArray(customFunctionSettings) ? customFunctionSettings : []).find(function(item){ return Number(item.slot_number) === slot; });
            var nameInput = document.getElementById('custom-function-name-' + slot);
            var colorSelect = document.getElementById('custom-function-color-' + slot);
            if (nameInput) nameInput.value = row ? String(row.function_name || '') : '';
            if (colorSelect) colorSelect.value = row && customFunctionColors[String(row.color || '').toLowerCase()] ? String(row.color).toLowerCase() : '#ff9100';
        }
        var slotSelect = document.getElementById('custom-function-slot');
        if (slotSelect) {
            Array.from(slotSelect.options).forEach(function(option){
                var row = (Array.isArray(customFunctionSettings) ? customFunctionSettings : []).find(function(item){ return Number(item.slot_number) === Number(option.value); });
                var label = row && String(row.function_name || '').trim() ? String(row.function_name).trim() : ('Funkce ' + option.value);
                option.textContent = label;
            });
        }
    }

    function renderCustomFunctionHistory() {
        var wrap = document.getElementById('custom-functions-history');
        if (!wrap) return;
        var rows = Array.isArray(customFunctionMarks) ? customFunctionMarks.slice() : [];
        if (!rows.length) { wrap.innerHTML = '<p style="color:#777">Zatím nejsou uložené žádné záznamy.</p>'; return; }
        var table = document.createElement('table'); table.className = 'custom-function-history';
        table.innerHTML = '<thead><tr><th>Datum</th><th>Funkce</th><th>Barva</th><th></th></tr></thead>';
        var tbody = document.createElement('tbody');
        rows.forEach(function(row){
            var setting = (Array.isArray(customFunctionSettings) ? customFunctionSettings : []).find(function(item){ return Number(item.slot_number) === Number(row.slot_number); });
            var tr = document.createElement('tr');
            var date = document.createElement('td'); date.textContent = formatIsoDateCz(String(row.record_date || '')); tr.appendChild(date);
            var name = document.createElement('td'); name.textContent = setting && String(setting.function_name || '').trim() ? String(setting.function_name).trim() : ('Funkce ' + row.slot_number); tr.appendChild(name);
            var functionColor = setting && setting.color ? String(setting.color).toLowerCase() : '#ff9100';
            var color = document.createElement('td'); color.textContent = customFunctionColors[functionColor] || 'Svítivě oranžová'; color.style.background = functionColor; color.style.color = '#263238'; color.style.fontWeight = '700'; color.style.textAlign = 'center'; tr.appendChild(color);
            var cell = document.createElement('td');
            var del = document.createElement('button'); del.type = 'button'; del.className = 'pruning-history-delete'; del.textContent = '×'; del.title = 'Smazat označení';
            del.addEventListener('click', function(){ deleteCustomFunctionMark(Number(row.id)); }); cell.appendChild(del); tr.appendChild(cell); tbody.appendChild(tr);
        });
        table.appendChild(tbody); wrap.innerHTML = ''; wrap.appendChild(table);
    }

    var electricitySettings = Object.assign({price_per_kwh:0,day_price_per_kwh:0,night_price_per_kwh:0,day_hours:16,night_hours:8,extra_device_watts:0}, (typeof electricityInitialSettings === 'object' && electricityInitialSettings !== null) ? electricityInitialSettings : {});
    function ensureElectricitySettings(){
        if (!electricitySettings || typeof electricitySettings !== 'object' || Array.isArray(electricitySettings)) electricitySettings = {};
        var defaults={price_per_kwh:0,day_price_per_kwh:0,night_price_per_kwh:0,day_hours:16,night_hours:8,extra_device_watts:0};
        Object.keys(defaults).forEach(function(key){if(electricitySettings[key]===undefined || electricitySettings[key]===null) electricitySettings[key]=defaults[key];});
        return electricitySettings;
    }
    function electricityDebug(message) {
        var box=document.getElementById('electricity-debug');
        if(box){ var stamp=new Date().toLocaleTimeString('cs-CZ'); box.textContent='['+stamp+'] '+String(message); }
        try { console.log('[Growreport E]',message); } catch(e) {}
    }
    function toggleElectricityTariffs(){
        var panel=document.getElementById('electricity-tariff-settings'),btn=document.getElementById('electricity-tariff-toggle');
        if(!panel||!btn)return;var opening=panel.style.display==='none'||!panel.style.display;
        panel.style.display=opening?'block':'none';btn.setAttribute('aria-expanded',opening?'true':'false');btn.textContent=(opening?'−':'＋')+' Proud ZZ – nastavení cen a hodin';
    }
    var electricityPriceHistory = Array.isArray(electricityPriceHistoryInitial) ? electricityPriceHistoryInitial.slice() : [];
    if (!Array.isArray(electricityPriceHistory)) electricityPriceHistory = [];
    function electricityPriceForDate(isoDate){ var chosen=null; electricityPriceHistory.forEach(function(row){if(String(row.effective_date)<=String(isoDate))chosen=row;}); if(!chosen)return null; return chosen; }
    function renderElectricityPriceHistory(){var el=document.getElementById('electricity-price-history');if(!el)return; if(!Array.isArray(electricityPriceHistory)) electricityPriceHistory=[]; if(electricityPriceHistory.length===0){el.innerHTML='<span style="color:#777">Zatím není uložená historie cen. První cena se uloží od zvoleného data.</span>';return;} el.innerHTML='<strong>Historie cen (starší období zůstávají zachována)</strong><ul style="margin:5px 0;padding-left:20px">'+electricityPriceHistory.map(function(x){var p=Number(x.day_price_per_kwh)>0&&Number(x.night_price_per_kwh)>0?'den '+Number(x.day_price_per_kwh).toLocaleString('cs-CZ')+' Kč / noc '+Number(x.night_price_per_kwh).toLocaleString('cs-CZ')+' Kč':Number(x.price_per_kwh).toLocaleString('cs-CZ')+' Kč/kWh';return '<li>Od '+String(x.effective_date)+' – '+p+'</li>';}).join('')+'</ul>'; }
    function calculateElectricityCosts() {
        var settings=ensureElectricitySettings();
        var splitMode=Number(settings.day_price_per_kwh||0)>0&&Number(settings.night_price_per_kwh||0)>0;
        var singlePrice=Math.max(0,Number(settings.price_per_kwh||0));
        var dayPrice=splitMode?Math.max(0,Number(settings.day_price_per_kwh||0)):singlePrice;
        var nightPrice=splitMode?Math.max(0,Number(settings.night_price_per_kwh||0)):0;
        var dayHours=splitMode?Math.max(0,Number(settings.day_hours||0)):24;
        var nightHours=splitMode?Math.max(0,Number(settings.night_hours||0)):0;
        var splitTotal=dayHours+nightHours;
        var dayRatio=splitTotal>0?dayHours/splitTotal:0;
        var nightRatio=splitTotal>0?nightHours/splitTotal:0;
        var extraWatts=Math.max(0,Number(settings.extra_device_watts||0));
        var weekly=[],daily=[],totalKwh=0,totalDayKwh=0,totalNightKwh=0,totalCost=0;
        function addDevice(list,name,watts,hours,category,identity){
            watts=Number(watts||0);hours=Number(hours||0);
            if(!(watts>0)||!(hours>0))return;
            var kwh=watts*hours/1000;
            list.push({name:name,watts:watts,hours:hours,kwh:kwh,category:category||'',identity:identity||name});
        }
        document.querySelectorAll('.growreport[data-week]').forEach(function(weekEl){
            var week=Number(weekEl.getAttribute('data-week')),weekKwh=0,weekDevices={};
            for(var day=1;day<=7;day++){
                var devices=[];
                var lights=(lightsData&&lightsData[String(week)]&&lightsData[String(week)][String(day)])||[];
                if(!Array.isArray(lights)) lights=[];
                lights.forEach(function(light,index){
                    var isLed=String(light.name||'')==='LED';
                    var watts=isLed?Number(light.max_watts||0):Number(light.watts||0);
                    var hours=isLed?Number(light.max_hours||0):Number(light.hours||0);
                    var lightName=(light.name||'Světlo')+(isLed?' (max.)':'')+' – světlo '+(index+1);
                    addDevice(devices,lightName,watts,hours,'Světla','light-'+(index+1));
                });
                var air=(airflowData&&airflowData[String(week)]&&airflowData[String(week)][String(day)])||{};
                (Array.isArray(air.fans)?air.fans:[]).forEach(function(item,index){var used=Number(item.used_watts);if(!(used>0)){var scale=Number(item.speed_scale||3),speed=Number(item.speed||(item.speed_scale?1:3));used=Number(item.watts||0)*speed/scale;}addDevice(devices,'Větrák '+(index+1),used,item.hours,'Vzduch','fan-'+(index+1));});
                (Array.isArray(air.exhausts)?air.exhausts:[]).forEach(function(item,index){addDevice(devices,(item.name||'Odtah')+' '+(index+1),item.watts,item.hours,'Vzduch','exhaust-'+(index+1));});
                addDevice(devices,'Další zařízení (souhrnný příkon)',extraWatts,24,'Další','extra-device');
                var dayKwh=devices.reduce(function(sum,d){return sum+d.kwh;},0);
                var dk=dayKwh*dayRatio,nk=dayKwh*nightRatio;
                var dayIso = (typeof calendarStartDate !== 'undefined' && calendarStartDate) ? addDaysToIsoDate(calendarStartDate, ((week-1)*7)+day-1) : '';
                var datedPrice = dayIso ? electricityPriceForDate(dayIso) : null;
                var effectiveSplit = datedPrice ? (Number(datedPrice.day_price_per_kwh)>0 && Number(datedPrice.night_price_per_kwh)>0) : splitMode;
                var effectiveDayPrice = datedPrice ? Number(datedPrice.day_price_per_kwh||0) : dayPrice;
                var effectiveNightPrice = datedPrice ? Number(datedPrice.night_price_per_kwh||0) : nightPrice;
                var effectiveSinglePrice = datedPrice ? Number(datedPrice.price_per_kwh||0) : singlePrice;
                var effectiveDayHours = datedPrice ? Number(datedPrice.day_hours||24) : dayHours;
                var effectiveNightHours = datedPrice ? Number(datedPrice.night_hours||0) : nightHours;
                var effectiveDenom=(effectiveDayHours+effectiveNightHours)||24;
                var effectiveDk=dayKwh*(effectiveDayHours/effectiveDenom),effectiveNk=dayKwh*(effectiveNightHours/effectiveDenom);
                var cost=effectiveSplit?((effectiveDayPrice>0&&effectiveNightPrice>0)?effectiveDk*effectiveDayPrice+effectiveNk*effectiveNightPrice:null):(effectiveSinglePrice>0?dayKwh*effectiveSinglePrice:null);
                daily.push({week:week,day:day,date:dayIso,kwh:dayKwh,dayKwh:effectiveDk,nightKwh:effectiveNk,cost:cost,devices:devices});
                devices.forEach(function(d){
                    // Každé zařízení má vlastní identitu napříč dny; při změně příkonu
                    // zůstane rozlišená i konkrétní výkonová varianta.
                    var key=d.identity+'|'+d.watts;
                    if(!weekDevices[key])weekDevices[key]={name:d.name,watts:d.watts,hours:0,kwh:0,identity:d.identity};
                    weekDevices[key].hours+=d.hours;
                    weekDevices[key].kwh+=d.kwh;
                });
                weekKwh+=dayKwh;
            }
            var weekDay=weekKwh*dayRatio,weekNight=weekKwh*nightRatio;
            var weekDailyCosts=daily.filter(function(d){return d.week===week;});
            var weekCost=weekDailyCosts.every(function(d){return d.cost!==null;})?weekDailyCosts.reduce(function(sum,d){return sum+Number(d.cost||0);},0):null;
            totalKwh+=weekKwh;totalDayKwh+=weekDay;totalNightKwh+=weekNight;if(weekCost!==null)totalCost+=weekCost;
            weekly.push({week:week,kwh:weekKwh,dayKwh:weekDay,nightKwh:weekNight,cost:weekCost,devices:Object.keys(weekDevices).map(function(k){return weekDevices[k];})});
        });
        return {weekly:weekly,daily:daily,totalKwh:totalKwh,totalDayKwh:totalDayKwh,totalNightKwh:totalNightKwh,totalCost:totalCost,dayPrice:dayPrice,nightPrice:nightPrice,dayHours:dayHours,nightHours:nightHours,hasPrices:splitMode?(dayPrice>0&&nightPrice>0):singlePrice>0,splitMode:splitMode,singlePrice:singlePrice};
    }
    function renderElectricityResults(){
        var r=calculateElectricityCosts();
        // Normalizace všech kolekcí před vykreslením – žádná část E nesmí číst .length z undefined.
        if(!r || typeof r!=='object') r={weekly:[],daily:[],totalKwh:0,totalDayKwh:0,totalNightKwh:0,totalCost:0,dayPrice:0,nightPrice:0,dayHours:24,nightHours:0,hasPrices:false,splitMode:false,singlePrice:0};
        r.weekly=Array.isArray(r.weekly)?r.weekly:[]; r.daily=Array.isArray(r.daily)?r.daily:[];
        var fmt=function(v){return Number(v||0).toLocaleString('cs-CZ',{minimumFractionDigits:2,maximumFractionDigits:2});};
        var money=function(v){return r.hasPrices&&v!==null?fmt(v)+' Kč':'—';};
        var html='<div style="padding:10px;background:#f4f6f7;border-radius:6px"><strong>Celý Growreport</strong><br>Celková spotřeba světel a vzduchu: <strong>'+fmt(r.totalKwh)+' kWh</strong>';
        if(r.hasPrices){if(r.splitMode)html+='<br>Denní tarif: '+fmt(r.totalDayKwh)+' kWh × '+fmt(r.dayPrice)+' Kč = '+fmt(r.totalDayKwh*r.dayPrice)+' Kč<br>Noční tarif: '+fmt(r.totalNightKwh)+' kWh × '+fmt(r.nightPrice)+' Kč = '+fmt(r.totalNightKwh*r.nightPrice)+' Kč';else html+='<br>Cena proudu: '+fmt(r.singlePrice)+' Kč/kWh';html+='<br><strong>Celkové náklady: '+fmt(r.totalCost)+' Kč</strong>';}
        else html+='<br><span style="color:#666">Náklady se zobrazí po zadání ceny proudu nebo po nastavení denního a nočního tarifu.</span>';
        html+='</div><h4 style="margin:14px 0 6px">Spotřeba po týdnech</h4><table class="custom-function-history"><thead><tr><th>Týden</th><th>Spotřeba světel a vzduchu</th><th>Denní kWh</th><th>Noční kWh</th><th>Náklady</th></tr></thead><tbody>';
        r.weekly.forEach(function(row){html+='<tr><td>'+row.week+'</td><td>'+fmt(row.kwh)+' kWh</td><td>'+fmt(row.dayKwh)+'</td><td>'+fmt(row.nightKwh)+'</td><td>'+money(row.cost)+'</td></tr>';});
        html+='</tbody></table><h4 style="margin:14px 0 6px">Denní spotřeba</h4>';
        // Souhrn všech zařízení napříč celým reportem – neskrývat vybavení,
        // pokud právě otevřený týden (např. týden 0) ještě nemá vlastní záznam.
        var allDevices={};
        r.weekly.forEach(function(weekRow){
            (Array.isArray(weekRow.devices)?weekRow.devices:[]).forEach(function(device){
                var key=String(device.identity||device.name)+'|'+Number(device.watts||0);
                if(!allDevices[key])allDevices[key]={name:device.name,watts:Number(device.watts||0),hours:0,kwh:0};
                allDevices[key].hours+=Number(device.hours||0);
                allDevices[key].kwh+=Number(device.kwh||0);
            });
        });
        var allDeviceRows=Object.keys(allDevices).map(function(key){return allDevices[key];});
        html+='<strong style="display:block;margin:8px 0 6px">Veškeré použité vybavení – celý Growreport</strong><table class="custom-function-history"><thead><tr><th>Zařízení</th><th>Příkon</th><th>Hodin celkem</th><th>Spotřeba celkem</th><th>Náklady</th></tr></thead><tbody>';
        if(!Array.isArray(allDeviceRows) || allDeviceRows.length===0){
            html+='<tr><td colspan="5" style="text-align:center;color:#777">V reportu zatím není zaznamenáno žádné zařízení se spotřebou.</td></tr>';
        }else{
            allDeviceRows.forEach(function(device){
                var denom=(r.dayHours+r.nightHours)||1;
                var dk=device.kwh*(r.dayHours/denom),nk=device.kwh*(r.nightHours/denom);
                var cost=r.hasPrices?(r.splitMode?dk*r.dayPrice+nk*r.nightPrice:device.kwh*r.singlePrice):null;
                html+='<tr><td>'+device.name+'</td><td>'+fmt(device.watts)+' W</td><td>'+fmt(device.hours)+' h</td><td><strong>'+fmt(device.kwh)+' kWh</strong></td><td>'+money(cost)+'</td></tr>';
            });
        }
        html+='</tbody></table>';
        r.weekly.forEach(function(w){
            html+='<details style="border:1px solid #ddd;border-radius:5px;margin:5px 0;padding:7px"><summary style="cursor:pointer;font-weight:700">Týden '+w.week+' – '+fmt(w.kwh)+' kWh</summary>';
            html+='<table class="custom-function-history"><thead><tr><th>Den</th><th>Spotřeba</th><th>Náklady</th></tr></thead><tbody>';
            (Array.isArray(r.daily)?r.daily:[]).filter(function(d){return d && d.week===w.week;}).forEach(function(d){
                html+='<tr><td>'+d.day+'. den'+(d.date?' ('+d.date+')':'')+'</td><td>'+fmt(d.kwh)+' kWh</td><td>'+money(d.cost)+'</td></tr>';
                html+='<tr><td colspan="3"><details><summary style="cursor:pointer">Vybavení použité '+d.day+'. den</summary><table class="custom-function-history"><thead><tr><th>Zařízení</th><th>Příkon</th><th>Hodin</th><th>Spotřeba</th><th>Náklady</th></tr></thead><tbody>';
                (Array.isArray(d.devices)?d.devices:[]).forEach(function(device){var denom=(r.dayHours+r.nightHours)||1;var deviceCost=r.hasPrices?device.kwh*(r.dayHours/denom)*r.dayPrice+device.kwh*(r.nightHours/denom)*r.nightPrice:null;html+='<tr><td>'+device.name+'</td><td>'+fmt(device.watts)+' W</td><td>'+fmt(device.hours)+'</td><td>'+fmt(device.kwh)+' kWh</td><td>'+money(deviceCost)+'</td></tr>';});
                html+='</tbody></table></details></td></tr>';
            });
            html+='</tbody></table><strong style="display:block;margin-top:12px">Použité vybavení za týden – spotřeba každého zařízení</strong><table class="custom-function-history"><thead><tr><th>Zařízení</th><th>Příkon</th><th>Hodin</th><th>Spotřeba za týden</th><th>Náklady</th></tr></thead><tbody>';
            if(!w || !Array.isArray(w.devices) || w.devices.length === 0){
                html+='<tr><td colspan="5" style="text-align:center;color:#777">V tomto týdnu není zaznamenáno žádné zařízení se spotřebou.</td></tr>';
            }else{
                (Array.isArray(w.devices)?w.devices:[]).forEach(function(d){
                    var denom=(r.dayHours+r.nightHours)||1;
                    var dk=d.kwh*(r.dayHours/denom),nk=d.kwh*(r.nightHours/denom);
                    var cost=r.hasPrices?(r.splitMode?dk*r.dayPrice+nk*r.nightPrice:d.kwh*r.singlePrice):null;
                    html+='<tr><td>'+d.name+'</td><td>'+fmt(d.watts)+' W</td><td>'+fmt(d.hours)+' h</td><td><strong>'+fmt(d.kwh)+' kWh</strong></td><td>'+money(cost)+'</td></tr>';
                });
            }
            html+='</tbody></table></details>';
        });
        html+='<p style="font-size:12px;color:#666">Spotřeba se počítá z příkonu a hodin uložených u jednotlivých světel, větráků a odtahů. LED používá evidovaný maximální příkon a maximální dobu svícení. Denní/noční rozdělení je poměrné podle nastavených hodin. Další souhrnný příkon se počítá 24 hodin denně.</p>';
        var target=document.getElementById('electricity-results');if(target)target.innerHTML=html;
    }
    function openElectricityDialog(){
        try{
            electricityDebug('Kliknutí E – otevírám okno.');
            var dialog=document.getElementById('electricity-dialog');
            if(!dialog){electricityDebug('CHYBA: element #electricity-dialog nebyl nalezen.');alert('Okno Elektřina E nebylo nalezeno.');return;}
            var settings=ensureElectricitySettings();
            var tariffPanel=document.getElementById('electricity-tariff-settings'),tariffButton=document.getElementById('electricity-tariff-toggle');if(tariffPanel)tariffPanel.style.display='none';if(tariffButton){tariffButton.setAttribute('aria-expanded','false');tariffButton.textContent='＋ Rozdělit na denní a noční';}
            var singlePrice=document.getElementById('electricity-single-price');if(singlePrice)singlePrice.value=String(settings.price_per_kwh||'');
            var dateCheck=document.getElementById('electricity-change-from-date'),dateInput=document.getElementById('electricity-effective-date');if(dateCheck){dateCheck.checked=false;dateCheck.onchange=function(){if(dateInput)dateInput.disabled=!dateCheck.checked;};}if(dateInput){dateInput.value=new Date().toISOString().slice(0,10);dateInput.disabled=true;}renderElectricityPriceHistory();
            var fields={ 'electricity-day-price':settings.day_price_per_kwh||'', 'electricity-night-price':settings.night_price_per_kwh||'', 'electricity-day-hours':settings.day_hours??16, 'electricity-night-hours':settings.night_hours??8, 'electricity-extra-watts':settings.extra_device_watts||0 };
            Object.keys(fields).forEach(function(id){var el=document.getElementById(id);if(el)el.value=String(fields[id]);else electricityDebug('UPOZORNĚNÍ: chybí pole '+id);});
            dialog.style.display='flex';dialog.style.setProperty('display','flex','important');dialog.style.setProperty('z-index','25000','important');dialog.setAttribute('aria-hidden','false');
            renderElectricityResults();electricityDebug('OK: dialog zobrazen.');
        }catch(error){electricityDebug('CHYBA při otevření: '+(error&&error.stack?error.stack:error));alert('Elektřina E: '+(error&&error.message?error.message:error));}
    }
    function closeElectricityDialog(){var dialog=document.getElementById('electricity-dialog');if(dialog){dialog.style.display='none';dialog.setAttribute('aria-hidden','true');} }
    function saveElectricitySettings(splitMode){
        var dayPrice=document.getElementById('electricity-day-price').value,nightPrice=document.getElementById('electricity-night-price').value;
        var dayHours=document.getElementById('electricity-day-hours').value,nightHours=document.getElementById('electricity-night-hours').value;
        var singlePrice=document.getElementById('electricity-single-price').value;
        var extra=document.getElementById('electricity-extra-watts').value,msg=document.getElementById('electricity-message');
        var dateCheck=document.getElementById('electricity-change-from-date'),dateInput=document.getElementById('electricity-effective-date');
        var payload;
        if(splitMode){
            if(dayPrice===''||nightPrice===''||!Number.isFinite(Number(dayPrice))||!Number.isFinite(Number(nightPrice))||Number(dayPrice)<=0||Number(nightPrice)<=0){if(msg)msg.textContent='Zadej cenu denního i nočního proudu (obě vyšší než 0).';return;}
            if(!Number.isFinite(Number(dayHours))||!Number.isFinite(Number(nightHours))||Number(dayHours)<0||Number(nightHours)<0||Math.abs(Number(dayHours)+Number(nightHours)-24)>0.01){if(msg)msg.textContent='Denní a noční hodiny musí dohromady tvořit přesně 24 hodin.';return;}
            payload={price_per_kwh:0,day_price_per_kwh:dayPrice,night_price_per_kwh:nightPrice,day_hours:dayHours,night_hours:nightHours,extra_device_watts:extra};
        }else{
            if(singlePrice===''||!Number.isFinite(Number(singlePrice))||Number(singlePrice)<=0){if(msg)msg.textContent='Zadej platnou cenu proudu za kWh.';return;}
            payload={price_per_kwh:singlePrice,day_price_per_kwh:0,night_price_per_kwh:0,day_hours:24,night_hours:0,extra_device_watts:extra};
        }
        payload.effective_date=(dateCheck&&dateCheck.checked&&dateInput&&dateInput.value)?dateInput.value:(new Date().toISOString().slice(0,10));
        electricityDebug('Ukládám nastavení proudu od '+payload.effective_date+'…');
        postJson('electricity_save',payload).then(function(data){
            if(!data.success)throw new Error(data.message||'Uložení se nezdařilo.');
            electricitySettings=Object.assign({price_per_kwh:0,day_price_per_kwh:0,night_price_per_kwh:0,day_hours:24,night_hours:0,extra_device_watts:0},data);ensureElectricitySettings();
            if(msg)msg.textContent=splitMode?'Denní a noční tarif byly uloženy.':'Cena proudu byla uložena.';
            electricityPriceHistory.push({effective_date:payload.effective_date,price_per_kwh:Number(data.price_per_kwh||0),day_price_per_kwh:Number(data.day_price_per_kwh||0),night_price_per_kwh:Number(data.night_price_per_kwh||0),day_hours:Number(data.day_hours||24),night_hours:Number(data.night_hours||0)});electricityPriceHistory.sort(function(a,b){return String(a.effective_date).localeCompare(String(b.effective_date));}); electricityPriceHistory=electricityPriceHistory.filter(function(row,index,arr){return index===arr.findIndex(function(x){return x.effective_date===row.effective_date;});});
            renderElectricityPriceHistory();renderElectricityResults();electricityDebug('OK: nastavení uloženo od '+payload.effective_date+'.');
        }).catch(function(error){if(msg)msg.textContent=error.message;electricityDebug('CHYBA ukládání: '+(error&&error.stack?error.stack:error));});
    }
    window.openElectricityDialog=openElectricityDialog;window.closeElectricityDialog=closeElectricityDialog;window.saveElectricitySettings=saveElectricitySettings;window.toggleElectricityTariffs=toggleElectricityTariffs;

    function openCustomFunctionsDialog(week) {
        customFunctionCurrentWeek = Number(week);
        if (!Number.isInteger(customFunctionCurrentWeek) || customFunctionCurrentWeek < 0) customFunctionCurrentWeek = 1;
        if (!calendarStartDate) { alert('Nejprve nastav První den a počáteční datum growreportu.'); return; }
        var dialog = document.getElementById('custom-functions-dialog');
        var dateSelect = document.getElementById('custom-function-date');
        if (!dialog || !dateSelect) return;
        dateSelect.innerHTML = '';
        for (var day = 1; day <= 7; day++) {
            var iso = addDaysToIsoDate(calendarStartDate, ((customFunctionCurrentWeek - 1) * 7) + day - 1);
            var option = document.createElement('option'); option.value = String(day) + '|' + iso;
            option.textContent = formatIsoDateCz(iso) + ' — ' + calendarDayNames[(calendarDayNames.indexOf((document.getElementById('start-day-' + customFunctionCurrentWeek) || {}).value || calendarDayNames[0]) + day - 1 + 7) % 7];
            dateSelect.appendChild(option);
        }
        var message = document.getElementById('custom-functions-message');
        if (message) { message.textContent = ''; message.style.color = '#333'; }
        refreshCustomFunctionSettingsUI(); renderCustomFunctionHistory();
        dialog.style.display = 'flex';
    }

    function closeCustomFunctionsDialog() {
        var dialog = document.getElementById('custom-functions-dialog'); if (dialog) dialog.style.display = 'none';
    }
    (function(){ var dialog=document.getElementById('custom-functions-dialog'); if(dialog) dialog.addEventListener('click',function(event){ if(event.target===dialog) closeCustomFunctionsDialog(); }); })();

    function generateGrowreportWeeksFromT() {
        var growthInput = document.getElementById('custom-growth-weeks');
        var flowerInput = document.getElementById('custom-flower-weeks');
        var button = document.getElementById('custom-create-weeks-button');
        var message = document.getElementById('custom-create-weeks-message');
        var growthWeeks = Number(growthInput ? growthInput.value : NaN);
        var flowerWeeks = Number(flowerInput ? flowerInput.value : NaN);
        var totalWeeks = growthWeeks + flowerWeeks;
        if (!Number.isInteger(growthWeeks) || !Number.isInteger(flowerWeeks) || growthWeeks < 0 || flowerWeeks < 0 || growthWeeks > 52 || flowerWeeks > 52 || totalWeeks < 1 || totalWeeks > 104) {
            if (message) { message.textContent = 'Zadej celá čísla od 0 do 52; celkový počet týdnů musí být 1 až 104.'; message.style.color = '#b71c1c'; }
            return;
        }
        if (!window.confirm('Vytvořit Growreport s ' + growthWeeks + ' týdny RŮST a ' + flowerWeeks + ' týdny KVĚT (' + totalWeeks + ' týdnů celkem)? Existující denní záznamy, fotografie a další data zůstanou zachované. Fáze týdnů 1 až ' + totalWeeks + ' se nastaví podle tohoto rozpisu.')) return;
        if (button) button.disabled = true;
        if (message) { message.textContent = 'Vytvářím týdny…'; message.style.color = '#333'; }
        postJson('custom_function_weeks_generate', {growth_weeks:growthWeeks, flower_weeks:flowerWeeks}).then(function(data){
            if (!data || !data.success) throw new Error((data && data.message) || 'Týdny se nepodařilo vytvořit.');
            if (message) { message.textContent = 'Hotovo: ' + data.growth_weeks + ' týdnů RŮST a ' + data.flower_weeks + ' týdnů KVĚT. Growreport se obnoví.'; message.style.color = '#2e7d32'; }
            window.location.reload();
        }).catch(function(error){
            if (button) button.disabled = false;
            if (message) { message.textContent = 'Chyba: ' + (error && error.message ? error.message : error); message.style.color = '#b71c1c'; }
        });
    }

    function saveCustomFunctionSettings() {
        var settings = [];
        for (var slot = 1; slot <= 3; slot++) {
            settings.push({name:(document.getElementById('custom-function-name-' + slot) || {}).value || '', color:(document.getElementById('custom-function-color-' + slot) || {}).value || '#ff9100'});
        }
        var message = document.getElementById('custom-functions-message');
        if (message) { message.textContent = 'Ukládám nastavení…'; message.style.color = '#333'; }
        postJson('custom_functions_save_settings', {settings:settings}).then(function(data){
            if (!data || !data.success) throw new Error((data && data.message) || 'Nastavení se nepodařilo uložit.');
            customFunctionSettings = settings.map(function(item,index){ return {slot_number:index+1,function_name:item.name,color:item.color}; });
            refreshCustomFunctionSettingsUI(); renderCustomFunctionHistory();
            document.querySelectorAll('.growreport[data-week]').forEach(function(el){ updateDays(Number(el.getAttribute('data-week'))); });
            if (message) { message.textContent = 'Názvy a barvy funkcí byly uloženy.'; message.style.color = '#2e7d32'; }
        }).catch(function(error){ if (message) { message.textContent = 'Chyba: ' + error.message; message.style.color = '#b71c1c'; } });
    }

    function saveCustomFunctionMark() {
        var selected = (document.getElementById('custom-function-date') || {}).value || '';
        var parts = selected.split('|');
        var slot = Number((document.getElementById('custom-function-slot') || {}).value || 0);
        var message = document.getElementById('custom-functions-message');
        if (!parts[0] || !parts[1] || slot < 1 || slot > 3) { if (message) message.textContent = 'Vyber funkci a datum.'; return; }
        if (message) { message.textContent = 'Ukládám označení…'; message.style.color = '#333'; }
        postJson('custom_function_mark_save', {slot:slot,week:customFunctionCurrentWeek,day:Number(parts[0]),record_date:parts[1]}).then(function(data){
            if (!data || !data.success || !Array.isArray(data.items)) throw new Error((data && data.message) || 'Označení se nepodařilo uložit.');
            customFunctionMarks = data.items;
            document.querySelectorAll('.growreport[data-week]').forEach(function(el){ updateDays(Number(el.getAttribute('data-week'))); });
            renderCustomFunctionHistory();
            if (message) { message.textContent = 'Den byl označen a uložen.'; message.style.color = '#2e7d32'; }
        }).catch(function(error){ if (message) { message.textContent = 'Chyba: ' + error.message; message.style.color = '#b71c1c'; } });
    }

    function deleteCustomFunctionMark(id) {
        if (!id || !window.confirm('Opravdu chceš odstranit toto barevné označení?')) return;
        postJson('custom_function_mark_delete', {id:id}).then(function(data){
            if (!data || !data.success || !Array.isArray(data.items)) throw new Error((data && data.message) || 'Záznam se nepodařilo odstranit.');
            customFunctionMarks = data.items;
            document.querySelectorAll('.growreport[data-week]').forEach(function(el){ updateDays(Number(el.getAttribute('data-week'))); });
            renderCustomFunctionHistory();
        }).catch(function(error){ alert('Mazání označení selhalo: ' + error.message); });
    }

    (function loadCustomFunctionData(){
        postJson('custom_functions_get', {}).then(function(data){
            if (!data || !data.success) return;
            customFunctionSettings = Array.isArray(data.settings) ? data.settings : [];
            customFunctionMarks = Array.isArray(data.items) ? data.items : [];
            document.querySelectorAll('.growreport[data-week]').forEach(function(el){ updateDays(Number(el.getAttribute('data-week'))); });
        }).catch(function(error){ console.warn('Vlastní funkce T se nepodařilo načíst:', error); });
    })();

    var vzHoursPickerReady = false;
    function renderVzDial(hours) {
        var picker = document.getElementById('vz-hours-picker');
        if (!picker) return;
        vzDialHours = Math.max(0, Math.min(24, Math.round(Number(hours || 0) * 2) / 2));
        if (!vzHoursPickerReady) {
            // Používá stejný válcový výběr jako běžné zalévání, pouze s rozsahem 0–24 h.
            initScrollPicker(picker, 24, 0.5, vzDialHours);
            vzHoursPickerReady = true;
            picker.addEventListener('scroll', function() {
                if (picker.getVal) vzDialHours = Math.round(picker.getVal() * 2) / 2;
            }, {passive:true});
        } else {
            var items = Array.prototype.slice.call(picker.querySelectorAll('.sp-item'));
            var index = items.findIndex(function(item) { return Math.abs(Number(item.dataset.val) - vzDialHours) < 0.01; });
            if (index >= 0) {
                picker.scrollTop = index * 40;
                picker.dispatchEvent(new Event('scroll'));
            }
        }
        picker.setAttribute('aria-valuenow', String(vzDialHours));
    }

    // Přepnutí válce podle typu záznamu; zvlhčovač má výběr doby chodu.
    function toggleVzDial() {
        var typeSelect = document.getElementById('vz-type');
        var dialWrap = document.getElementById('vz-hours-picker-wrap');
        if (!typeSelect || !dialWrap) return;
        var showDial = typeSelect.value === 'zvlhcovac';
        dialWrap.classList.toggle('visible', showDial);
        dialWrap.style.display = showDial ? 'flex' : 'none';
        if (showDial) requestAnimationFrame(function() { renderVzDial(vzDialHours); });
    }

    function syncVzFieldsForDate() {
        var dateSelect = document.getElementById('vz-date');
        var typeSelect = document.getElementById('vz-type');
        if (!dateSelect || !typeSelect) return;
        var selected = String(dateSelect.value || '').split('|');
        var date = selected[1] || '';
        var row = (Array.isArray(vzRecords) ? vzRecords : []).find(function(item){ return String(item.record_date || '') === date; });
        var anchorCheckbox = document.getElementById('vz-anchor-future');
        var message = document.getElementById('vz-message');
        vzPendingAnchorDate = '';
        if (row) {
            typeSelect.value = String(row.record_type || 'vanicka');
            renderVzDial(row.runtime_hours === null || row.runtime_hours === '' ? 0 : Number(row.runtime_hours));
            if (anchorCheckbox) anchorCheckbox.checked = Number(row.anchor_future || 0) === 1;
            if (message) message.textContent = Number(row.auto_propagated || 0) === 1 ? 'Tento den byl uložen z ukotvené předlohy.' : '';
        } else {
            // Do dosud neuloženého budoucího dne pouze předvyplníme nejbližší starší ukotvenou hodnotu.
            // Záznam ani modré označení dne nevznikne, dokud uživatel neklikne na Uložit.
            var priorAnchors = (Array.isArray(vzRecords) ? vzRecords : []).filter(function(item) {
                return Number(item.anchor_future || 0) === 1 && String(item.record_date || '') < date;
            }).sort(function(a, b) { return String(b.record_date || '').localeCompare(String(a.record_date || '')); });
            var source = priorAnchors.length ? priorAnchors[0] : null;
            if (source) {
                typeSelect.value = String(source.record_type || 'vanicka');
                renderVzDial(source.runtime_hours === null || source.runtime_hours === '' ? 0 : Number(source.runtime_hours));
                vzPendingAnchorDate = String(source.record_date || '');
                if (message) { message.textContent = 'Předvyplněno z ukotvení ' + formatIsoDateCz(vzPendingAnchorDate) + '. Den se označí a uloží až po stisknutí Uložit záznam.'; message.style.color = '#315b86'; }
            } else {
                typeSelect.value = 'vanicka';
                renderVzDial(0);
                if (message) message.textContent = '';
            }
            if (anchorCheckbox) anchorCheckbox.checked = false;
        }
        toggleVzDial();
    }

    function renderVzHistory() {
        var wrap = document.getElementById('vz-history-wrap');
        if (!wrap) return;
        var rows = Array.isArray(vzRecords) ? vzRecords.slice() : [];
        if (!rows.length) { wrap.innerHTML = '<p style="color:#777">Zatím nejsou uložené žádné záznamy.</p>'; return; }
        var table = document.createElement('table'); table.className = 'vz-history';
        table.innerHTML = '<thead><tr><th>Datum</th><th>Záznam</th><th>Barva</th><th>Doba chodu</th><th>Ukotvení</th><th></th></tr></thead>';
        var tbody = document.createElement('tbody');
        rows.sort(function(a,b){ return String(b.record_date).localeCompare(String(a.record_date)); }).forEach(function(row) {
            var tr = document.createElement('tr');
            var date = document.createElement('td'); date.textContent = formatIsoDateCz(String(row.record_date || '')); tr.appendChild(date);
            var type = document.createElement('td'); type.textContent = vzRecordNames[String(row.record_type || '')] || '—'; tr.appendChild(type);
            appendGrowreportHistoryColorCell(tr, '#123d73', 'Tmavě modrá');
            var hours = document.createElement('td'); hours.textContent = String(row.record_type) === 'zvlhcovac' ? (String(row.runtime_hours || '0').replace('.', ',') + ' h') : '—'; tr.appendChild(hours);
            var anchor = document.createElement('td');
            anchor.textContent = Number(row.anchor_future || 0) === 1 ? 'Ukotveno' : (Number(row.auto_propagated || 0) === 1 ? 'Převzato' : '—');
            tr.appendChild(anchor);
            var cell = document.createElement('td'); cell.style.textAlign = 'center';
            var del = document.createElement('button'); del.type = 'button'; del.className = 'vz-history-delete'; del.textContent = '×'; del.title = 'Smazat záznam';
            del.addEventListener('click', function(){ deleteVzRecord(Number(row.id)); });
            cell.appendChild(del); tr.appendChild(cell); tbody.appendChild(tr);
        });
        table.appendChild(tbody); wrap.innerHTML = ''; wrap.appendChild(table);
    }

    function openVzDialog(week) {
        vzCurrentWeek = Number(week);
        if (!Number.isInteger(vzCurrentWeek) || vzCurrentWeek < 0) vzCurrentWeek = 1;
        var dateSelect = document.getElementById('vz-date');
        var dialog = document.getElementById('vz-dialog');
        if (!calendarStartDate) { alert('Nejprve nastav První den a počáteční datum growreportu.'); return; }
        if (!dateSelect || !dialog) return;
        dateSelect.innerHTML = '';
        for (var day = 1; day <= 7; day++) {
            var iso = addDaysToIsoDate(calendarStartDate, ((vzCurrentWeek - 1) * 7) + day - 1);
            var option = document.createElement('option'); option.value = String(day) + '|' + iso;
            option.textContent = formatIsoDateCz(iso) + ' — ' + calendarDayNames[(calendarDayNames.indexOf((document.getElementById('start-day-' + vzCurrentWeek) || {}).value || calendarDayNames[0]) + day - 1 + 7) % 7];
            dateSelect.appendChild(option);
        }
        var existing = (Array.isArray(vzRecords) ? vzRecords : []).filter(function(row){ return Number(row.week_number) === vzCurrentWeek; }).sort(function(a,b){ return Number(a.day_number) - Number(b.day_number); })[0];
        if (existing) dateSelect.value = String(existing.day_number) + '|' + String(existing.record_date);
        var message = document.getElementById('vz-message'); if (message) { message.textContent = ''; message.style.color = '#333'; }
        syncVzFieldsForDate();
        renderVzHistory();
        dialog.style.display = 'flex';
        renderVzDial(vzDialHours);
    }

    function closeVzDialog() { var dialog = document.getElementById('vz-dialog'); if (dialog) dialog.style.display = 'none'; }
    (function(){ var dialog = document.getElementById('vz-dialog'); if (dialog) dialog.addEventListener('click', function(event){ if (event.target === dialog) closeVzDialog(); }); var date = document.getElementById('vz-date'); if (date) date.addEventListener('change', syncVzFieldsForDate); var type = document.getElementById('vz-type'); if (type) type.addEventListener('change', toggleVzDial); })();

    function saveVzRecord() {
        var dateSelect = document.getElementById('vz-date');
        var typeSelect = document.getElementById('vz-type');
        var message = document.getElementById('vz-message');
        var selected = dateSelect && dateSelect.value ? dateSelect.value.split('|') : [];
        var day = Number(selected[0] || 0), date = selected[1] || '';
        var type = typeSelect ? typeSelect.value : '';
        if (!day || !date || !vzRecordNames[type]) { if (message) message.textContent = 'Vyber den a typ záznamu.'; return; }
        if (message) { message.textContent = 'Ukládám…'; message.style.color = '#333'; }
        var anchorCheckbox = document.getElementById('vz-anchor-future');
        var inheritedFromDate = vzPendingAnchorDate;
        postJson('vz_save', {week:vzCurrentWeek, day:day, record_date:date, record_type:type, runtime_hours:type === 'zvlhcovac' ? vzDialHours : null, anchor_future:!!(anchorCheckbox && anchorCheckbox.checked), source_anchor_date:inheritedFromDate || null}).then(function(data) {
            if (!data || !data.success || !Array.isArray(data.items)) throw new Error((data && data.message) || 'Záznam se nepodařilo uložit.');
            vzRecords = data.items;
            vzPendingAnchorDate = '';
            vzRecordDates = new Set(vzRecords.map(function(row){ return String(row.record_date || ''); }));
            document.querySelectorAll('.growreport[data-week]').forEach(function(el){ updateDays(Number(el.getAttribute('data-week'))); });
            renderVzHistory();
            if (message) { message.textContent = 'Uloženo: ' + vzRecordNames[type] + ' — ' + formatIsoDateCz(date) + (type === 'zvlhcovac' ? ', ' + String(vzDialHours).replace('.', ',') + ' h.' : '.') + (anchorCheckbox && anchorCheckbox.checked ? ' Nastavení je ukotveno jako předloha pro další označené dny.' : (inheritedFromDate ? ' Hodnota byla převzata z ukotvení ' + formatIsoDateCz(inheritedFromDate) + '.' : '')); message.style.color = '#2e7d32'; }
        }).catch(function(error){ if (message) { message.textContent = 'Chyba: ' + error.message; message.style.color = '#b71c1c'; } });
    }

    function deleteVzRecord(id) {
        if (!id || !window.confirm('Opravdu chceš smazat tento záznam V/Z?')) return;
        postJson('vz_delete', {id:id}).then(function(data) {
            if (!data || !data.success || !Array.isArray(data.items)) throw new Error((data && data.message) || 'Záznam se nepodařilo smazat.');
            vzRecords = data.items;
            vzRecordDates = new Set(vzRecords.map(function(row){ return String(row.record_date || ''); }));
            document.querySelectorAll('.growreport[data-week]').forEach(function(el){ updateDays(Number(el.getAttribute('data-week'))); });
            renderVzHistory(); syncVzFieldsForDate();
            var message = document.getElementById('vz-message'); if (message) { message.textContent = 'Záznam byl smazán.'; message.style.color = '#2e7d32'; }
        }).catch(function(error){ alert('Mazání záznamu selhalo: ' + error.message); });
    }

    function getWeekDateOptions(select, week) {
        if (!select) return;
        if (!calendarStartDate) { alert('Nejprve nastav První den a počáteční datum growreportu.'); return false; }
        select.innerHTML = '';
        var startLabel = (document.getElementById('start-day-' + week) || {}).value || calendarDayNames[0];
        for (var day = 1; day <= 7; day++) {
            var iso = addDaysToIsoDate(calendarStartDate, ((Number(week) - 1) * 7) + day - 1);
            var option = document.createElement('option');
            option.value = String(day) + '|' + iso;
            option.textContent = formatIsoDateCz(iso) + ' — ' + calendarDayNames[(calendarDayNames.indexOf(startLabel) + day - 1 + 7) % 7];
            select.appendChild(option);
        }
        return true;
    }

    function renderSprayHistory() {
        var wrap = document.getElementById('spray-history-wrap');
        if (!wrap) return;
        wrap.innerHTML = '';
        if (!Array.isArray(sprayRecords) || !sprayRecords.length) { wrap.textContent = 'Zatím nejsou uložené žádné postřiky.'; wrap.style.color = '#777'; return; }
        wrap.style.color = '#222';
        var table = document.createElement('table'); table.className = 'spray-history-table';
        table.innerHTML = '<thead><tr><th>Datum</th><th>Postřiky a množství</th><th>Barva</th><th>Záznam vytvořen</th><th aria-label="Smazat"></th></tr></thead>';
        var tbody = document.createElement('tbody');
        sprayRecords.slice().sort(function(a,b){ return String(b.record_date).localeCompare(String(a.record_date)) || Number(b.id)-Number(a.id); }).forEach(function(row){
            var tr = document.createElement('tr');
            var date = document.createElement('td'); date.textContent = formatIsoDateCz(String(row.record_date || '')) + ' — týden ' + String(row.week_number) + ', den ' + String(row.day_number); tr.appendChild(date);
            var detail = document.createElement('td'); detail.textContent = (Array.isArray(row.sprays) ? row.sprays : []).map(function(item){ return String(item.name || 'Postřik') + ' = ' + String(item.ml || 0) + ' ml'; }).join(', '); tr.appendChild(detail);
            appendGrowreportHistoryColorCell(tr, '#efdfd3', 'Světle hnědá');
            var created = document.createElement('td'); created.textContent = String(row.created_at || ''); tr.appendChild(created);
            var deleteCell = document.createElement('td'); deleteCell.style.textAlign = 'center';
            var del = document.createElement('button'); del.type = 'button'; del.className = 'spray-history-delete'; del.textContent = '×'; del.title = 'Smazat tento záznam'; del.setAttribute('aria-label','Smazat záznam postřiku');
            del.addEventListener('click', function(){ deleteSprayRecord(Number(row.id)); });
            deleteCell.appendChild(del); tr.appendChild(deleteCell); tbody.appendChild(tr);
        });
        table.appendChild(tbody); wrap.appendChild(table);
    }

    function openSprayDialog(week) {
        sprayCurrentWeek = Number(week);
        if (!Number.isInteger(sprayCurrentWeek) || sprayCurrentWeek < 0) sprayCurrentWeek = 1;
        var dialog = document.getElementById('spray-dialog');
        var dateSelect = document.getElementById('spray-date');
        if (!dialog || !dateSelect) return;
        if (!getWeekDateOptions(dateSelect, sprayCurrentWeek)) return;
        for (var i=1; i<=2; i++) {
            var name = document.getElementById('spray-name-' + i);
            var ml = document.getElementById('spray-ml-' + i);
            if (name) name.value = '';
            if (ml) ml.value = '1';
        }
        var message = document.getElementById('spray-message'); if (message) { message.textContent=''; message.style.color='#333'; }
        renderSprayHistory(); dialog.style.display='flex';
    }
    function closeSprayDialog() { var dialog=document.getElementById('spray-dialog'); if(dialog) dialog.style.display='none'; }
    (function(){ var dialog=document.getElementById('spray-dialog'); if(dialog) dialog.addEventListener('click',function(event){ if(event.target===dialog) closeSprayDialog(); }); })();

    function saveSprayAction() {
        var select = document.getElementById('spray-date');
        var selected = select && select.value ? select.value.split('|') : [];
        var day = Number(selected[0] || 0), date = selected[1] || '';
        var sprays = [];
        for (var i=1; i<=2; i++) {
            var nameEl = document.getElementById('spray-name-' + i);
            var mlEl = document.getElementById('spray-ml-' + i);
            var name = String(nameEl ? nameEl.value : '').trim();
            if (!name) continue;
            var ml = Number(mlEl ? mlEl.value : 0);
            if (!Number.isInteger(ml) || ml < 1 || ml > 10000) { var msg2=document.getElementById('spray-message'); if(msg2){msg2.textContent='Množství každého postřiku musí být 1 až 10 000 ml.';msg2.style.color='#b71c1c';} return; }
            sprays.push({type:'custom', name:name, ml:ml});
        }
        if (!day || !date || !sprays.length) { var msg3=document.getElementById('spray-message'); if(msg3){msg3.textContent='Vyber datum a zadej název alespoň jednoho postřiku.';msg3.style.color='#b71c1c';} return; }
        var msg4=document.getElementById('spray-message'); if(msg4){msg4.textContent='Ukládám…';msg4.style.color='#333';}
        postJson('spray_save',{week:sprayCurrentWeek,day:day,record_date:date,sprays:sprays}).then(function(data){
            if(!data || !data.success || !Array.isArray(data.items)) throw new Error((data&&data.message)||'Záznam postřiku se nepodařilo uložit.');
            sprayRecords=data.items; sprayRecordDates=new Set(sprayRecords.map(function(row){return String(row.record_date||'');}));
            document.querySelectorAll('.growreport[data-week]').forEach(function(el){updateDays(Number(el.getAttribute('data-week')));});
            renderSprayHistory();
            if(msg4){msg4.textContent='Postřik uložen pro '+formatIsoDateCz(date)+'.';msg4.style.color='#2e7d32';}
        }).catch(function(error){if(msg4){msg4.textContent='Chyba při ukládání: '+error.message;msg4.style.color='#b71c1c';}});
    }
    function deleteSprayRecord(id) {
        if(!id || !confirm('Opravdu chceš smazat tento záznam postřiku?')) return;
        postJson('spray_delete',{id:id}).then(function(data){
            if(!data || !data.success) throw new Error((data&&data.message)||'Záznam se nepodařilo smazat.');
            sprayRecords=sprayRecords.filter(function(row){return Number(row.id)!==id;});
            sprayRecordDates=new Set(sprayRecords.map(function(row){return String(row.record_date||'');}));
            document.querySelectorAll('.growreport[data-week]').forEach(function(el){updateDays(Number(el.getAttribute('data-week')));});
            renderSprayHistory();
        }).catch(function(error){alert('Mazání postřiku selhalo: '+error.message);});
    }
    (function loadSprayRecords(){
        postJson('spray_list',{}).then(function(data){
            if(!data||!data.success||!Array.isArray(data.items)) return;
            sprayRecords=data.items; sprayRecordDates=new Set(sprayRecords.map(function(row){return String(row.record_date||'');}));
            document.querySelectorAll('.growreport[data-week]').forEach(function(el){updateDays(Number(el.getAttribute('data-week')));});
        }).catch(function(error){console.warn('Záznamy postřiků se nepodařilo načíst:',error);});
    })();

    function renderNetHistory() {
        var wrap=document.getElementById('net-history-wrap'); if(!wrap) return; wrap.innerHTML='';
        if(!Array.isArray(netRecords)||!netRecords.length){wrap.textContent='Zatím nejsou označené žádné dny se sítí.';wrap.style.color='#777';return;}
        wrap.style.color='#222';
        netRecords.slice().sort(function(a,b){return String(b.record_date).localeCompare(String(a.record_date));}).forEach(function(row){
            var item=document.createElement('div');item.className='net-history-item';
            var text=document.createElement('span');text.textContent=formatIsoDateCz(String(row.record_date||''))+' — týden '+String(row.week_number)+', den '+String(row.day_number);
            var color=document.createElement('span');color.textContent='Světle modrá';color.style.cssText='display:inline-block;flex:0 0 auto;padding:3px 7px;border:1px solid #90caf9;border-radius:4px;background:#d9efff;color:#263238;font-size:12px;font-weight:700;';
            var del=document.createElement('button');del.type='button';del.className='net-history-delete';del.textContent='×';del.title='Odstranit označení sítě';del.addEventListener('click',function(){deleteNetMark(Number(row.id));});
            item.appendChild(text);item.appendChild(color);item.appendChild(del);wrap.appendChild(item);
        });
    }
    function openNetDialog(week) {
        netCurrentWeek=Number(week); if(!Number.isInteger(netCurrentWeek)||netCurrentWeek<0)netCurrentWeek=1;
        var dialog=document.getElementById('net-dialog'), select=document.getElementById('net-date'); if(!dialog||!select)return;
        if (!getWeekDateOptions(select,netCurrentWeek)) return;
        var check=document.getElementById('net-enabled');if(check)check.checked=false;
        var message=document.getElementById('net-message');if(message){message.textContent='';message.style.color='#333';}
        syncNetCheckboxToDate();renderNetHistory();dialog.style.display='flex';
    }
    function closeNetDialog(){var dialog=document.getElementById('net-dialog');if(dialog)dialog.style.display='none';}
    (function(){var dialog=document.getElementById('net-dialog');if(dialog)dialog.addEventListener('click',function(event){if(event.target===dialog)closeNetDialog();});})();
    function syncNetCheckboxToDate(){var sel=document.getElementById('net-date'),check=document.getElementById('net-enabled');if(!sel||!check)return;var parts=sel.value.split('|');check.checked=netRecordDates.has(parts[1]||'');}
    (function(){var sel=document.getElementById('net-date');if(sel)sel.addEventListener('change',syncNetCheckboxToDate);})();
    function saveNetMark(){
        var select=document.getElementById('net-date'),parts=select&&select.value?select.value.split('|'):[],day=Number(parts[0]||0),date=parts[1]||'',check=document.getElementById('net-enabled'),marked=!!(check&&check.checked),msg=document.getElementById('net-message');
        if(!day||!date){if(msg){msg.textContent='Vyber datum.';msg.style.color='#b71c1c';}return;}
        if(msg){msg.textContent='Ukládám…';msg.style.color='#333';}
        postJson('net_save',{week:netCurrentWeek,day:day,record_date:date,marked:marked}).then(function(data){
            if(!data||!data.success||!Array.isArray(data.items))throw new Error((data&&data.message)||'Označení sítě se nepodařilo uložit.');
            netRecords=data.items;netRecordDates=new Set(netRecords.map(function(row){return String(row.record_date||'');}));
            document.querySelectorAll('.growreport[data-week]').forEach(function(el){updateDays(Number(el.getAttribute('data-week')));});
            renderNetHistory();if(msg){msg.textContent=marked?'Síť označena pro '+formatIsoDateCz(date)+'.':'Označení sítě bylo odstraněno.';msg.style.color='#2e7d32';}
        }).catch(function(error){if(msg){msg.textContent='Chyba: '+error.message;msg.style.color='#b71c1c';}});
    }
    function deleteNetMark(id){
        if(!id||!confirm('Opravdu chceš odstranit označení sítě?'))return;
        postJson('net_delete',{id:id}).then(function(data){
            if(!data||!data.success)throw new Error((data&&data.message)||'Označení sítě se nepodařilo odstranit.');
            netRecords=netRecords.filter(function(row){return Number(row.id)!==id;});netRecordDates=new Set(netRecords.map(function(row){return String(row.record_date||'');}));
            document.querySelectorAll('.growreport[data-week]').forEach(function(el){updateDays(Number(el.getAttribute('data-week')));});renderNetHistory();syncNetCheckboxToDate();
        }).catch(function(error){alert('Označení sítě se nepodařilo odstranit: '+error.message);});
    }
    (function loadNetRecords(){postJson('net_list',{}).then(function(data){if(!data||!data.success||!Array.isArray(data.items))return;netRecords=data.items;netRecordDates=new Set(netRecords.map(function(row){return String(row.record_date||'');}));document.querySelectorAll('.growreport[data-week]').forEach(function(el){updateDays(Number(el.getAttribute('data-week')));});}).catch(function(error){console.warn('Označení sítě se nepodařilo načíst:',error);});})();

    function renderSavedWateringRows(week, day) {
    var list = document.getElementById('watering-saved-list');
    if (!list) return;

    var selectedWeek = Number(week);
    var selectedDay = Number(day);
    if (!Number.isFinite(selectedWeek)) selectedWeek = 0;
    if (!Number.isFinite(selectedDay)) selectedDay = 7;
    selectedDay = Math.max(1, Math.min(7, Math.floor(selectedDay)));

    var weekDataForSelected = wateringData[String(selectedWeek)] || wateringData[selectedWeek] || {};
    var dayObj = weekDataForSelected[String(selectedDay)] || weekDataForSelected[selectedDay] || {};
    var pots = Array.isArray(potsConfig) ? potsConfig.slice().sort(function(a,b){ return Number(a.pot_number)-Number(b.pot_number); }) : [];

    // Součet je vypočítán výhradně od týdne 0/1 do právě otevřeného týdne.
    // Předchozí týdny zahrnou všech 7 dní; z aktuálního týdne jen dny až po vybraný den.
    // Budoucí týdny a pozdější dny aktuálního týdne se nikdy nezapočítají.
    var cumulativeTotals = Object.create(null);
    var availableWeeks = Object.keys(wateringData || {})
        .map(function(key) { return Number(key); })
        .filter(function(number) { return Number.isFinite(number) && number <= selectedWeek; })
        .sort(function(a,b) { return a-b; });

    availableWeeks.forEach(function(weekNumber) {
        var weekData = wateringData[String(weekNumber)] || wateringData[weekNumber] || {};
        var lastDay = weekNumber === selectedWeek ? selectedDay : 7;
        for (var dayNumber = 1; dayNumber <= lastDay; dayNumber++) {
            var savedAmounts = weekData[String(dayNumber)] || weekData[dayNumber] || {};
            Object.keys(savedAmounts).forEach(function(potKey) {
                var amount = Number(savedAmounts[potKey]);
                if (!Number.isFinite(amount) || amount <= 0) return;
                cumulativeTotals[String(potKey)] = (cumulativeTotals[String(potKey)] || 0) + amount;
            });
        }
    });

    function formatLiters(value) {
        var n = Math.round(Number(value || 0) * 100) / 100;
        return (n % 1 === 0) ? String(n.toFixed(0)) : String(n).replace('.', ',');
    }

    if (pots.length === 0) {
        list.innerHTML = '<span style="color:#777;">Zatím není nastaven žádný květináč.</span>';
        return;
    }

    var grandTotal = 0;
    var html = '';
    pots.forEach(function(pot) {
        var k = String(pot.pot_number);
        var currentAmount = Number(dayObj[k] || 0);
        var cumulativeAmount = Math.round(Number(cumulativeTotals[k] || 0) * 100) / 100;
        grandTotal += cumulativeAmount;
        html += '<div>' + getPotDisplayName(Number(k)) + ': <strong>' + (currentAmount > 0 ? formatLiters(currentAmount) + ' L' : '—') + '</strong>' +
            ' <span style="color:#2e7d32;font-weight:600;">· Zalito celkem: ' + formatLiters(cumulativeAmount) + ' L</span></div>';
    });
    grandTotal = Math.round(grandTotal * 100) / 100;
    html += '<div style="margin-top:8px;padding-top:7px;border-top:1px solid #ccd8e6;font-weight:800;color:#1565C0;">Celkem celkem: ' + formatLiters(grandTotal) + ' L</div>';
    var runoffPhValue = (wateringRunoffPHData[String(selectedWeek)] || {})[String(selectedDay)];
    var runoffLitersValue = (wateringRunoffLitersData[String(selectedWeek)] || {})[String(selectedDay)];
    if (runoffPhValue !== undefined || runoffLitersValue !== undefined) {
        html += '<div style="margin-top:6px;color:#6a1b9a;font-weight:700;">Výtok: ' + (runoffPhValue !== undefined ? 'pH ' + String(runoffPhValue).replace('.', ',') : '') + (runoffLitersValue !== undefined ? (runoffPhValue !== undefined ? ' · ' : '') + formatLiters(runoffLitersValue) + ' L' : '') + '</div>';
    }
    html += '<div style="margin-top:5px;color:#667788;font-size:11px;">Součet do týdne ' + selectedWeek + ', dne ' + selectedDay + ' (bez budoucích záznamů).</div>';
    list.innerHTML = html;
}
</script>
<script>
// Nezávislé otevření okna E přes delegovaný klik – funguje pro tlačítko v každém týdnu.
document.addEventListener('click', function(event) {
    var button = event.target && event.target.closest ? event.target.closest('button[aria-label="Elektřina E"]') : null;
    if (!button) return;
    event.preventDefault();event.stopPropagation();
    try {
        if (typeof window.openElectricityDialog === 'function') window.openElectricityDialog();
        else {
            var dialog=document.getElementById('electricity-dialog');
            if(dialog){dialog.style.setProperty('display','flex','important');dialog.style.setProperty('z-index','25000','important');var dbg=document.getElementById('electricity-debug');if(dbg)dbg.textContent='DEBUG E: náhradní otevření – hlavní funkce ještě není dostupná.';}
            else {console.error('Growreport E: dialog nebyl nalezen.');alert('Elektřina E: okno nebylo nalezeno.');}
        }
    } catch(error) { console.error('Growreport E click:',error);var dbg=document.getElementById('electricity-debug');if(dbg)dbg.textContent='DEBUG E – chyba kliknutí: '+(error.stack||error.message); }
}, true);
</script>

<style>
.day-box .fert-total{position:absolute;z-index:4;bottom:1px;right:2px;display:flex;flex-direction:column;align-items:flex-end;gap:0;font-size:8px;line-height:9px;background:rgba(255,255,255,.94);border-radius:3px;padding:1px 3px;color:#5d321d;font-weight:700;pointer-events:none;white-space:nowrap}
.day-box .fert-total span{display:block;color:#5d321d;font-size:8px;line-height:9px}
.growreport-content button.hnojeno-btn{flex-direction:column;gap:0;line-height:1.05}.growreport-content button.hnojeno-btn .hnoj-melasa-total{white-space:nowrap!important;display:inline-flex!important;min-width:max-content!important;}
.growreport-content button.hnojeno-btn .hnoj-total{font-size:11px;line-height:12px}
.growreport-content button.hnojeno-btn .hnoj-total.small{font-size:9px}
.growreport-content button.hnojeno-btn .hnoj-dry-total{font-size:9px;line-height:10px;color:#2e7d32;font-weight:700}
.fert-modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.58);z-index:10000;display:none;align-items:center;justify-content:center;padding:14px}
.fert-modal{background:#fff;color:#222;border-radius:12px;padding:20px;max-width:760px;width:100%;max-height:90vh;overflow:auto;box-shadow:0 10px 40px #0005}
.fert-modal h2{margin:0 0 14px}.fert-modal h3{margin:10px 0}.fert-cols{display:grid;grid-template-columns:1fr 1fr;gap:16px}.fert-list{display:grid;gap:7px}.fert-list label{display:flex;align-items:center;gap:8px;margin:0;font-size:14px}.fert-list input[type=checkbox]{width:18px;height:18px}.fert-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:18px;flex-wrap:wrap}.fert-actions button{padding:9px 16px;cursor:pointer}.fert-amount-row{display:grid;grid-template-columns:1fr 100px;gap:10px;align-items:center;margin:8px 0}.fert-amount-row input{width:100%;padding:7px}.fert-name-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.fert-name-grid input{width:100%;padding:7px;box-sizing:border-box}.fert-hint{font-size:13px;color:#666}.fert-mode-options{display:flex;flex-wrap:wrap;gap:12px;margin:14px 0}.fert-mode-btn{padding:12px 18px;margin:0;cursor:pointer;border:1px solid #8d4b24;border-radius:7px;background:#f3e5dc;color:#5d321d;font-weight:700}.fert-mode-btn:hover{background:#e7c9b5}
.fert-modal-clear-row{display:flex;justify-content:flex-start;margin-top:12px}.fert-clear-modal-btn{padding:8px 12px;margin:0;cursor:pointer;background:#ffebee;color:#b71c1c;border:1px solid #ef9a9a;border-radius:6px;font-weight:600}.fert-clear-modal-btn:hover{background:#ffcdd2}
.hnojeno-btn .hnoj-melasa-total{display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;font-size:9px;line-height:1;font-weight:800;white-space:nowrap;overflow-wrap:normal;word-break:keep-all;color:inherit;padding:1px 2px;}
.fert-history-totals-panel{margin:14px 0 8px;padding:10px 12px;border:1px solid #d7e0e5;border-radius:8px;background:#f8fafb}.fert-history-totals-panel h3{margin:0 0 8px;font-size:15px;color:#263238}.fert-history-totals-wrap{max-height:220px;overflow:auto}.fert-history-totals{width:100%;border-collapse:collapse;font-size:13px;background:#fff}.fert-history-totals th,.fert-history-totals td{border:1px solid #dce3e7;padding:6px 8px;text-align:left}.fert-history-totals th{position:sticky;top:0;background:#e8eef2;z-index:1}.fert-history-totals td:last-child,.fert-history-totals th:last-child{text-align:right;white-space:nowrap}.fert-history-totals-empty{margin:0;color:#667781;font-size:13px}
.fert-auto-mode-btn{background:#e5f4e8!important;border-color:#74aa7b!important;color:#225b2d!important}
.auto-fert-top-grid{display:grid;grid-template-columns:minmax(180px,240px) 1fr;gap:12px;align-items:stretch;margin:10px 0}
.auto-fert-field{display:flex;flex-direction:column;gap:6px;font-size:13px;font-weight:700}
.auto-fert-field select,.auto-fert-week-table select{width:100%;box-sizing:border-box;padding:7px;border:1px solid #b8c5ce;border-radius:5px;background:#fff;font:inherit}
.auto-fert-info{border:1px solid #d7e0e5;border-radius:6px;background:#f6f9fa;padding:9px 11px;font-size:12px;color:#52636d;display:flex;align-items:center}
.auto-fert-recipe-wrap,.auto-fert-week-wrap,.auto-fert-preview{max-height:250px;overflow:auto;border:1px solid #dce3e7;border-radius:7px;margin:8px 0 14px;background:#fff}
.auto-fert-recipe-table,.auto-fert-week-table{width:100%;border-collapse:collapse;font-size:13px;background:#fff}
.auto-fert-recipe-table th,.auto-fert-recipe-table td,.auto-fert-week-table th,.auto-fert-week-table td{border:1px solid #e0e6e9;padding:6px 8px;text-align:left;vertical-align:middle}
.auto-fert-recipe-table th,.auto-fert-week-table th{position:sticky;top:0;background:#e8eef2;z-index:1}
.auto-fert-recipe-table td:first-child{text-align:center;width:48px}
.auto-fert-recipe-table input[type=checkbox]{width:17px;height:17px}
.auto-fert-base{width:78px;box-sizing:border-box;padding:5px;border:1px solid #b8c5ce;border-radius:4px}
.auto-fert-base:disabled{background:#f2f4f5;color:#888}
.auto-fert-unit{white-space:nowrap;font-size:12px;color:#667781}
.auto-fert-week-table td:nth-child(1){white-space:nowrap;width:110px}
.auto-fert-preview{padding:8px;box-sizing:border-box}
.auto-fert-preview .auto-fert-week-table{font-size:12px}
.auto-fert-warning-row{background:#fff3e0;color:#8b4513}
.fert-error{color:#b71c1c;background:#ffebee;border:1px solid #ef9a9a;border-radius:6px;padding:8px 10px;font-size:13px}
@media(max-width:600px){.auto-fert-top-grid{grid-template-columns:1fr}.auto-fert-recipe-table th,.auto-fert-recipe-table td,.auto-fert-week-table th,.auto-fert-week-table td{padding:5px;font-size:12px}.auto-fert-recipe-wrap{max-height:220px}}

@media(max-width:600px){.fert-cols,.fert-name-grid{grid-template-columns:1fr}.fert-modal{padding:14px}.fert-amount-row{grid-template-columns:1fr 82px}}
/* Zvětšení uložené fotografie: ovládání je nad polem pro nahrávání, aby šlo pohodlně kliknout. */
.growreport-content button.photo-zoom-btn { position:absolute !important; top:6px !important; right:6px !important; z-index:100 !important; display:inline-flex; align-items:center !important; justify-content:center !important; width:32px !important; min-width:32px !important; height:32px !important; min-height:32px !important; padding:0 !important; margin:0 !important; border:1px solid rgba(255,255,255,.75) !important; border-radius:5px !important; background:rgba(0,0,0,.78) !important; color:#fff !important; box-shadow:0 1px 5px rgba(0,0,0,.35); font-size:21px !important; line-height:1 !important; cursor:pointer !important; }
.growreport-content button.photo-zoom-btn:hover { background:rgba(0,0,0,.95) !important; }
.growreport-content input[type=file] { z-index:2; }
.photo-zoom-modal { position:fixed; inset:0; z-index:30000; display:none; flex-direction:column; padding:12px; box-sizing:border-box; background:rgba(0,0,0,.94); color:#fff; }
.photo-zoom-toolbar { display:flex; flex:0 0 auto; align-items:center; justify-content:space-between; gap:12px; padding:4px 4px 12px; font-family:Montserrat,sans-serif; }
.photo-zoom-title { min-width:0; font-size:15px; font-weight:700; }
.photo-zoom-dimensions { display:block; margin-top:3px; color:#ddd; font-size:12px; font-weight:400; }
.photo-zoom-actions { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.photo-zoom-actions a, .photo-zoom-actions button { display:inline-flex; align-items:center; justify-content:center; min-height:34px; padding:7px 10px; border:1px solid #777; border-radius:5px; background:#222; color:#fff; font:600 13px Montserrat,sans-serif; text-decoration:none; cursor:pointer; }
.photo-zoom-actions button { width:36px; font-size:24px; line-height:1; }
.photo-zoom-scroll { flex:1 1 auto; min-height:0; overflow:auto; overscroll-behavior:contain; background:#111; border-radius:5px; }
.photo-zoom-scroll img { display:block; width:auto !important; height:auto !important; max-width:none !important; max-height:none !important; object-fit:initial !important; margin:0; }
.photo-zoom-nav { position:absolute; top:50%; transform:translateY(-50%); z-index:3; display:inline-flex; align-items:center; justify-content:center; width:48px; height:64px; padding:0; border:1px solid rgba(255,255,255,.45); border-radius:8px; background:rgba(0,0,0,.68); color:#fff; font-size:48px; line-height:1; cursor:pointer; user-select:none; }
.photo-zoom-nav:hover { background:rgba(0,0,0,.9); }
.photo-zoom-nav-prev { left:16px; }
.photo-zoom-nav-next { right:16px; }
@media(max-width:600px) { .photo-zoom-modal{padding:7px}.photo-zoom-toolbar{align-items:flex-start;padding-bottom:8px}.photo-zoom-title{font-size:13px}.photo-zoom-actions{gap:5px}.photo-zoom-actions a{font-size:11px;padding:6px}.photo-zoom-actions button{min-height:32px;width:32px}.photo-zoom-nav{width:36px;height:52px;font-size:38px}.photo-zoom-nav-prev{left:7px}.photo-zoom-nav-next{right:7px} }

/* Denní plán má vlastní kompaktní sloupec hned vedle poznámky. Ostatní části rozložení zůstávají v původním pořadí. */
.growreport-content {
  grid-template-columns: repeat(7, minmax(0, 1fr)) !important;
  grid-template-rows: minmax(0, 1fr);
  min-height: 0;
  align-items: stretch;
}
.growreport-content > .week-notes-cell {
  min-width: 0 !important;
  min-height: 0 !important;
  overflow: hidden !important;
}
.growreport-content > .daily-tasks-section {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  justify-content: flex-start;
  gap: 4px;
  min-width: 0;
  min-height: 0;
  width: 100%;
  height: 100%;
  max-height: 100%;
  margin: 0;
  padding: 5px;
  box-sizing: border-box;
  overflow-x: hidden !important;
  overflow-y: auto !important;
  border: 1px solid #b6d3eb;
  border-radius: 5px;
  background: #f7fbff;
}
.growreport-content > .daily-tasks-section .daily-tasks-grid {
  display: grid !important;
  grid-template-columns: minmax(0, 1fr) !important;
  gap: 4px;
  width: 100%;
  min-width: 0;
}
.growreport-content > .daily-tasks-section .daily-task-card {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  justify-content: flex-start;
  gap: 3px;
  width: 100%;
  min-width: 0;
  padding: 4px;
  box-sizing: border-box;
  overflow: hidden;
  border-radius: 4px;
}
.growreport-content > .daily-tasks-section .daily-task-card .daily-task-text {
  display: block;
  width: 100%;
  height: auto;
  min-height: 42px;
  max-height: 105px;
  padding: 4px;
  box-sizing: border-box;
  overflow-x: hidden;
  overflow-y: scroll !important;
  scrollbar-gutter: stable;
  border-radius: 3px;
  font: 10px/1.2 Arial, sans-serif;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.growreport-content > .daily-tasks-section .daily-task-card .daily-task-day { font-weight: 800; }
.growreport-content > .daily-tasks-section .daily-task-complete-btn {
  width: 100%;
  min-height: 24px;
  padding: 3px 4px;
  margin: 0;
  box-sizing: border-box;
  border-radius: 4px;
  font-size: 10px;
  line-height: 1.2;
}


/* Sedm stejně širokých hlavních sekcí. Growreport je mírně širší a vyšší,
   aby se poslední sloupec a jeho údaje lépe vešly. */
#growreport-container { width: 100%; max-width: 100%; box-sizing: border-box; overflow-x: auto; overflow-y: visible; }
.growreport { width: 44cm !important; min-width: 44cm !important; height: 11cm !important; box-sizing: border-box; }
.growreport-content { grid-template-columns: repeat(7, minmax(0, 1fr)) !important; }

/* Textové pole Poznámka vyplní dostupnou výšku sloupce odshora dolů. */
.growreport-content > .week-notes-cell {
  display: flex !important;
  flex-direction: column !important;
  align-items: stretch !important;
  justify-content: flex-start !important;
  height: 100% !important;
  min-height: 0 !important;
  box-sizing: border-box !important;
}
.growreport-content > .week-notes-cell > textarea[id^="weekText-"] {
  display: block !important;
  width: 100% !important;
  height: auto !important;
  min-height: 0 !important;
  max-height: none !important;
  flex: 1 1 auto !important;
  margin: 0 !important;
  box-sizing: border-box !important;
}



/* Oprava posuvníku celého seznamu denních úkolů:
   kontejner zůstává omezený na výšku své sekce Growreportu a posouvá se jako celek. */
.growreport-content > section.daily-tasks-section[id^="daily-tasks-section-"] {
  display: flex !important;
  flex: 0 1 auto !important;
  align-self: stretch !important;
  min-width: 0 !important;
  min-height: 0 !important;
  height: 100% !important;
  max-height: 100% !important;
  box-sizing: border-box !important;
  overflow-x: hidden !important;
  overflow-y: scroll !important;
  scrollbar-gutter: stable both-edges;
  overscroll-behavior-y: contain;
}
.growreport-content > section.daily-tasks-section[id^="daily-tasks-section-"] > .daily-tasks-grid {
  display: grid !important;
  grid-template-columns: minmax(0, 1fr) !important;
  grid-auto-rows: max-content !important;
  align-content: start !important;
  align-items: start !important;
  flex: 0 0 auto !important;
  min-width: 0 !important;
  min-height: 0 !important;
  width: 100% !important;
  overflow: visible !important;
}
.growreport-content > section.daily-tasks-section[id^="daily-tasks-section-"] > .daily-tasks-grid > .daily-task-card {
  flex: 0 0 auto !important;
  min-height: 0 !important;
  max-width: 100% !important;
}


/* Denní záznamy se zobrazují celé bez vlastních posuvníků.
   Svislý posuvník zůstává pouze ve společném okně denního plánu. */
.growreport-content > section.daily-tasks-section[id^="daily-tasks-section-"] .daily-task-card .daily-task-text,
.growreport-content > .week-notes-cell .daily-tasks-section .daily-task-card .daily-task-text {
  display: block !important;
  height: auto !important;
  min-height: 0 !important;
  max-height: none !important;
  overflow: visible !important;
  overflow-x: visible !important;
  overflow-y: visible !important;
  scrollbar-gutter: auto !important;
  white-space: pre-wrap !important;
  overflow-wrap: anywhere !important;
  flex: 0 0 auto !important;
}
.growreport-content > section.daily-tasks-section[id^="daily-tasks-section-"] .daily-task-card,
.growreport-content > .week-notes-cell .daily-tasks-section .daily-task-card {
  height: auto !important;
  max-height: none !important;
  overflow: visible !important;
  align-self: stretch !important;
}
/* Samostatné poznámky úkonů jsou plnohodnotné sourozenecké karty vedle zalití/hnojiv. */
.daily-action-task-host { display:contents !important; }
.daily-action-note-card { min-width:0; }
.daily-action-note-card .daily-task-text { max-height:none !important; overflow:visible !important; }
.daily-action-note-card .daily-task-entry-action { margin-top:0; }
.daily-action-note-card.completed .daily-task-entry-action { border-color:#81c784; background:#f4fbf4; color:#1b5e20; }

.growreport-content > section.daily-tasks-section[id^="daily-tasks-section-"] > .daily-tasks-grid,
.growreport-content > .week-notes-cell .daily-tasks-section .daily-tasks-grid {
  height: auto !important;
  max-height: none !important;
  overflow: visible !important;
  align-content: start !important;
}

/* Každý nový úkon je vlastní poznámka s vlastním tlačítkem Splněno. */
.daily-action-task,
.daily-action-task.completed {
  display: flex !important;
  flex-direction: column !important;
  gap: 5px !important;
  background: #fff !important;
  border: 1px solid #64a6df !important;
  border-radius: 5px !important;
  padding: 7px !important;
}
.daily-action-task.completed { background: #f4fbf4 !important; border-color: #81c784 !important; }
.daily-action-task-note { display:block !important; width:100% !important; color:#123b5b !important; font:700 10px/1.3 Arial,sans-serif !important; white-space:pre-wrap !important; overflow-wrap:anywhere !important; }
.daily-action-task-button { width:100% !important; min-height:36px !important; }

/* Náhled denního záznamu při najetí myší: zvětší vždy jen vybraný den mimo původní rozložení. */
.daily-task-hover-preview {
  display: none;
  position: fixed !important;
  z-index: 50000 !important;
  width: min(420px, calc(100vw - 24px));
  max-width: calc(100vw - 24px);
  max-height: min(72vh, 560px);
  overflow: auto !important;
  box-sizing: border-box;
  padding: 10px;
  border: 2px solid #1565c0;
  border-radius: 9px;
  background: #e3f2fd;
  color: #123b5b;
  box-shadow: 0 8px 28px rgba(0,0,0,.32);
}
.daily-task-hover-preview.completed {
  border-color: #66bb6a;
  background: #e8f5e9;
  color: #1b5e20;
}
.daily-task-hover-preview .daily-task-card {
  display: flex !important;
  width: 100% !important;
  min-width: 0 !important;
  max-height: none !important;
  height: auto !important;
  overflow: visible !important;
  margin: 0 !important;
  padding: 4px !important;
  border: 0 !important;
  border-radius: 0 !important;
  box-shadow: none !important;
  background: transparent !important;
  transform: none !important;
}
.daily-task-hover-preview .daily-task-text {
  display: block !important;
  width: 100% !important;
  min-height: 0 !important;
  max-height: none !important;
  height: auto !important;
  overflow: visible !important;
  padding: 6px !important;
  box-sizing: border-box !important;
  border-radius: 5px !important;
  font: 18px/1.5 Arial, sans-serif !important;
  font-size: 18px !important;
  white-space: pre-wrap !important;
  overflow-wrap: anywhere !important;
  scrollbar-gutter: auto !important;
}
.daily-task-hover-preview .daily-task-text,
.daily-task-hover-preview .daily-task-text * {
  font-size: 18px !important;
  line-height: 1.5 !important;
}
.daily-task-hover-preview .daily-task-day { font-size: 18px !important; font-weight: 800 !important; }
.daily-task-hover-preview .daily-task-complete-btn {
  width: 100% !important;
  min-height: 38px !important;
  margin-top: 8px !important;
  padding: 8px 10px !important;
  font-size: 13px !important;
}


/* Veřejný náhled: editační ovladače zůstávají viditelné, ale jsou zřetelně neaktivní. */
body.growreport-public-view button:disabled,
body.growreport-public-view input:disabled,
body.growreport-public-view .growreport-header-actions button:disabled {
    filter: grayscale(1) !important;
    opacity: 0.48 !important;
    cursor: not-allowed !important;
    box-shadow: none !important;
}
body.growreport-public-view button:disabled:hover {
    transform: none !important;
    box-shadow: none !important;
}
</style>
<div class="fert-modal-backdrop" id="fert-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="fert-modal-title">
 <div class="fert-modal"><h2 id="fert-modal-title">Hnojení dne</h2><div id="fert-modal-content"></div><div class="fert-actions" id="fert-modal-actions"></div></div>
</div>
<script>
(function(){
 const presetLiquid=['Melasový čaj','Moč','Kořenový Stimulátor','Banán tekutý','Banán tuhý','Kopřiva-Řasa','Ptačí trus','Kukuřice enzym','Pivní enzym','Voda z bongu','Pivní kvasnice','Kvasnice droždí','Skořápky hnojivo','Pivní kal','Olivový olej','Kokosový cukr'];
 const presetDry=['Green Crack','Popel','Vápník-Křemík','Popel z trávy','Micorize'];
 let current=null, custom={liquid:Array(20).fill(''),dry:Array(10).fill('')}, chosen=[];
 const backdrop=document.getElementById('fert-modal-backdrop'), content=document.getElementById('fert-modal-content'), actions=document.getElementById('fert-modal-actions');
 function show(){backdrop.style.display='flex'} function hide(){backdrop.style.display='none'}
 function btn(label,fn){const b=document.createElement('button');b.type='button';b.textContent=label;b.onclick=fn;actions.appendChild(b)}
 function setTitle(s){document.getElementById('fert-modal-title').textContent=s}
 function reset(){content.innerHTML='';actions.innerHTML=''}
 function scoopLabel(n){return n===1?'1 odměrka':(n>=2&&n<=4?n+' odměrky':n+' odměrek')}
 function kindForItem(item, mode){
   const name=String(item.name||'');
   if(item.kind==='dry'||Object.prototype.hasOwnProperty.call(item,'scoops')) return 'dry';
   if(item.kind==='liquid') return 'liquid';
   const dryList=mode==='preset'?presetDry:custom.dry.filter(x=>String(x||'').trim());
   return dryList.includes(name)?'dry':'liquid';
 }
 window.openFertilizer=function(week,day){openMode(week,day);};
 window.openAutoFertilizerForWeek=function(week){
   current={week:Number(week)||1,day:1};
   openAutoFertilizer();
 };
 window.handleFertilizerCheckbox=function(week,day,checkbox){if(checkbox.checked){checkbox.checked=false;openMode(week,day);}else{openMode(week,day);}};
 function openMode(week,day){
   current={week:Number(week),day:Number(day)};reset();setTitle('Hnojení – týden '+current.week+', den '+current.day);
   content.innerHTML='<p>Vyber, jaká hnojiva chceš použít, nebo nastav automatický plán:</p><div class="fert-mode-options"><button type="button" class="fert-mode-btn" id="fert-mode-preset">A – Předvolená hnojiva</button><button type="button" class="fert-mode-btn" id="fert-mode-custom">B – Vlastní hnojiva</button><button type="button" class="fert-mode-btn fert-auto-mode-btn" id="fert-auto-mode">🧪 Automatické hnojení</button></div><p class="fert-hint">Tekutá hnojiva se zadávají v ml (1–500 ml). Suchá hnojiva se zadávají v odměrkách po 50 ml (1–40 odměrek pro každé hnojivo).</p><section class="fert-history-totals-panel"><h3 id="fert-history-totals-title">Všechna použitá hnojiva – celkem do týdne</h3><div id="fert-history-totals-wrap" class="fert-history-totals-wrap"><p class="fert-history-totals-empty">Načítám celkovou spotřebu hnojiv…</p></div></section><div class="fert-modal-clear-row"><button type="button" class="fert-clear-modal-btn" id="fert-clear-current"><i class="fas fa-trash" aria-hidden="true"></i> Vymazat data tohoto dne</button></div>';
   document.getElementById('fert-mode-preset').onclick=()=>loadExistingAndChoose('preset');
   document.getElementById('fert-mode-custom').onclick=()=>loadExistingAndChoose('custom');
   document.getElementById('fert-auto-mode').onclick=openAutoFertilizer;
   document.getElementById('fert-clear-current').onclick=()=>window.clearFertilizerDay(current.week,current.day);
   btn('Zavřít',hide);show();
   loadFertilizerTotals(current.week, current.day);
 }
 let autoFertilizerSettings = null;
 let autoFertilizerMaxWeek = 1;
 let autoFertilizerWeekPhases = {};
 let autoFertilizerGlobalRecipes = [];
 let autoFertilizerHistoryRows = [];
 let autoFertilizerHistoryRecordCount = 0;

 function openAutoFertilizer(){
   reset();setTitle('Automatické hnojení');
   content.innerHTML='<p class="fert-hint">Načítám receptury a týdny growreportu…</p>';
   btn('Zpět na hnojení dne',()=>openMode(current.week,current.day));btn('Zavřít',hide);show();
   Promise.all([
     loadCustom(),
     postJson('auto_fertilizer_settings_get',{}),
     postJson('fertilizer_get',{week:1,day:1})
   ]).then(([_,response,baseDay])=>{
     if(!response||!response.success)throw new Error((response&&response.message)||'Nepodařilo se načíst nastavení automatického hnojení.');
     autoFertilizerMaxWeek=Math.max(1,Number(response.max_week)||1);
     autoFertilizerWeekPhases=response.week_phases&&typeof response.week_phases==='object'?response.week_phases:{};
     const saved=response.settings&&typeof response.settings==='object'?response.settings:{};
     const baseItems=baseDay&&baseDay.success&&baseDay.fertilizer&&Array.isArray(baseDay.fertilizer.items)?baseDay.fertilizer.items.map(item=>{
       const kind=kindForItem(item,baseDay.fertilizer.mode||'preset');
       return kind==='dry'?{name:String(item.name||''),kind:'dry',base_scoops:Math.max(1,Number(item.scoops)||1)}:{name:String(item.name||''),kind:'liquid',base_ml:Math.max(1,Number(item.ml)||1)};
     }):[];
     let localRecipes=Array.isArray(saved.recipes)?saved.recipes.slice(0,2):[];
     if(!localRecipes.length){
       localRecipes=[
         {name:'RŮST',items:Array.isArray(saved.growth_items)?saved.growth_items:(Array.isArray(saved.items)?saved.items:baseItems)},
         {name:'KVĚT',items:Array.isArray(saved.flower_items)?saved.flower_items:(Array.isArray(saved.items)?saved.items:baseItems.map(item=>Object.assign({},item)))}
       ];
     }
     while(localRecipes.length<2)localRecipes.push({name:localRecipes.length===0?'RŮST':'KVĚT',items:[]});
     localRecipes=localRecipes.slice(0,2).map((recipe,index)=>({name:index===0?'RŮST':'KVĚT',items:Array.isArray(recipe.items)?recipe.items:[]}));
     autoFertilizerGlobalRecipes=[];
     const settings={
       frequency:[2,3,4,5].includes(Number(saved.frequency))?Number(saved.frequency):5,
       recipes:localRecipes,
       week_recipes:saved.week_recipes&&typeof saved.week_recipes==='object'?saved.week_recipes:{},
       week_increases:saved.week_increases&&typeof saved.week_increases==='object'?saved.week_increases:{},
       week_doses:saved.week_doses&&typeof saved.week_doses==='object'?saved.week_doses:{}
     };
     for(let w=1;w<=autoFertilizerMaxWeek;w++){
       const phase=autoFertilizerWeekPhases[String(w)]||(w<=9?'growth':'flower');
       if(![1,2].includes(Number(settings.week_recipes[String(w)])))settings.week_recipes[String(w)]=phase==='flower'?2:1;
       if(settings.week_increases[String(w)]===undefined)settings.week_increases[String(w)]=w===1?0:10;
     }
     autoFertilizerSettings=settings;
     renderAutoFertilizerSettings(settings);
   }).catch(error=>{
     content.innerHTML='<p class="fert-error"></p>';
     content.querySelector('.fert-error').textContent='Automatické hnojení se nepodařilo načíst: '+(error&&error.message?error.message:'neznámá chyba');
   });
 }

 async function importAutoFertilizerHistory(button){
   const previousText=button.textContent;button.disabled=true;button.textContent='Načítám záznamy hnojiv…';
   try{
     const current=collectAutoFertilizerSettings();
     if(current&&current.settings)autoFertilizerSettings=current.settings;
     const response=await postJson('auto_fertilizer_history_import',{});
     if(!response||!response.success)throw new Error((response&&response.message)||'Nepodařilo se načíst záznamy hnojiv z Growreportu.');
     if(!Array.isArray(response.rows)||!response.rows.length)throw new Error('V Growreportu nejsou uložené žádné použitelné záznamy hnojiv pro fáze RŮST nebo KVĚT.');
     const settings=autoFertilizerSettings||{frequency:5,recipes:[{name:'RŮST',items:[]},{name:'KVĚT',items:[]}],week_recipes:{},week_increases:{},week_doses:{}};
     const imported=Array.isArray(response.recipes)?response.recipes:[];
     for(let i=0;i<2;i++){
       const incoming=imported[i]&&Array.isArray(imported[i].items)?imported[i].items:[];
       if(incoming.length)settings.recipes[i]={name:i===0?'RŮST':'KVĚT',items:incoming};
     }
     autoFertilizerHistoryRows=response.rows;
     autoFertilizerHistoryRecordCount=Number(response.record_count)||0;
     settings.week_doses={};
     autoFertilizerSettings=settings;
     renderAutoFertilizerSettings(settings);
     alert('Načteno '+autoFertilizerHistoryRecordCount+' záznamů hnojiv z Growreportu. Průměrné dávky pro RŮST a KVĚT jsou vložené do základních receptur. Zkontroluj tabulku a potom ulož změny.');
   }catch(error){
     alert('Načtení dat hnojiv se nezdařilo: '+(error&&error.message?error.message:'neznámá chyba'));
     button.disabled=false;button.textContent=previousText;
   }
 }

 function getAutoFertilizerPhase(week){
   return autoFertilizerWeekPhases[String(week)]||(week<=9?'growth':'flower');
 }
 function getAutoFertilizerRecipe(slot,settings){
   if(slot===1||slot===2)return (settings.recipes||[])[slot-1]||{name:slot===1?'RŮST':'KVĚT',items:[]};
   return {name:slot===1?'RŮST':'KVĚT',items:[]};
 }
 function getAutoFertilizerRecipeName(slot,settings){
   const recipe=getAutoFertilizerRecipe(slot,settings);
   return slot===1?'RŮST':(slot===2?'KVĚT':String(recipe.name||('Samostatná receptura '+(slot-2))));
 }
 function getAutoFertilizerScheduledDays(frequency){
   const byWeek={};
   const maxDay=autoFertilizerMaxWeek*7;
   for(let wateringIndex=0,globalDay=1;globalDay<=maxDay;wateringIndex++,globalDay+=frequency){
     if(wateringIndex%3!==0)continue;
     const week=Math.floor((globalDay-1)/7)+1;
     const day=(globalDay-1)%7+1;
     if(getAutoFertilizerPhase(week)==='dry')continue;
     if(!byWeek[String(week)])byWeek[String(week)]=[];
     byWeek[String(week)].push(day);
   }
   return byWeek;
 }
 function clearAutoFertilizerWeekOverrides(week){
   const rows=autoFertilizerSettings&&autoFertilizerSettings.week_doses?autoFertilizerSettings.week_doses:{};
   if(rows&&rows[String(week)])delete rows[String(week)];
 }

 function renderAutoFertilizerSettings(settings){
   reset();setTitle('Automatické hnojení');
   const intro=document.createElement('p');intro.className='fert-hint';
   intro.textContent='Nastav recepturu pro RŮST a recepturu pro KVĚT. První zálivka je s hnojivy, následující dvě pouze s vodou a při třetí zálivce se hnojí znovu.';
   content.appendChild(intro);

   const importRow=document.createElement('div');importRow.style.cssText='display:flex;flex-wrap:wrap;gap:8px;margin:0 0 14px;';
   const importButton=document.createElement('button');importButton.type='button';importButton.className='fert-mode-btn';
   importButton.textContent='Načíst data hnojiv z Growreportu';
   importButton.addEventListener('click',()=>importAutoFertilizerHistory(importButton));
   importRow.appendChild(importButton);content.appendChild(importRow);
   if(autoFertilizerHistoryRows.length){
     const historyHeading=document.createElement('h3');historyHeading.textContent='Přehled načtených skutečných dávek hnojiv';content.appendChild(historyHeading);
     const historyHint=document.createElement('p');historyHint.className='fert-hint';historyHint.textContent='Hodnoty jsou průměrem uložených záznamů hnojiv v Growreportu. RŮST a KVĚT jsou vyhodnoceny odděleně. Tyto hodnoty byly vloženy do základních receptur níže.';content.appendChild(historyHint);
     const historyTable=document.createElement('table');historyTable.className='auto-fert-week-table';
     const historyHead=document.createElement('thead');const historyHeadRow=document.createElement('tr');
     ['Fáze','Hnojivo','Druh','Průměrná dávka','Počet záznamů'].forEach(label=>{const th=document.createElement('th');th.textContent=label;historyHeadRow.appendChild(th);});
     historyHead.appendChild(historyHeadRow);historyTable.appendChild(historyHead);
     const historyBody=document.createElement('tbody');
     autoFertilizerHistoryRows.forEach(item=>{const row=document.createElement('tr');
       [item.phase,item.name,item.kind==='dry'?'Suché':'Tekuté',String(item.average)+' '+item.unit,String(item.count)].forEach(value=>{const cell=document.createElement('td');cell.textContent=value;row.appendChild(cell);});
       historyBody.appendChild(row);
     });
     historyTable.appendChild(historyBody);const historyWrap=document.createElement('div');historyWrap.className='auto-fert-week-wrap';historyWrap.appendChild(historyTable);content.appendChild(historyWrap);
   }


   const top=document.createElement('div');top.className='auto-fert-top-grid';
   const frequencyLabel=document.createElement('label');frequencyLabel.className='auto-fert-field';frequencyLabel.textContent='Interval mezi zálivkami';
   const frequencySelect=document.createElement('select');frequencySelect.id='auto-fert-frequency';
   [[5,'Jednou za 5 dní'],[4,'Jednou za 4 dny'],[3,'Jednou za 3 dny'],[2,'Jednou za 2 dny']].forEach(([value,label])=>{const option=document.createElement('option');option.value=String(value);option.textContent=label;frequencySelect.appendChild(option);});
   frequencySelect.value=String(settings.frequency||5);frequencyLabel.appendChild(frequencySelect);top.appendChild(frequencyLabel);
   const info=document.createElement('div');info.className='auto-fert-info';info.textContent='Po každém hnojení následují dvě zálivky pouze vodou. Interval 2–5 dní určuje rozestup mezi jednotlivými zálivkami.';top.appendChild(info);content.appendChild(top);

   const recipeHeading=document.createElement('h3');recipeHeading.textContent='1. Receptura pro RŮST';content.appendChild(recipeHeading);
   renderAutoFertilizerRecipeEditor(1,settings.recipes[0]);
   const flowerHeading=document.createElement('h3');flowerHeading.textContent='2. Receptura pro KVĚT';content.appendChild(flowerHeading);
   renderAutoFertilizerRecipeEditor(2,settings.recipes[1]);
   const libraryGrid=document.createElement('div');libraryGrid.style.cssText='display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:0 0 18px;';
   content.appendChild(libraryGrid);
   renderAutoFertilizerRecipeLibrary(1,libraryGrid);
   renderAutoFertilizerRecipeLibrary(2,libraryGrid);
   const weekHeading=document.createElement('h3');weekHeading.textContent='3. Zvolená možnost hnojení a navýšení dávky podle týdnů';content.appendChild(weekHeading);
   const weekHint=document.createElement('p');weekHint.className='fert-hint';weekHint.textContent='Vyber recepturu pro každý týden. Navýšení se nabízí pouze v týdnech, ve kterých podle rozpisu skutečně vychází hnojení; týdny Sušina se automaticky vynechají.';content.appendChild(weekHint);
   const weekWrap=document.createElement('div');weekWrap.className='auto-fert-week-wrap';
   const weekTable=document.createElement('table');weekTable.className='auto-fert-week-table';
   const weekHead=document.createElement('thead');const weekHeadRow=document.createElement('tr');
   ['Týden','Fáze','Termíny hnojení','Zvolená receptura','Navýšení dávky'].forEach(label=>{const th=document.createElement('th');th.textContent=label;weekHeadRow.appendChild(th);});
   weekHead.appendChild(weekHeadRow);weekTable.appendChild(weekHead);
   const weekBody=document.createElement('tbody');const scheduledDays=getAutoFertilizerScheduledDays(Number(settings.frequency)||5);
   for(let week=1;week<=autoFertilizerMaxWeek;week++){
     const row=document.createElement('tr');const weekCell=document.createElement('td');weekCell.textContent='Týden '+week;row.appendChild(weekCell);
     const phase=getAutoFertilizerPhase(week);const phaseCell=document.createElement('td');phaseCell.textContent=phase==='dry'?'Sušina':(phase==='flower'?'🌼 Květ':'🌿 Růst');row.appendChild(phaseCell);
     const daysCell=document.createElement('td');const days=scheduledDays[String(week)]||[];daysCell.textContent=days.length?days.map(day=>'den '+day).join(', '):(phase==='dry'?'Bez hnojení – sušina':'—');row.appendChild(daysCell);
     const recipeCell=document.createElement('td');const recipeSelect=document.createElement('select');recipeSelect.className='auto-fert-week-recipe';recipeSelect.dataset.autoWeek=String(week);
     for(let slot=1;slot<=2;slot++){
       const option=document.createElement('option');option.value=String(slot);
       const recipe=getAutoFertilizerRecipe(slot,settings);const optionName=slot===1?'RŮST':'KVĚT';
       const hasItems=Array.isArray(recipe.items)&&recipe.items.length>0;
       option.textContent=slot+'. '+optionName+(hasItems?'':' (zatím prázdná)');
       recipeSelect.appendChild(option);
     }
     recipeSelect.value=String(settings.week_recipes[String(week)]||(phase==='flower'?2:1));
     if(phase==='dry')recipeSelect.disabled=true;
     recipeCell.appendChild(recipeSelect);row.appendChild(recipeCell);
     const increaseCell=document.createElement('td');const increaseSelect=document.createElement('select');increaseSelect.className='auto-fert-week-increase';increaseSelect.dataset.autoWeek=String(week);
     if(week===1){const option=document.createElement('option');option.value='0';option.textContent='0 % – základní dávka';increaseSelect.appendChild(option);increaseSelect.value='0';increaseSelect.disabled=true;}
     else{
       [10,20,30,40,50,60,70,80,90,100,200,300,400,500,600,700,800,900,1000].forEach(value=>{const option=document.createElement('option');option.value=String(value);option.textContent='+'+value+' %';increaseSelect.appendChild(option);});
       const savedIncrease=Number(settings.week_increases[String(week)]);increaseSelect.value=String(savedIncrease>0?savedIncrease:10);
     }
     increaseCell.appendChild(increaseSelect);const state=document.createElement('span');state.className='auto-fert-increase-state';increaseCell.appendChild(state);row.appendChild(increaseCell);
     recipeSelect.addEventListener('change',()=>{clearAutoFertilizerWeekOverrides(week);updateAutoFertilizerPreview();});
     increaseSelect.addEventListener('change',()=>{clearAutoFertilizerWeekOverrides(week);updateAutoFertilizerPreview();});
     weekBody.appendChild(row);
   }
   weekTable.appendChild(weekBody);weekWrap.appendChild(weekTable);content.appendChild(weekWrap);

   const doseHeading=document.createElement('h3');doseHeading.textContent='4. Navýšení dávky podle týdnů 2 – konkrétní hnojiva a úprava množství';content.appendChild(doseHeading);
   const doseHint=document.createElement('p');doseHint.className='fert-hint';doseHint.textContent='Níže se zobrazí konkrétní hnojiva vybrané receptury a vypočítané dávky pro každý týden, ve kterém se hnojí. Množství můžeš ručně změnit. Upravená dávka platí pro všechny termíny hnojení v daném týdnu.';content.appendChild(doseHint);
   const doseWrap=document.createElement('div');doseWrap.id='auto-fert-week-dose-editor';doseWrap.className='auto-fert-week-wrap';content.appendChild(doseWrap);
   const summary=document.createElement('p');summary.id='auto-fert-plan-summary';summary.className='fert-hint';content.appendChild(summary);

   frequencySelect.addEventListener('change',()=>{
     const currentSettings=collectAutoFertilizerSettings();
     if(currentSettings&&currentSettings.settings)currentSettings.settings.week_doses={};
     if(autoFertilizerSettings)autoFertilizerSettings.week_doses={};
     updateAutoFertilizerPreview();
   });
   autoFertilizerSettings=settings;
   updateAutoFertilizerPreview();
   btn('Zpět na hnojení dne',()=>openMode(current.week,current.day));
   btn('Uložit změny',saveAutoFertilizerSettings);
   btn('Zapsat plán do Growreportu',applyAutoFertilizerPlan);
   btn('Zavřít',hide);
 }

 function renderAutoFertilizerRecipeButtons(slot){
   const row=document.createElement('div');
   row.className='auto-fert-recipe-buttons';
   row.style.cssText='display:flex;flex-wrap:wrap;gap:8px;margin:0 0 16px;';
   const label=slot===1?'RŮST':'KVĚT';
   const save=document.createElement('button');
   save.type='button';save.className='fert-mode-btn';
   save.textContent='Uložit recepturu '+slot+' '+label;
   save.addEventListener('click',()=>saveAutoFertilizerRecipe(slot));
   const load=document.createElement('button');
   load.type='button';load.className='fert-mode-btn';
   load.textContent='Načíst uloženou recepturu '+slot+' '+label;
   load.addEventListener('click',()=>loadAutoFertilizerRecipe(slot));
   row.appendChild(save);row.appendChild(load);content.appendChild(row);
 }

 async function renderAutoFertilizerRecipeLibrary(slot,parent){
   const phase=slot===1?'GROWTH':'FLOWER';
   const label=slot===1?'RŮST':'KVĚT';
   const panel=document.createElement('section');
   panel.className='auto-fert-saved-library';
   panel.style.cssText='border:1px solid #cbd5e1;border-radius:10px;padding:10px;margin:0 0 18px;background:#f8fafc;';
   const heading=document.createElement('h4');heading.textContent='Uložené receptury – '+label;heading.style.margin='0 0 8px';panel.appendChild(heading);
   const saveRow=document.createElement('div');saveRow.style.cssText='display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;';
   const nameInput=document.createElement('input');nameInput.type='text';nameInput.maxLength=80;nameInput.placeholder='Název nové receptury';nameInput.style.cssText='flex:1;min-width:180px;padding:8px;border:1px solid #cbd5e1;border-radius:6px;';
   const saveButton=document.createElement('button');saveButton.type='button';saveButton.className='fert-mode-btn';saveButton.textContent='Uložit aktuální jako novou';
   saveButton.addEventListener('click',async()=>{
     const name=nameInput.value.trim();
     if(!name){alert('Zadej název uložené receptury.');nameInput.focus();return;}
     const recipe=collectAutoFertilizerRecipe(slot);
     if(recipe.error){alert(recipe.error);return;}
     if(!recipe.items.length){alert('Nejdřív vyber alespoň jedno hnojivo do receptury '+label+'.');return;}
     if(!confirm('Opravdu uložit aktuální recepturu jako „'+name+'“ do seznamu '+label+'?'))return;
     try{
       const response=await postJson('fertilizer_recipe_library_save',{phase:phase,name:name,items:recipe.items});
       if(!response||!response.success)throw new Error((response&&response.message)||'Recepturu se nepodařilo uložit.');
       nameInput.value='';
       await loadList();
       alert('Receptura „'+name+'“ byla uložena.');
     }catch(error){alert(error&&error.message?error.message:'Chyba při ukládání receptury.');}
   });
   saveRow.appendChild(nameInput);saveRow.appendChild(saveButton);panel.appendChild(saveRow);
   const list=document.createElement('div');list.className='auto-fert-saved-recipe-list';list.textContent='Načítám uložené receptury…';panel.appendChild(list);
   if(parent)parent.appendChild(panel);else content.appendChild(panel);
   async function loadList(){
     list.innerHTML='';
     try{
       const response=await postJson('fertilizer_recipe_library_get',{phase:phase});
       if(!response||!response.success)throw new Error((response&&response.message)||'Seznam se nepodařilo načíst.');
       const recipes=Array.isArray(response.recipes)?response.recipes:[];
       if(!recipes.length){const empty=document.createElement('p');empty.className='fert-hint';empty.textContent='Zatím zde nejsou uložené žádné receptury.';list.appendChild(empty);return;}
       recipes.forEach((saved,index)=>{
         const row=document.createElement('div');row.style.cssText='display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;padding:8px 0;border-top:1px solid #e2e8f0;';
         const title=document.createElement('span');title.textContent=(index+1)+'. '+saved.name+' ('+(Array.isArray(saved.items)?saved.items.length:0)+' hnojiv)';title.style.cssText='font-size:14px;color:#334155;';
         const load=document.createElement('button');load.type='button';load.className='fert-mode-btn';load.textContent='Načíst';
         load.addEventListener('click',()=>{
           if(!confirm('Načíst recepturu „'+saved.name+'“ do receptury '+label+'? Aktuální výběr '+label+' bude nahrazen.'))return;
           const currentSettings=collectAutoFertilizerSettings().settings;
           currentSettings.recipes[slot-1]={name:label,items:saved.items};
           autoFertilizerSettings=currentSettings;
           renderAutoFertilizerSettings(currentSettings);
           updateAutoFertilizerPreview();
           alert('Receptura „'+saved.name+'“ byla načtena. Zkontroluj týdenní rozpis a potom použij „Zapsat plán do Growreportu“.');
         });
         row.appendChild(title);row.appendChild(load);list.appendChild(row);
       });
     }catch(error){list.textContent=error&&error.message?error.message:'Chyba při načítání seznamu receptur.';}
   }
   await loadList();
 }

 function collectAutoFertilizerRecipe(slot){
   const items=[];let error='';
   document.querySelectorAll('#fert-modal-content .auto-fert-item-row[data-recipe="'+slot+'"] .auto-fert-active:checked').forEach(checkbox=>{
     const itemRow=checkbox.closest('.auto-fert-item-row');if(!itemRow)return;
     const kind=itemRow.dataset.kind;const name=String(itemRow.dataset.name||'');
     const amountInput=itemRow.querySelector('.auto-fert-base');const amount=Number(amountInput&&amountInput.value);const max=kind==='dry'?40:500;
     if(!Number.isInteger(amount)||amount<1||amount>max){error='Dávka hnojiva „'+name+'“ musí být 1 až '+max+(kind==='dry'?' odměrek.':' ml.');return;}
     items.push(kind==='dry'?{name,kind,base_scoops:amount}:{name,kind,base_ml:amount});
   });
   return {name:slot===1?'RŮST':'KVĚT',items,error};
 }

 async function saveAutoFertilizerRecipe(slot){
   const recipe=collectAutoFertilizerRecipe(slot);
   if(recipe.error){alert(recipe.error);return;}
   if(!recipe.items.length){alert('Nejdřív vyber alespoň jedno hnojivo do receptury '+recipe.name+'.');return;}
   try{
     const response=await postJson('user_fertilizer_recipe_save',{slot:slot,recipe:recipe});
     if(!response||!response.success)throw new Error((response&&response.message)||'Recepturu se nepodařilo uložit.');
     alert('Receptura '+slot+' '+recipe.name+' byla uložena.');
   }catch(error){alert('Chyba při ukládání receptury: '+(error&&error.message?error.message:'neznámá chyba'));}
 }

 async function loadAutoFertilizerRecipe(slot){
   try{
     const response=await postJson('user_fertilizer_recipes_get',{});
     if(!response||!response.success)throw new Error((response&&response.message)||'Uložené receptury se nepodařilo načíst.');
     const recipes=Array.isArray(response.recipes)?response.recipes:[];
     const saved=recipes.find(recipe=>Number(recipe.slot)===slot);
     if(!saved||!Array.isArray(saved.items)||!saved.items.length){alert('Pro recepturu '+slot+' '+(slot===1?'RŮST':'KVĚT')+' zatím není uložena žádná receptura.');return;}
     const currentSettings=collectAutoFertilizerSettings().settings;
     currentSettings.recipes[slot-1]={name:slot===1?'RŮST':'KVĚT',items:saved.items};
     autoFertilizerSettings=currentSettings;
     renderAutoFertilizerSettings(currentSettings);
     updateAutoFertilizerPreview();
     alert('Uložená receptura '+slot+' '+(slot===1?'RŮST':'KVĚT')+' byla načtena. Zkontroluj týdenní rozpis a potom použij „Zapsat plán do Growreportu“.');
   }catch(error){alert('Chyba při načítání receptury: '+(error&&error.message?error.message:'neznámá chyba'));}
 }

 function renderAutoFertilizerRecipeEditor(slot,recipe){
   const nameValue=slot===1?'RŮST':'KVĚT';
   const table=document.createElement('table');table.className='auto-fert-recipe-table';
   const head=document.createElement('thead');const headRow=document.createElement('tr');
   ['Použít','Hnojivo','Druh','Základní množství'].forEach(label=>{const th=document.createElement('th');th.textContent=label;headRow.appendChild(th);});head.appendChild(headRow);table.appendChild(head);
   const body=document.createElement('tbody');const saved={};
   (Array.isArray(recipe.items)?recipe.items:[]).forEach(item=>{saved[String(item.kind)+'|'+String(item.name)]=item;});
   const liquid=Array.from(new Set((presetLiquid||[]).concat(custom.liquid||[],Object.keys(saved).filter(key=>key.indexOf('liquid|')===0).map(key=>key.substring(7))).map(value=>String(value||'').trim()).filter(Boolean)));
   const dry=Array.from(new Set((presetDry||[]).concat(custom.dry||[],Object.keys(saved).filter(key=>key.indexOf('dry|')===0).map(key=>key.substring(4))).map(value=>String(value||'').trim()).filter(Boolean)));
   function addItem(name,kind){
     const row=document.createElement('tr');row.className='auto-fert-item-row';row.dataset.name=name;row.dataset.kind=kind;row.dataset.recipe=String(slot);
     const checkCell=document.createElement('td');const checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.className='auto-fert-active';checkCell.appendChild(checkbox);row.appendChild(checkCell);
     const nameCell=document.createElement('td');nameCell.textContent=name;row.appendChild(nameCell);
     const kindCell=document.createElement('td');kindCell.textContent=kind==='dry'?'Suché':'Tekuté';row.appendChild(kindCell);
     const amountCell=document.createElement('td');const input=document.createElement('input');input.type='number';input.min='1';input.max=kind==='dry'?'40':'500';input.step='1';input.className='auto-fert-base';input.disabled=true;
     const oldItem=saved[kind+'|'+name];
     if(oldItem){checkbox.checked=true;input.disabled=false;input.value=String(kind==='dry'?oldItem.base_scoops:oldItem.base_ml);}
     amountCell.appendChild(input);const unit=document.createElement('span');unit.className='auto-fert-unit';unit.textContent=kind==='dry'?' odměrek':' ml';amountCell.appendChild(unit);row.appendChild(amountCell);
     checkbox.addEventListener('change',()=>{input.disabled=!checkbox.checked;if(checkbox.checked&&!input.value)input.value='1';updateAutoFertilizerPreview();});
     input.addEventListener('input',updateAutoFertilizerPreview);
     body.appendChild(row);
   }
   liquid.forEach(name=>addItem(name,'liquid'));dry.forEach(name=>addItem(name,'dry'));
   table.appendChild(body);const wrap=document.createElement('div');wrap.className='auto-fert-recipe-wrap';wrap.appendChild(table);content.appendChild(wrap);
 }

 function collectAutoFertilizerSettings(){
   const frequency=Number((document.getElementById('auto-fert-frequency')||{}).value||5);
   const recipes=[];let error='';
   for(let slot=1;slot<=2;slot++){
     const recipeName=slot===1?'RŮST':'KVĚT';
     const items=[];
     document.querySelectorAll('#fert-modal-content .auto-fert-item-row[data-recipe="'+slot+'"] .auto-fert-active:checked').forEach(checkbox=>{
       const row=checkbox.closest('.auto-fert-item-row');if(!row)return;
       const kind=row.dataset.kind;const name=String(row.dataset.name||'');const amountInput=row.querySelector('.auto-fert-base');const amount=Number(amountInput&&amountInput.value);const max=kind==='dry'?40:500;
       if(!Number.isInteger(amount)||amount<1||amount>max){error='Dávka hnojiva „'+name+'“ v receptuře '+(slot<=2?recipeName:'Samostatná '+(slot-2))+' musí být 1 až '+max+(kind==='dry'?' odměrek.':' ml.');return;}
       items.push(kind==='dry'?{name,kind,base_scoops:amount}:{name,kind,base_ml:amount});
     });
     const cleanRecipe={name:recipeName,items};
     recipes.push(cleanRecipe);
   }
   const week_increases={},week_recipes={},week_doses={};
   document.querySelectorAll('#fert-modal-content .auto-fert-week-increase').forEach(select=>{week_increases[String(select.dataset.autoWeek)]=Number(select.value);});
   document.querySelectorAll('#fert-modal-content .auto-fert-week-recipe').forEach(select=>{week_recipes[String(select.dataset.autoWeek)]=Number(select.value);});
   document.querySelectorAll('#fert-modal-content .auto-fert-week-dose').forEach(input=>{
     const week=String(input.dataset.autoWeek||'');const kind=String(input.dataset.kind||'');const name=String(input.dataset.name||'');const dose=Number(input.value);const max=kind==='dry'?40:500;
     if(!Number.isInteger(dose)||dose<1||dose>max){error='Upravená dávka hnojiva „'+name+'“ v týdnu '+week+' musí být 1 až '+max+(kind==='dry'?' odměrek.':' ml.');return;}
     if(!week_doses[week])week_doses[week]={};week_doses[week][kind+'|'+name]=dose;
   });
   if(![2,3,4,5].includes(frequency))error='Vyber platný interval mezi zálivkami.';
   if(!recipes.some(recipe=>recipe.items.length)&&!error)error='Vyber alespoň jedno hnojivo v receptuře RŮST nebo KVĚT.';
   return {settings:{frequency,recipes,week_recipes,week_increases,week_doses},global_recipes:[],error};
 }

 function makeAutoFertilizerPlan(settings){
   const plan=[];const maxDay=autoFertilizerMaxWeek*7;const frequency=Number(settings.frequency)||5;
   for(let wateringIndex=0,globalDay=1;globalDay<=maxDay;wateringIndex++,globalDay+=frequency){
     // První zálivka hnojení, další dvě pouze voda, třetí zálivka opět hnojení.
     if(wateringIndex%3!==0)continue;
     const week=Math.floor((globalDay-1)/7)+1;const day=(globalDay-1)%7+1;const phase=getAutoFertilizerPhase(week);
     if(phase==='dry')continue;
     const recipeSlot=Number((settings.week_recipes||{})[String(week)]||(phase==='flower'?2:1));
     const recipe=getAutoFertilizerRecipe(recipeSlot,settings);const recipeItems=recipe&&Array.isArray(recipe.items)?recipe.items:[];
     if(!recipeItems.length)continue;
     const percent=week===1?0:Number((settings.week_increases||{})[String(week)]||10);
     const manualWeek=(settings.week_doses||{})[String(week)]||{};const items=[];let warning='';
     recipeItems.forEach(item=>{
       const kind=item.kind==='dry'?'dry':'liquid';const key=kind+'|'+String(item.name||'');
       const calculated=kind==='dry'?Math.round(Number(item.base_scoops)*(100+percent)/100):Math.round(Number(item.base_ml)*(100+percent)/100);
       const dose=manualWeek[key]!==undefined?Number(manualWeek[key]):calculated;const max=kind==='dry'?40:500;
       if(!Number.isInteger(dose)||dose<1||dose>max)warning='Dávka hnojiva „'+item.name+'“ v týdnu '+week+' musí být 1 až '+max+(kind==='dry'?' odměrek.':' ml.');
       items.push({name:String(item.name||''),kind,dose,key});
     });
     plan.push({week,day,phase,recipeSlot,recipeName:getAutoFertilizerRecipeName(recipeSlot,settings),percent,items,warning});
   }
   return plan;
 }

 function updateAutoFertilizerPreview(){
   const collected=collectAutoFertilizerSettings();
   const settings=collected.settings;
   const plan=makeAutoFertilizerPlan(settings);
   const activeWeeks={};plan.forEach(entry=>{activeWeeks[String(entry.week)]=true;});
   document.querySelectorAll('#fert-modal-content .auto-fert-week-increase').forEach(select=>{
     const week=String(select.dataset.autoWeek);const cell=select.parentNode;const state=cell?cell.querySelector('.auto-fert-increase-state'):null;
     const active=!!activeWeeks[week];select.disabled=!active||Number(week)===1;
     if(state)state.textContent=active?(Number(week)===1?' – hnojení na základní dávku':' – hnojení v tomto týdnu'):' – bez hnojení';
   });
   renderAutoFertilizerWeekDoseEditor(plan);
   const summary=document.getElementById('auto-fert-plan-summary');
   if(summary){
     const eventCount=plan.length;const weekCount=Object.keys(activeWeeks).length;
     summary.textContent='Naplánované termíny hnojení: '+eventCount+'. Počet týdnů s hnojením: '+weekCount+'. Mezi každými dvěma hnojenými zálivkami jsou dvě zálivky pouze vodou. Týdny Sušina se vynechají.';
   }
   const errorTarget=document.getElementById('auto-fert-validation-error');
   if(errorTarget){errorTarget.textContent=collected.error||'';errorTarget.style.display=collected.error?'block':'none';}
 }

 function renderAutoFertilizerWeekDoseEditor(plan){
   const target=document.getElementById('auto-fert-week-dose-editor');if(!target)return;
   target.innerHTML='';
   if(!plan.length){const empty=document.createElement('p');empty.className='fert-hint';empty.textContent='Pro zvolené týdny zatím není naplánované žádné hnojení. Zkontroluj fázi týdne a vybranou recepturu.';target.appendChild(empty);return;}
   const table=document.createElement('table');table.className='auto-fert-week-table auto-fert-dose-table';
   const head=document.createElement('thead');const row=document.createElement('tr');
   ['Týden','Termíny hnojení','Fáze','Receptura','Hnojivo','Druh','Dávka pro každý termín'].forEach(label=>{const th=document.createElement('th');th.textContent=label;row.appendChild(th);});head.appendChild(row);table.appendChild(head);
   const body=document.createElement('tbody');const grouped={};
   plan.forEach(entry=>{if(!grouped[String(entry.week)])grouped[String(entry.week)]=[];grouped[String(entry.week)].push(entry);});
   Object.keys(grouped).sort((a,b)=>Number(a)-Number(b)).forEach(week=>{
     const events=grouped[week];const first=events[0];const days=events.map(entry=>'den '+entry.day).join(', ');
     first.items.forEach(item=>{
       const row=document.createElement('tr');
       const weekCell=document.createElement('td');weekCell.textContent='Týden '+week;row.appendChild(weekCell);
       const daysCell=document.createElement('td');daysCell.textContent=days;row.appendChild(daysCell);
       const phaseCell=document.createElement('td');phaseCell.textContent=first.phase==='flower'?'🌼 Květ':'🌿 Růst';row.appendChild(phaseCell);
       const recipeCell=document.createElement('td');recipeCell.textContent=first.recipeName;row.appendChild(recipeCell);
       const nameCell=document.createElement('td');nameCell.textContent=item.name;row.appendChild(nameCell);
       const kindCell=document.createElement('td');kindCell.textContent=item.kind==='dry'?'Suché':'Tekuté';row.appendChild(kindCell);
       const doseCell=document.createElement('td');const input=document.createElement('input');input.type='number';input.min='1';input.max=item.kind==='dry'?'40':'500';input.step='1';input.className='auto-fert-week-dose';input.dataset.autoWeek=String(week);input.dataset.kind=item.kind;input.dataset.name=item.name;input.value=String(item.dose);doseCell.appendChild(input);
       const unit=document.createElement('span');unit.className='auto-fert-unit';unit.textContent=item.kind==='dry'?' odměrek':' ml';doseCell.appendChild(unit);row.appendChild(doseCell);
       if(first.warning){row.className='auto-fert-warning-row';input.title=first.warning;}
       body.appendChild(row);
     });
   });
   table.appendChild(body);target.appendChild(table);
   const warning=plan.find(entry=>entry.warning);
   if(warning){const p=document.createElement('p');p.className='fert-error';p.textContent=warning.warning+' Uprav dávku nebo navýšení v daném týdnu.';target.appendChild(p);}
 }

 async function persistAutoFertilizerSettings(collected){
   const plan=makeAutoFertilizerPlan(collected.settings);const activeWeeks={};plan.forEach(entry=>{activeWeeks[String(entry.week)]=true;});
   for(let week=1;week<=autoFertilizerMaxWeek;week++){
     if(!activeWeeks[String(week)])collected.settings.week_increases[String(week)]=0;
     else if(week===1)collected.settings.week_increases[String(week)]=0;
   }
   const response=await postJson('auto_fertilizer_settings_save',collected.settings);
   if(!response||!response.success)throw new Error((response&&response.message)||'Nastavení automatického hnojení se nepodařilo uložit.');
   autoFertilizerSettings=response.settings||collected.settings;
   autoFertilizerGlobalRecipes=[];
   return response;
 }

 async function saveAutoFertilizerSettings(){
   const collected=collectAutoFertilizerSettings();if(collected.error){alert(collected.error);return;}
   try{
     await persistAutoFertilizerSettings(collected);
     alert('Receptury RŮST a KVĚT a změny dávek byly uloženy.');
     updateAutoFertilizerPreview();
   }catch(error){alert('Chyba při ukládání: '+(error&&error.message?error.message:'neznámá chyba'));}
 }

 async function applyAutoFertilizerPlan(){
   const collected=collectAutoFertilizerSettings();if(collected.error){alert(collected.error);return;}
   const plan=makeAutoFertilizerPlan(collected.settings);const warning=plan.find(entry=>entry.warning);
   if(warning){alert(warning.warning+' Uprav dávku v tabulce „Navýšení dávky podle týdnů 2“.');return;}
   if(!plan.length){alert('Není naplánovaný žádný termín hnojení. Nejdřív vyber receptury a zkontroluj fáze týdnů.');return;}
   if(!confirm('Automatické hnojení se zapíše do Growreportu. První zálivka bude s hnojivy, další dvě pouze s vodou a třetí zálivka znovu s hnojivy. Použijí se uložené týdenní dávky. Týdny Sušina se přeskočí. Předchozí automaticky vytvořené záznamy budou nahrazeny. Pokračovat?'))return;
   try{
     await persistAutoFertilizerSettings(collected);
     const response=await postJson('auto_fertilizer_apply',collected.settings);
     if(!response||!response.success)throw new Error((response&&response.message)||'Plán se nepodařilo zapsat.');
     alert('Automatické hnojení bylo zapsáno do Growreportu. Termínů hnojení: '+response.generated_count+'. Rozestup zálivek: každých '+response.frequency+' dní; po každém hnojení následují dvě zálivky pouze vodou.');
     location.reload();
   }catch(error){alert('Chyba při ukládání automatického hnojení: '+(error&&error.message?error.message:'neznámá chyba'));}
 }
 function renderFertilizerTotals(items, target){
   if(!target) return;
   target.innerHTML='';
   if(!Array.isArray(items)||!items.length){
     const empty=document.createElement('p');empty.className='fert-history-totals-empty';empty.textContent='Zatím nejsou uložená žádná použitá hnojiva.';target.appendChild(empty);return;
   }
   const table=document.createElement('table');table.className='fert-history-totals';
   const thead=document.createElement('thead'), header=document.createElement('tr');
   ['Hnojivo','Druh','Celkem'].forEach(label=>{const th=document.createElement('th');th.textContent=label;header.appendChild(th);});
   thead.appendChild(header);table.appendChild(thead);
   const tbody=document.createElement('tbody');
   items.forEach(item=>{
     const tr=document.createElement('tr');
     const name=document.createElement('td');name.textContent=String(item.name||'');tr.appendChild(name);
     const kind=document.createElement('td');kind.textContent=item.kind==='dry'?'Suché':'Tekuté';tr.appendChild(kind);
     const total=document.createElement('td');
     if(item.kind==='dry'){
       const scoops=Math.max(0,Number(item.total_scoops)||0);
       total.textContent=scoops+' '+(scoops===1?'odměrka':(scoops>=2&&scoops<=4?'odměrky':'odměrek'))+' (50 ml/ks)';
     }else{
       if(String(item.name||'').toLocaleLowerCase('cs-CZ')==='melasový čaj' && Number(item.total_water_l)>0){
         const liters=Number(item.total_water_l);const spoons=Math.max(0,Number(item.total_teaspoons)||0);
         const tablespoons=spoons/4;
         const tablespoonsText=tablespoons.toLocaleString('cs-CZ',{maximumFractionDigits:2});
         total.textContent=liters.toLocaleString('cs-CZ',{maximumFractionDigits:1})+' l vody / '+spoons+'ČL='+tablespoonsText+'PL';
       }else{ total.textContent=(Math.max(0,Number(item.total_ml)||0))+' ml'; }
     }
     tr.appendChild(total);tbody.appendChild(tr);
   });
   table.appendChild(tbody);target.appendChild(table);
 }
 function loadFertilizerTotals(openWeek, openDay){
   const totalsTitle=document.getElementById('fert-history-totals-title');
   if(totalsTitle) totalsTitle.textContent='Všechna použitá hnojiva – celkem do týdne '+openWeek;
   postJson('fertilizer_totals',{week:openWeek}).then(r=>{
     if(!current||current.week!==openWeek||current.day!==openDay) return;
     const target=document.getElementById('fert-history-totals-wrap');
     if(!target) return;
     if(!r||!r.success) throw new Error((r&&r.message)||'Nepodařilo se načíst souhrn hnojiv.');
     renderFertilizerTotals(r.items,target);
   }).catch(e=>{
     if(!current||current.week!==openWeek||current.day!==openDay) return;
     const target=document.getElementById('fert-history-totals-wrap');
     if(target){target.innerHTML='';const error=document.createElement('p');error.className='fert-history-totals-empty';error.textContent='Souhrn hnojiv se nepodařilo načíst: '+(e&&e.message?e.message:'neznámá chyba');target.appendChild(error);}
   });
 }
 function loadCustom(){
   return postJson('custom_fertilizers_get',{}).then(r=>{
     if(!r || !r.success) throw new Error((r&&r.message)||'Server odmítl načíst uložená vlastní hnojiva.');
     custom={liquid:Array.isArray(r.liquid)?r.liquid:Array(20).fill(''),dry:Array.isArray(r.dry)?r.dry:Array(10).fill('')};
     return r;
   });
 }
 function loadExistingAndChoose(mode){
   Promise.all([loadCustom(),postJson('fertilizer_get',{week:current.week,day:current.day})])
     .then(([_,saved])=>{
       if(!saved || !saved.success) throw new Error((saved&&saved.message)||'Server odmítl načíst uložená hnojiva pro tento den.');
       const oldItems=saved.fertilizer&&(saved.fertilizer.mode===mode||saved.fertilizer.auto_generated)&&Array.isArray(saved.fertilizer.items)?saved.fertilizer.items:[];
       chosen=oldItems.map(x=>{
         const kind=kindForItem(x,mode);
         if(kind==='dry'){
           let scoops=Number(x.scoops);
           if(!Number.isFinite(scoops)||scoops<1) scoops=Math.max(1,Math.min(40,Math.round(Number(x.ml||50)/50)));
           return {name:String(x.name||''),kind:'dry',scoops:Math.max(1,Math.min(40,Math.round(scoops)))};
         }
         if (String(x.name||'').toLocaleLowerCase('cs-CZ')==='melasový čaj' && Number(x.water_l)>0) return {name:String(x.name||''),kind:'liquid',ml:Math.max(1,Math.min(500,Number(x.ml)||5)),water_l:Number(x.water_l),teaspoons:Number(x.teaspoons)||Math.round(Number(x.water_l)*2)};
         return {name:String(x.name||''),kind:'liquid',ml:Math.max(1,Math.min(500,Number(x.ml)||1))};
       });
       if(mode==='custom' && !custom.liquid.some(x=>String(x||'').trim()) && !custom.dry.some(x=>String(x||'').trim())) editCustom();
       else showChoices(mode);
     })
     .catch(e=>{console.error('Načtení hnojiv selhalo:',e);alert('Nepodařilo se načíst hnojiva.\n\nDůvod: '+(e&&e.message?e.message:'Neznámá chyba.'));});
 }
 function showChoices(mode){
   reset();setTitle(mode==='preset'?'A – Předvolená hnojiva':'B – Vlastní hnojiva');
   const liquid=Array.from(new Set((mode==='preset'?presetLiquid:custom.liquid.filter(x=>String(x||'').trim())).concat(chosen.filter(x=>x.kind==='liquid').map(x=>x.name))));
   const dry=Array.from(new Set((mode==='preset'?presetDry:custom.dry.filter(x=>String(x||'').trim())).concat(chosen.filter(x=>x.kind==='dry').map(x=>x.name))));
   if(mode==='custom'){const b=document.createElement('button');b.type='button';b.textContent='Upravit názvy vlastních hnojiv';b.onclick=editCustom;content.appendChild(b);}
   const cols=document.createElement('div');cols.className='fert-cols';
   [['Tekutá hnojiva (ml)','liquid',liquid],['Suchá hnojiva (odměrka 50 ml)','dry',dry]].forEach(([heading,kind,list])=>{
     const col=document.createElement('div');const h=document.createElement('h3');h.textContent=heading;col.appendChild(h);
     const ul=document.createElement('div');ul.className='fert-list';
     if(!list.length){const p=document.createElement('p');p.className='fert-hint';p.textContent='Zatím nejsou vyplněné žádné názvy.';ul.appendChild(p);}
     list.forEach(name=>{
       const lab=document.createElement('label');const cb=document.createElement('input');cb.type='checkbox';
       cb.checked=chosen.some(x=>x.kind===kind&&x.name===name);
       cb.onchange=()=>{
         if(cb.checked){if(!chosen.some(x=>x.kind===kind&&x.name===name))chosen.push(kind==='dry'?{name,kind,scoops:1}:{name,kind,ml:1});}
         else chosen=chosen.filter(x=>!(x.kind===kind&&x.name===name));
       };
       lab.append(cb,document.createTextNode(name));ul.appendChild(lab);
     });
     col.appendChild(ul);cols.appendChild(col);
   });
   content.appendChild(cols);
   btn('Zpět',openMode.bind(null,current.week,current.day));
   btn('OK – zadat množství',()=>{if(!chosen.length){alert('Vyber alespoň jedno hnojivo.');return;}showAmounts(mode);});
 }
 function editCustom(){
   reset();setTitle('Vlastní hnojiva – názvy');
   content.innerHTML='<p class="fert-hint">Vyplň názvy do 20 polí pro tekutá a 10 polí pro suchá hnojiva. Prázdná pole se nenabídnou při výběru. Suchá hnojiva se následně dávkují po odměrkách 50 ml.</p><div class="fert-name-grid"><div><h3>Tekutá (20 možností)</h3><div id="custom-liquid"></div></div><div><h3>Suchá (10 možností)</h3><div id="custom-dry"></div></div></div>';
   [['liquid','custom-liquid',20],['dry','custom-dry',10]].forEach(([key,id,n])=>{const box=document.getElementById(id);for(let i=0;i<n;i++){const inp=document.createElement('input');inp.type='text';inp.maxLength=80;inp.placeholder=(key==='liquid'?'Tekuté ':'Suché ')+(i+1);inp.value=custom[key][i]||'';inp.dataset.index=i;box.appendChild(inp);}});
   btn('Zpět',()=>showChoices('custom'));
   btn('Uložit názvy',()=>{
     const get=id=>Array.from(document.querySelectorAll('#'+id+' input')).map(x=>x.value.trim());
     postJson('custom_fertilizers_save',{liquid:get('custom-liquid'),dry:get('custom-dry')}).then(r=>{
       if(!r.success){alert(r.message||'Uložení se nezdařilo.');return;}
       custom={liquid:r.liquid,dry:r.dry};alert('Názvy vlastních hnojiv jsou uložené.');showChoices('custom');
     }).catch(e=>alert('Chyba při ukládání názvů: '+e.message));
   });
 }
 function showAmounts(mode){
   reset();setTitle('Množství hnojiv');
   content.innerHTML='<p>Tekutá hnojiva: 1–500 ml pro každé. Suchá hnojiva: 1–40 odměrek po 50 ml pro každé.</p>';
   chosen.forEach((item,i)=>{
     const isDry=item.kind==='dry';const row=document.createElement('div');row.className='fert-amount-row';
     const isMelasa=!isDry && String(item.name||'').toLocaleLowerCase('cs-CZ')==='melasový čaj';
     const label=document.createElement('label');label.textContent=isMelasa?'Melasový čaj – množství vody (L)':(isDry?item.name+' (odměrka 50 ml)':item.name+' (ml)');
     if(isMelasa){
       const picker=document.createElement('div');picker.className='sp-wrap';picker.style.margin='8px auto';picker.style.width='100px';picker.style.height='180px';picker.dataset.index=i;picker.dataset.kind='melasa';
       const dose=document.createElement('div');dose.style.cssText='text-align:center;font-weight:800;color:#2e7d32;margin:8px 0 12px';dose.id='melasa-dose-'+i;
       row.append(label,picker,dose);content.appendChild(row);
       initScrollPicker(picker,20,0.5,Number(item.water_l)||0.5);
       const updateDose=()=>{const water=picker.getVal();const teaspoons=Math.round(water*2);const tablespoons=Math.floor(water/2);const remaining=Math.max(0,teaspoons-tablespoons*4);item.water_l=water;item.teaspoons=teaspoons;dose.textContent=(tablespoons?tablespoons+' polévková lžíce'+(tablespoons===1?'':'e'):'')+(remaining?(tablespoons?' + ':'')+remaining+' čajové lžičky':'');};
       picker.addEventListener('scroll',()=>requestAnimationFrame(updateDose));setTimeout(updateDose,70);
     }else{
       const inp=document.createElement('input');inp.type='number';inp.min='1';inp.max=isDry?'40':'500';inp.step='1';inp.required=true;inp.value=isDry?(item.scoops||1):(item.ml||1);inp.dataset.index=i;inp.dataset.kind=item.kind;
       row.append(label,inp);content.appendChild(row);
     }
   });
   btn('Zpět',()=>showChoices(mode));
   btn('OK – uložit',()=>{
     const items=[];
     for(const picker of content.querySelectorAll('.sp-wrap[data-kind="melasa"]')){
       const item=chosen[Number(picker.dataset.index)];
       const waterL=picker.getVal();
       if(!Number.isFinite(waterL)||waterL<0.5){alert('Vyber alespoň 0,5 litru vody pro melasový čaj.');return;}
       const teaspoons=Math.round(waterL*2);
       items.push({name:item.name,kind:'liquid',ml:5*teaspoons,water_l:waterL,teaspoons:teaspoons});
     }
     for(const inp of content.querySelectorAll('input[type=number]')){
       const value=Number(inp.value);const item=chosen[Number(inp.dataset.index)];const isDry=item.kind==='dry';const max=isDry?40:500;
       if(!Number.isInteger(value)||value<1||value>max){alert(isDry?'Každé suché hnojivo musí mít 1 až 40 odměrek.':'Každé tekuté hnojivo musí mít 1 až 500 ml.');inp.focus();return;}
       items.push(isDry?{name:item.name,kind:'dry',scoops:value}:{name:item.name,kind:'liquid',ml:value});
     }
     postJson('fertilizer_save',{week:current.week,day:current.day,mode,items}).then(r=>{
       if(!r.success){alert(r.message||'Hnojiva se nepodařilo uložit.');return;}
       if (!dailyTaskFertilizersData[String(current.week)] || typeof dailyTaskFertilizersData[String(current.week)] !== 'object') dailyTaskFertilizersData[String(current.week)] = {};
       dailyTaskFertilizersData[String(current.week)][String(current.day)] = items.map(function(item){ return Object.assign({}, item); });
       if (!dailyTaskHnojenoData[String(current.week)] || typeof dailyTaskHnojenoData[String(current.week)] !== 'object') dailyTaskHnojenoData[String(current.week)] = {};
       dailyTaskHnojenoData[String(current.week)][String(current.day)] = items.length > 0;
       const melasaItem=(Array.isArray(r.items)?r.items:[]).find(function(item){return String(item.name||'').toLocaleLowerCase('cs-CZ')==='melasový čaj' && Number(item.water_l)>0;});
       updateDay(current.week,current.day,Number(r.total_ml)||0,Number(r.total_scoops)||0,melasaItem?Number(melasaItem.water_l):0,melasaItem?Number(melasaItem.teaspoons):0);
       updateDailyTaskNote(current.week,current.day);
       hide();
     }).catch(e=>alert('Chyba při ukládání hnojiv: '+e.message));
   });
 }
 function updateDay(week,day,totalMl,totalScoops,melasaWater=0,melasaTeaspoons=0){
   const cb=document.getElementById('hnojeno-'+week+'-'+day);const strip=document.getElementById('day-hnojeno-'+week+'-'+day);const button=document.getElementById('hnoj-'+week+'-'+day);
   const hasAny=totalMl>0||totalScoops>0||melasaTeaspoons>0||melasaWater>0;if(cb)cb.checked=hasAny;if(strip)strip.style.backgroundColor=hasAny?'brown':'';
   if(button){button.dataset.total=String(totalMl);button.dataset.scoops=String(totalScoops);button.dataset.melasaWater=String(melasaWater);button.dataset.melasaTeaspoons=String(melasaTeaspoons);button.innerHTML=hasAny?((totalMl>0?'<span class="hnoj-total '+(String(totalMl).length>3?'small':'')+'">'+totalMl+' ml</span>':'')+(totalScoops>0?'<span class="hnoj-dry-total">'+totalScoops+'</span>':'')+(melasaTeaspoons>0?'<span class="hnoj-melasa-total">mel:'+melasaTeaspoons+'</span>':'')):'<span class="hnoj-plus">+</span>';}
 }
 window.clearFertilizerDay=function(week,day){
   const w=Number(week), d=Number(day);
   if(!Number.isInteger(w)||w<0||!Number.isInteger(d)||d<1||d>7){alert('Neplatný týden nebo den.');return;}
   if(!confirm('Opravdu chceš vymazat všechna hnojiva pro týden '+w+', den '+d+'? Tato akce odstraní tekutá i suchá hnojiva pouze z tohoto dne.')) return;
   postJson('fertilizer_clear',{week:w,day:d}).then(r=>{
     if(!r||!r.success) throw new Error((r&&r.message)||'Server nepotvrdil vymazání hnojiv.');
     updateDay(w,d,0,0);
     if (!dailyTaskFertilizersData[String(w)] || typeof dailyTaskFertilizersData[String(w)] !== 'object') dailyTaskFertilizersData[String(w)] = {};
     dailyTaskFertilizersData[String(w)][String(d)] = [];
     if (!dailyTaskHnojenoData[String(w)] || typeof dailyTaskHnojenoData[String(w)] !== 'object') dailyTaskHnojenoData[String(w)] = {};
     dailyTaskHnojenoData[String(w)][String(d)] = false;
     updateDailyTaskNote(w,d);
     if (current && current.week === w && current.day === d) hide();
   }).catch(e=>{
     console.error('Vymazání hnojiv selhalo:',e);
     alert('Hnojiva se nepodařilo vymazat.\n\nDůvod: '+(e&&e.message?e.message:'Neznámá chyba.'));
   });
 };
 backdrop.addEventListener('click',e=>{if(e.target===backdrop)hide();});
})();


// ========== SVĚTLA ==========
// Diagnostika světel je záměrně oddělená, aby chyba při otevírání/ukládání
// nezastavila vykreslení týdnů ani ostatní funkce growreportu.
window.showLightsDebug = function(message) {
    const text = 'DEBUG SVĚTEL: ' + String(message || 'Neznámá chyba.');
    console.error(text);
    let box = document.getElementById('lights-debug-global');
    if (!box) {
        box = document.createElement('div');
        box.id = 'lights-debug-global';
        box.setAttribute('role', 'alert');
        box.style.cssText = 'position:fixed;left:12px;right:12px;bottom:12px;z-index:20000;background:#fff3f3;color:#7f0000;border:2px solid #c62828;border-radius:8px;padding:12px;font:13px/1.4 monospace;white-space:pre-wrap;box-shadow:0 4px 18px #0003;max-height:35vh;overflow:auto;';
        const close = document.createElement('button');
        close.type = 'button'; close.textContent = 'Zavřít';
        close.style.cssText = 'float:right;margin-left:12px;';
        close.addEventListener('click', () => { box.style.display = 'none'; });
        box.appendChild(close);
        const detail = document.createElement('div'); detail.id = 'lights-debug-global-text';
        box.appendChild(detail);
        document.body.appendChild(box);
    }
    const detail = document.getElementById('lights-debug-global-text');
    if (detail) detail.textContent = text;
    box.style.display = 'block';
    const inline = document.getElementById('lights-debug-message');
    if (inline) { inline.style.display = 'block'; inline.textContent = text; }
};
window.addEventListener('error', function(event) {
    const info = String(event && (event.message || (event.error && event.error.message)) || 'JavaScript chyba');
    if (/light|svět|openLights|lightsData/i.test(info)) window.showLightsDebug(info + (event.filename ? '\nSoubor: ' + event.filename + ':' + event.lineno : ''));
});
window.addEventListener('unhandledrejection', function(event) {
    const reason = event && event.reason;
    const info = String(reason && reason.message ? reason.message : reason || 'Nezachycená asynchronní chyba');
    if (/light|svět|openLights|lightsData|HTTP \d{3}|JSON/i.test(info)) window.showLightsDebug(info);
});

function positionGrowTip(tip, btn, lines) {
    if (!tip || !btn) return;
    tip.innerHTML = '';
    if (!lines.length) lines = ['Není uložen žádný záznam'];
    lines.forEach(function(line) {
        const div = document.createElement('div');
        div.className = 'tip-line';
        div.textContent = line;
        tip.appendChild(div);
    });
    const rect = btn.getBoundingClientRect();
    tip.style.display = 'flex';
    tip.style.minWidth = Math.max(150, rect.width * 3) + 'px';
    tip.style.minHeight = Math.max(48, rect.height * 3) + 'px';
    const width = tip.offsetWidth, height = tip.offsetHeight;
    let left = rect.left + rect.width / 2 - width / 2;
    let top = rect.top + rect.height / 2 - height / 2;
    left = Math.max(5, Math.min(left, window.innerWidth - width - 5));
    top = Math.max(5, Math.min(top, window.innerHeight - height - 5));
    tip.style.left = left + 'px';
    tip.style.top = top + 'px';
}
function showLightsTip(btn) {
    const match = String(btn.id || '').match(/^lights-(\d+)-(\d+)$/);
    if (!match) return;
    const week = match[1], day = match[2];
    const perWeek = lightsData && (lightsData[String(week)] || lightsData[week]);
    const items = perWeek && (perWeek[String(day)] || perWeek[day]);
    const lines = [];
    (Array.isArray(items) ? items : []).forEach(function(item) {
        if (!item) return;
        lines.push(String(item.name || 'Světlo'));
        if (item.distance_cm !== undefined && Number(item.distance_cm) > 0) lines.push('Vzdálenost: ' + item.distance_cm + ' cm');
        if (item.watts !== undefined && Number(item.watts) > 0) lines.push('Výkon: ' + item.watts + ' W');
        if (item.hours !== undefined && Number(item.hours) > 0) lines.push('Doba svícení: ' + item.hours + ' h');
        if (item.min_watts !== undefined && Number(item.min_watts) > 0) lines.push('Min. výkon: ' + item.min_watts + ' W');
        if (item.max_watts !== undefined && Number(item.max_watts) > 0) lines.push('Max. výkon: ' + item.max_watts + ' W');
        if (item.min_hours !== undefined && Number(item.min_hours) > 0) lines.push('Min. doba: ' + item.min_hours + ' h');
        if (item.max_hours !== undefined && Number(item.max_hours) > 0) lines.push('Max. doba: ' + item.max_hours + ' h');
    });
    const tip = document.getElementById('lights-tip');
    if (!lines.length) { if (tip) tip.style.display = 'none'; return; }
    positionGrowTip(tip, btn, lines);
}
function hideLightsTip() { const tip = document.getElementById('lights-tip'); if (tip) tip.style.display = 'none'; }
function showAirflowTip(btn) {
    const match = String(btn.id || '').match(/^airflow-(\d+)-(\d+)$/);
    if (!match) return;
    const week = match[1], day = match[2];
    const perWeek = airflowData && (airflowData[String(week)] || airflowData[week]);
    const settings = perWeek && (perWeek[String(day)] || perWeek[day]);
    const lines = [];
    (settings && Array.isArray(settings.fans) ? settings.fans : []).forEach(function(fan, index) {
        lines.push('Větrák ' + (index + 1) + ' – ' + (fan.mode === 'dark' ? 'Tma' : 'Světlo'));
        var scale = Number(fan.speed_scale || 3), speed = Number(fan.speed || (fan.speed_scale ? 1 : 3));
        var totalWatts = Number(fan.watts || 0);
        var used = totalWatts * speed / scale;
        lines.push('Výkon: ' + totalWatts + ' W · Rychlost: ' + speed + '/' + scale + ' · Čas: ' + fan.hours + ' h');
        if (totalWatts > 0) lines.push('Použitých wattů: ' + used.toFixed(2) + ' W');
    });
    (settings && Array.isArray(settings.exhausts) ? settings.exhausts : []).forEach(function(device, index) {
        lines.push((device.name || 'Odtah/přívod') + ' ' + (index + 1));
        lines.push('Výkon: ' + device.watts + ' W · Čas: ' + device.hours + ' h · Rychlost: ' + device.speed);
    });
    const tip = document.getElementById('airflow-tip');
    if (!lines.length) { if (tip) tip.style.display = 'none'; return; }
    positionGrowTip(tip, btn, lines);
}
function hideAirflowTip() { const tip = document.getElementById('airflow-tip'); if (tip) tip.style.display = 'none'; }

const availableLights = ['HPS', 'MH', 'CMH/LEC', 'UVA/UVB', 'LED', 'CFL', 'T5', 'Plazmová výbojka'];
let lightsCtx = null;
let selectedLightNames = [];
let lightsWorking = [];
window.openLights = function(week, day = 1) {
    try {
        const w = Number(week);
        const d = Number(day);
        if (!Number.isInteger(w) || w < 0 || !Number.isInteger(d) || d < 1 || d > 7) throw new Error('Neplatný týden nebo den pro nastavení světel.');
        const dialog = document.getElementById('lights-dialog');
        const title = document.getElementById('lights-dialog-title');
        if (!dialog || !title || !document.getElementById('lights-pick-list')) {
            throw new Error('Na stránce chybí HTML dialogu světel (lights-dialog / lights-pick-list). Zkontroluj nahranou verzi souboru.');
        }
        if (lightsLoadError) {
            window.showLightsDebug('Nepodařilo se načíst uložené nastavení světel z databáze: ' + lightsLoadError + '\nDialog otevřu, ale po uložení bude potřeba ověřit tabulku growreport_lights2.');
        } else {
            const dbg = document.getElementById('lights-debug-global');
            if (dbg) dbg.style.display = 'none';
            const inModal = document.getElementById('lights-debug-message');
            if (inModal) { inModal.style.display = 'none'; inModal.textContent = ''; }
        }
        lightsCtx = { week: w, day: d };
        const perWeek = lightsData && lightsData[String(w)] && typeof lightsData[String(w)] === 'object' ? lightsData[String(w)] : {};
        const saved = Array.isArray(perWeek[String(d)]) ? perWeek[String(d)] : (Array.isArray(perWeek[d]) ? perWeek[d] : []);
        lightsWorking = saved.map(x => Object.assign({}, x));
        selectedLightNames = lightsWorking.map(x => x.name);
        title.textContent = 'Světla – týden ' + w + ', den ' + d;
        renderLightSelection();
        document.getElementById('lights-step-select').style.display = '';
        document.getElementById('lights-step-values').style.display = 'none';
        document.getElementById('lights-next').style.display = '';
        document.getElementById('lights-save').style.display = 'none';
        document.getElementById('lights-back').style.display = 'none';
        dialog.style.display = 'flex';
        if (getComputedStyle(dialog).display === 'none') throw new Error('Dialog světel se nepodařilo zobrazit (CSS display:none).');
    } catch (error) {
        window.showLightsDebug('Otevření světel selhalo: ' + (error && error.message ? error.message : error));
        alert('Světla se nepodařilo otevřít.\n\n' + (error && error.message ? error.message : error) + '\n\nNa stránce se zobrazil DEBUG SVĚTEL s podrobnostmi.');
    }
};
function closeLights() {
    const dialog = document.getElementById('lights-dialog');
    if (dialog) dialog.style.display = 'none';
    lightsCtx = null;
}
function renderLightSelection() {
    const wrap = document.getElementById('lights-pick-list');
    wrap.innerHTML = '';
    availableLights.forEach(name => {
        const label = document.createElement('label');
        const cb = document.createElement('input');
        cb.type = 'checkbox'; cb.value = name; cb.checked = selectedLightNames.includes(name);
        cb.addEventListener('change', function() {
            const checked = Array.from(wrap.querySelectorAll('input[type="checkbox"]:checked'));
            if (checked.length > 3) { this.checked = false; alert('Můžeš vybrat nejvýše 3 světla.'); }
            selectedLightNames = Array.from(wrap.querySelectorAll('input[type="checkbox"]:checked')).map(x => x.value);
        });
        const span = document.createElement('span');
        span.textContent = name;
        label.appendChild(cb); label.appendChild(span); wrap.appendChild(label);
    });
}
function moveToLightValues() {
    selectedLightNames = Array.from(document.querySelectorAll('#lights-pick-list input[type="checkbox"]:checked')).map(x => x.value);
    if (selectedLightNames.length < 1 || selectedLightNames.length > 3) { alert('Vyber alespoň jedno a nejvýše tři světla.'); return; }
    const previous = new Map(lightsWorking.map(x => [x.name, x]));
    lightsWorking = selectedLightNames.map(name => {
        const old = previous.get(name);
        if (old) return Object.assign({}, old);
        if (name === 'LED') return {name, distance_cm:0, min_watts:0, min_hours:0, max_watts:0, max_hours:0};
        return {name, distance_cm:0, watts:0, hours:0};
    });
    renderLightValues();
    document.getElementById('lights-step-select').style.display = 'none';
    document.getElementById('lights-step-values').style.display = '';
    document.getElementById('lights-next').style.display = 'none';
    document.getElementById('lights-save').style.display = '';
    document.getElementById('lights-back').style.display = '';
}
function backToLightSelection() {
    document.getElementById('lights-step-select').style.display = '';
    document.getElementById('lights-step-values').style.display = 'none';
    document.getElementById('lights-next').style.display = '';
    document.getElementById('lights-save').style.display = 'none';
    document.getElementById('lights-back').style.display = 'none';
    renderLightSelection();
}
function renderLightValues() {
    const wrap = document.getElementById('lights-values-form');
    wrap.innerHTML = '';
    lightsWorking.forEach((light, index) => {
        const card = document.createElement('div'); card.className = 'lights-card';
        const h = document.createElement('h4'); h.textContent = light.name; card.appendChild(h);
        function addField(labelText, key, min, max, value, suffix, step) {
            const row = document.createElement('label'); row.className = 'lights-field-row';
            const label = document.createElement('span'); label.textContent = labelText;
            const input = document.createElement('input'); input.type = 'number'; input.min = String(min); input.max = String(max); input.step = String(step || 1); input.value = value;
            input.dataset.lightIndex = String(index); input.dataset.lightKey = key;
            input.setAttribute('aria-label', labelText + ' – ' + light.name);
            row.appendChild(label); row.appendChild(input); card.appendChild(row);
        }
        addField('Vzdálenost od rostliny (cm)', 'distance_cm', 10, 200, light.distance_cm ?? 0, 'cm', 1);
        if (light.name === 'LED') {
            addField('Minimální výkon (W)', 'min_watts', 10, 2000, light.min_watts ?? 0, 'W', 1);
            addField('Čas při minimálním výkonu (hod.)', 'min_hours', 1, 24, light.min_hours ?? 0, 'h', 1);
            addField('Maximální výkon (W)', 'max_watts', 10, 2000, light.max_watts ?? 0, 'W', 1);
            addField('Čas při maximálním výkonu (hod.)', 'max_hours', 1, 24, light.max_hours ?? 0, 'h', 1);
        } else {
            addField('Výkon (W)', 'watts', 10, 4000, light.watts ?? 0, 'W', 1);
            addField('Čas chodu (hod.)', 'hours', 1, 24, light.hours ?? 0, 'h', 1);
        }
        wrap.appendChild(card);
    });
}
function saveLights() {
    if (!lightsCtx) return;
    const inputs = document.querySelectorAll('#lights-values-form input[type="number"]');
    inputs.forEach(input => {
        const item = lightsWorking[Number(input.dataset.lightIndex)];
        if (item) item[input.dataset.lightKey] = Number(input.value);
    });
    for (const light of lightsWorking) {
        const distance = Number(light.distance_cm);
        if (!Number.isInteger(distance) || distance < 10 || distance > 200) { alert('Vzdálenost každého světla musí být 10 až 200 cm.'); return; }
        if (light.name === 'LED') {
            if (![light.min_watts, light.max_watts, light.min_hours, light.max_hours].every(Number.isInteger) || light.min_watts < 10 || light.min_watts > 2000 || light.max_watts < light.min_watts || light.max_watts > 2000 || light.min_hours < 1 || light.min_hours > 24 || light.max_hours < 1 || light.max_hours > 24) {
                alert('Zkontroluj u LED výkony 10–2000 W, max. výkon alespoň jako min. výkon a oba časy 1–24 hodin.'); return;
            }
        } else if (!Number.isInteger(light.watts) || light.watts < 10 || light.watts > 4000 || !Number.isInteger(light.hours) || light.hours < 1 || light.hours > 24) {
            alert('U každého světla nastav výkon 10–4000 W a čas 1–24 hodin.'); return;
        }
    }
    const saveButton = document.getElementById('lights-save');
    saveButton.disabled = true;
    postJson('save_lights', {week: lightsCtx.week, day: lightsCtx.day, lights: lightsWorking}).then(data => {
        if (!data.success) { alert(data.message || 'Světla se nepodařilo uložit.'); return; }
        closeLights();
        // Reload computes inheritance by day again; all earlier days remain unchanged.
        location.reload();
    }).catch(error => {
        console.error('Chyba při ukládání světel:', error);
        window.showLightsDebug('Ukládání světel selhalo: ' + (error && error.message ? error.message : error));
        alert('Chyba při ukládání světel: ' + (error && error.message ? error.message : error) + '\nPodrobnosti zůstávají zobrazené v DEBUG SVĚTEL.');
    }).finally(() => { saveButton.disabled = false; });
}

// ========== VĚTRÁKY A ODTAHY ==========
let airflowCtx = null;
let airflowWorking = {fans: [], exhausts: []};

window.openAirflow = function(week, day) {
    try {
        const w = Number(week), d = Number(day);
        if (!Number.isInteger(w) || w < 0 || !Number.isInteger(d) || d < 1 || d > 7) {
            throw new Error('Neplatný týden nebo den pro nastavení větráků a odtahů.');
        }
        const dialog = document.getElementById('airflow-dialog');
        const title = document.getElementById('airflow-dialog-title');
        if (!dialog || !title || !document.getElementById('airflow-fan-count') || !document.getElementById('airflow-exhaust-count')) {
            throw new Error('Na stránce chybí dialog větráků/odtahů. Zkontroluj, zda se nahrál celý soubor show_growreport2.php.');
        }
        airflowCtx = {week: w, day: d};
        const perWeek = airflowData && airflowData[String(w)] ? airflowData[String(w)] : {};
        const saved = perWeek[String(d)] || perWeek[d] || {fans: [], exhausts: []};
        airflowWorking = {
            fans: Array.isArray(saved.fans) ? saved.fans.map(x => Object.assign({}, x)) : [],
            exhausts: Array.isArray(saved.exhausts) ? saved.exhausts.map(x => Object.assign({}, x)) : []
        };
        title.textContent = 'Větráky a odtahy – týden ' + w + ', den ' + d;
        const debug = document.getElementById('airflow-debug-message');
        if (debug) {
            debug.style.display = airflowLoadError ? 'block' : 'none';
            debug.textContent = airflowLoadError ? 'Nepodařilo se načíst data větráků/odtahů: ' + airflowLoadError : '';
        }
        document.getElementById('airflow-fan-count').value = String(Math.max(1, Math.min(10, airflowWorking.fans.length || 1)));
        document.getElementById('airflow-exhaust-count').value = String(Math.max(0, Math.min(2, airflowWorking.exhausts.length || 0)));
        document.getElementById('airflow-step-counts').style.display = '';
        document.getElementById('airflow-step-values').style.display = 'none';
        document.getElementById('airflow-next').style.display = '';
        document.getElementById('airflow-save').style.display = 'none';
        document.getElementById('airflow-back').style.display = 'none';
        dialog.style.display = 'flex';
    } catch (error) {
        console.error('Otevření větráků/odtahů selhalo:', error);
        alert('Větráky a odtahy se nepodařilo otevřít: ' + (error && error.message ? error.message : error));
    }
};

function closeAirflow() {
    const dialog = document.getElementById('airflow-dialog');
    if (dialog) dialog.style.display = 'none';
    airflowCtx = null;
}

function moveToAirflowValues() {
    if (!airflowCtx) return;
    const fanCount = Number(document.getElementById('airflow-fan-count').value);
    const exhaustCount = Number(document.getElementById('airflow-exhaust-count').value);
    if (!Number.isInteger(fanCount) || fanCount < 1 || fanCount > 10 || !Number.isInteger(exhaustCount) || exhaustCount < 0 || exhaustCount > 2) {
        alert('Zvol 1–10 větráků a 0–2 odtahy/přívody.'); return;
    }
    const priorFans = airflowWorking.fans.slice();
    const priorExhausts = airflowWorking.exhausts.slice();
    airflowWorking.fans = Array.from({length: fanCount}, (_, i) => Object.assign({mode: 'light', watts: 0, speed_scale: 3, speed: 1, hours: 1}, priorFans[i] || {}));
    airflowWorking.exhausts = Array.from({length: exhaustCount}, (_, i) => Object.assign({name: 'Odtah', watts: 10, hours: 1, speed: 1}, priorExhausts[i] || {}));
    renderAirflowValues();
    document.getElementById('airflow-step-counts').style.display = 'none';
    document.getElementById('airflow-step-values').style.display = '';
    document.getElementById('airflow-next').style.display = 'none';
    document.getElementById('airflow-save').style.display = '';
    document.getElementById('airflow-back').style.display = '';
}

function backToAirflowCounts() {
    if (!airflowCtx) return;
    document.getElementById('airflow-fan-count').value = String(airflowWorking.fans.length || 1);
    document.getElementById('airflow-exhaust-count').value = String(airflowWorking.exhausts.length || 0);
    document.getElementById('airflow-step-counts').style.display = '';
    document.getElementById('airflow-step-values').style.display = 'none';
    document.getElementById('airflow-next').style.display = '';
    document.getElementById('airflow-save').style.display = 'none';
    document.getElementById('airflow-back').style.display = 'none';
}

function renderAirflowValues() {
    const wrap = document.getElementById('airflow-values-form');
    if (!wrap) throw new Error('Chybí formulář pro hodnoty větráků/odtahů.');
    wrap.innerHTML = '';

    function addNumberField(card, group, index, labelText, key, min, max, value) {
        const row = document.createElement('label'); row.className = 'airflow-field-row';
        const label = document.createElement('span'); label.textContent = labelText;
        const input = document.createElement('input');
        input.type = 'number'; input.min = String(min); input.max = String(max); input.step = '1'; input.value = String(value);
        input.dataset.airflowGroup = group; input.dataset.airflowIndex = String(index); input.dataset.airflowKey = key;
        input.setAttribute('aria-label', labelText);
        row.appendChild(label); row.appendChild(input); card.appendChild(row);
    }
    function addSelectField(card, group, index, labelText, key, options, value) {
        const row = document.createElement('label'); row.className = 'airflow-field-row';
        const label = document.createElement('span'); label.textContent = labelText;
        const select = document.createElement('select');
        select.dataset.airflowGroup = group; select.dataset.airflowIndex = String(index); select.dataset.airflowKey = key;
        options.forEach(option => {
            const element = document.createElement('option'); element.value = option.value; element.textContent = option.label;
            if (option.value === value) element.selected = true;
            select.appendChild(element);
        });
        row.appendChild(label); row.appendChild(select); card.appendChild(row);
    }

    airflowWorking.fans.forEach((fan, index) => {
        const card = document.createElement('div'); card.className = 'airflow-card';
        const h = document.createElement('h4'); h.textContent = 'Větrák ' + (index + 1); card.appendChild(h);
        addSelectField(card, 'fans', index, 'Režim běhu', 'mode', [
            {value:'light', label:'Světlo'}, {value:'dark', label:'Tma'}
        ], fan.mode === 'dark' ? 'dark' : 'light');
        addNumberField(card, 'fans', index, 'Výkon (W)', 'watts', 0, 4000, fan.watts ?? 0);
        addSelectField(card, 'fans', index, 'Stupnice rychlosti', 'speed_scale', [
            {value:3, label:'1 až 3'}, {value:10, label:'1 až 10'}
        ], Number(fan.speed_scale || 3));
        addSelectField(card, 'fans', index, 'Rychlost větráku', 'speed', Array.from({length:Number(fan.speed_scale || 3)}, (_,i)=>({value:i+1,label:String(i+1)})), Number(fan.speed || (fan.speed_scale ? 1 : 3)));
        addNumberField(card, 'fans', index, 'Čas chodu (hod.)', 'hours', 1, 24, fan.hours ?? 1);
        const usedLabel = document.createElement('div'); usedLabel.className='airflow-used-watts'; usedLabel.textContent=''; card.appendChild(usedLabel);
        function updateFanUsedWatts() {
            const wattsInput = wrap.querySelector('input[data-airflow-group="fans"][data-airflow-index="' + index + '"][data-airflow-key="watts"]');
            const scaleInput = wrap.querySelector('select[data-airflow-group="fans"][data-airflow-index="' + index + '"][data-airflow-key="speed_scale"]');
            const speedInput = wrap.querySelector('select[data-airflow-group="fans"][data-airflow-index="' + index + '"][data-airflow-key="speed"]');
            const total = Number(wattsInput && wattsInput.value);
            const scale = Number(scaleInput && scaleInput.value);
            const speed = Number(speedInput && speedInput.value);
            usedLabel.textContent = Number.isFinite(total) && total > 0 && scale > 0 && speed > 0 ? 'Použitých wattů: ' + (total * speed / scale).toFixed(2) + ' W' : '';
        }
        card.querySelectorAll('input, select').forEach(function(field) { field.addEventListener('input', updateFanUsedWatts); field.addEventListener('change', updateFanUsedWatts); });
        updateFanUsedWatts();
        wrap.appendChild(card);
    });
    // Při změně stupnice upravit nabídku rychlosti bez ztráty ostatních zadaných hodnot.
    wrap.querySelectorAll('select[data-airflow-group="fans"][data-airflow-key="speed_scale"]').forEach(function(scaleSelect) {
        scaleSelect.addEventListener('change', function() {
            const index = Number(scaleSelect.dataset.airflowIndex);
            const speedSelect = wrap.querySelector('select[data-airflow-group="fans"][data-airflow-index="' + index + '"][data-airflow-key="speed"]');
            if (!speedSelect) return;
            const max = Number(scaleSelect.value);
            const selected = Math.min(max, Math.max(1, Number(speedSelect.value) || 1));
            speedSelect.innerHTML = '';
            for (let n = 1; n <= max; n++) {
                const option = document.createElement('option'); option.value = String(n); option.textContent = String(n);
                if (n === selected) option.selected = true;
                speedSelect.appendChild(option);
            }
            speedSelect.dispatchEvent(new Event('change', {bubbles: true}));
        });
    });

    airflowWorking.exhausts.forEach((exhaust, index) => {
        const card = document.createElement('div'); card.className = 'airflow-card';
        const h = document.createElement('h4'); h.textContent = 'Odtah / přívod ' + (index + 1); card.appendChild(h);
        addSelectField(card, 'exhausts', index, 'Typ zařízení', 'name', [
            {value:'Odtah', label:'Odtah'}, {value:'Přívod', label:'Přívod'}
        ], exhaust.name === 'Přívod' ? 'Přívod' : 'Odtah');
        addNumberField(card, 'exhausts', index, 'Výkon (W)', 'watts', 10, 4000, exhaust.watts ?? 10);
        addNumberField(card, 'exhausts', index, 'Čas chodu (hod.)', 'hours', 1, 24, exhaust.hours ?? 1);
        addNumberField(card, 'exhausts', index, 'Rychlost (1–10)', 'speed', 1, 10, exhaust.speed ?? 1);
        wrap.appendChild(card);
    });
}

function saveAirflow() {
    if (!airflowCtx) return;
    const fields = document.querySelectorAll('#airflow-values-form [data-airflow-group]');
    fields.forEach(field => {
        const group = field.dataset.airflowGroup;
        const index = Number(field.dataset.airflowIndex);
        const key = field.dataset.airflowKey;
        const item = group === 'fans' ? airflowWorking.fans[index] : airflowWorking.exhausts[index];
        if (item) {
            // SELECT vrací vždy text; číselné položky převedeme na čísla ještě před validací a odesláním.
            const numericKeys = ['watts', 'speed_scale', 'speed', 'hours'];
            item[key] = numericKeys.includes(key) ? Number(field.value) : field.value;
        }
    });

    const debug = document.getElementById('airflow-debug-message');
    function airflowValidationError(message, details) {
        const fullMessage = message + (details ? '\n' + details : '');
        console.error('[Growreport airflow validation]', fullMessage, JSON.parse(JSON.stringify(airflowWorking)));
        if (debug) { debug.style.display = 'block'; debug.textContent = fullMessage; }
        alert(fullMessage);
    }

    for (let i = 0; i < airflowWorking.fans.length; i++) {
        const fan = airflowWorking.fans[i];
        const valid = ['light', 'dark'].includes(fan.mode) && Number.isInteger(fan.watts) && fan.watts >= 0 && fan.watts <= 4000 && [3,10].includes(Number(fan.speed_scale)) && Number.isInteger(fan.speed) && fan.speed >= 1 && fan.speed <= Number(fan.speed_scale) && Number.isInteger(fan.hours) && fan.hours >= 1 && fan.hours <= 24;
        if (!valid) {
            airflowValidationError('Nastavení větráku ' + (i + 1) + ' není platné.', 'Režim: ' + fan.mode + ', výkon: ' + fan.watts + ' W, stupnice: ' + fan.speed_scale + ', rychlost: ' + fan.speed + ', čas: ' + fan.hours + ' h.'); return;
        }
    }
    for (let i = 0; i < airflowWorking.exhausts.length; i++) {
        const exhaust = airflowWorking.exhausts[i];
        if (!['Odtah', 'Přívod'].includes(exhaust.name) || !Number.isInteger(exhaust.watts) || exhaust.watts < 10 || exhaust.watts > 4000 || !Number.isInteger(exhaust.hours) || exhaust.hours < 1 || exhaust.hours > 24 || !Number.isInteger(exhaust.speed) || exhaust.speed < 1 || exhaust.speed > 10) {
            airflowValidationError('Nastavení odtahu/přívodu ' + (i + 1) + ' není platné.', 'Typ: ' + exhaust.name + ', výkon: ' + exhaust.watts + ' W, rychlost: ' + exhaust.speed + ', čas: ' + exhaust.hours + ' h.'); return;
        }
    }

    const saveButton = document.getElementById('airflow-save');
    if (saveButton) saveButton.disabled = true;
    const airflowPayload = {week: airflowCtx.week, day: airflowCtx.day, fans: airflowWorking.fans, exhausts: airflowWorking.exhausts};
    console.info('[Growreport airflow save] Odesílaná data:', airflowPayload);
    postJson('save_airflow', airflowPayload).then(data => {
        if (!data.success) throw new Error(data.message || 'Nastavení větráků/odtahů se nepodařilo uložit.');
        closeAirflow();
        location.reload();
    }).catch(error => {
        const message = 'Ukládání větráků/odtahů selhalo: ' + (error && error.message ? error.message : error);
        console.error(message);
        if (debug) { debug.style.display = 'block'; debug.textContent = message; }
        alert(message);
    }).finally(() => { if (saveButton) saveButton.disabled = false; });
}

// ========== TEPLOTA ==========
let tempCtx = null;

function showTempTip(btn) {
    if (!btn) return;
    const items = [
        { l: btn.dataset.l1, v: btn.dataset.v1 },
        { l: btn.dataset.l2, v: btn.dataset.v2 }
    ].filter(x => x.v !== '' && x.v !== undefined);
    if (items.length === 0) { hideTempTip(); return; }

    const tip = document.getElementById('temp-tip');
    tip.innerHTML = '';
    items.forEach(x => {
        const d = document.createElement('div');
        d.className = 'tip-line'; d.style.color = tempColor(x.v);
        d.textContent = x.l + ': ' + x.v + '°C';
        tip.appendChild(d);
    });

    const r = btn.getBoundingClientRect();
    tip.style.display = 'flex';
    tip.style.minWidth = (r.width * 3) + 'px';
    tip.style.minHeight = (r.height * 3) + 'px';

    const tw = tip.offsetWidth, th = tip.offsetHeight;
    let left = r.left + r.width / 2 - tw / 2;
    let top  = r.top + r.height / 2 - th / 2;
    left = Math.max(5, Math.min(left, window.innerWidth - tw - 5));
    top  = Math.max(5, Math.min(top,  window.innerHeight - th - 5));
    tip.style.left = left + 'px';
    tip.style.top  = top + 'px';
}
function hideTempTip() {
    const t = document.getElementById('temp-tip');
    if (t) t.style.display = 'none';
}

function openTemperature(btn, week, day) {
    hideTempTip();
    tempCtx = { btn, week, day };
    document.getElementById('temp-dialog-title').innerText = 'Teplota (°C) – den ' + (week * 7 - (7 - day));
    document.getElementById('temp-label1').value = btn.dataset.l1 || 'Ráno';
    document.getElementById('temp-value1').value = btn.dataset.v1 || '';
    document.getElementById('temp-label2').value = btn.dataset.l2 || 'Večer';
    document.getElementById('temp-value2').value = btn.dataset.v2 || '';
    document.getElementById('temp-dialog').style.display = 'flex';
    document.getElementById('temp-value1').focus();
}

function closeTemperature() {
    document.getElementById('temp-dialog').style.display = 'none';
    tempCtx = null;
}

function tempColor(v) {
    const n = parseFloat(String(v).replace(',', '.'));
    if (!Number.isFinite(n)) return '';
    return (n >= 18 && n <= 38) ? '#2e7d32' : '#c62828';
}

function renderTempBtn(btn) {
    btn.innerHTML = '';
    const lines = [
        { l: btn.dataset.l1, v: btn.dataset.v1 },
        { l: btn.dataset.l2, v: btn.dataset.v2 }
    ];
    let any = false;

    lines.forEach(item => {
        if (item.v !== '' && item.v !== undefined) {
            any = true;
            const s = document.createElement('span');
            s.className = 'co2-line'; s.style.color = tempColor(item.v);
            s.textContent = (item.l || '').charAt(0).toUpperCase() + item.v + '°';
            btn.appendChild(s);
        }
    });

    if (!any) {
        const s = document.createElement('span');
        s.className = 'co2-plus';
        s.textContent = '+';
        btn.appendChild(s);
    }
}

// ========== VLHKOST ==========
function humidityColor(value) {
    if (value === null || value === undefined || String(value).trim() === '') return '#1565C0';
    const n = Number(value);
    if (!Number.isFinite(n)) return '#1565C0';
    if (n < 30) return '#F57C00';
    if (n <= 80) return '#1565C0';
    return '#C62828';
}

let humidityCtx = null;

function showHumidityTip(btn) {
    if (!btn) return;
    const items = [
        { l: btn.dataset.l1, v: btn.dataset.v1 },
        { l: btn.dataset.l2, v: btn.dataset.v2 }
    ].filter(x => x.v !== '' && x.v !== undefined);
    if (items.length === 0) { hideHumidityTip(); return; }
    const tip = document.getElementById('humidity-tip');
    tip.innerHTML = '';
    items.forEach(x => {
        const d = document.createElement('div');
        d.className = 'tip-line';
        d.style.color = humidityColor(x.v);
        d.textContent = x.l + ': ' + x.v + '%';
        tip.appendChild(d);
    });
    const r = btn.getBoundingClientRect();
    tip.style.display = 'flex';
    tip.style.minWidth = (r.width * 3) + 'px';
    tip.style.minHeight = (r.height * 3) + 'px';
    const tw = tip.offsetWidth, th = tip.offsetHeight;
    let left = r.left + r.width / 2 - tw / 2;
    let top = r.top + r.height / 2 - th / 2;
    left = Math.max(5, Math.min(left, window.innerWidth - tw - 5));
    top = Math.max(5, Math.min(top, window.innerHeight - th - 5));
    tip.style.left = left + 'px';
    tip.style.top = top + 'px';
}
function hideHumidityTip() {
    const tip = document.getElementById('humidity-tip');
    if (tip) tip.style.display = 'none';
}
function openHumidity(btn, week, day) {
    hideHumidityTip();
    humidityCtx = { btn, week, day };
    document.getElementById('humidity-dialog-title').innerText = 'Vlhkost (%) – den ' + (week * 7 - (7 - day));
    document.getElementById('humidity-label1').value = btn.dataset.l1 || 'Ráno';
    document.getElementById('humidity-value1').value = btn.dataset.v1 || '';
    document.getElementById('humidity-label2').value = btn.dataset.l2 || 'Večer';
    document.getElementById('humidity-value2').value = btn.dataset.v2 || '';
    document.getElementById('humidity-dialog').style.display = 'flex';
    document.getElementById('humidity-value1').focus();
}
function closeHumidity() {
    document.getElementById('humidity-dialog').style.display = 'none';
    humidityCtx = null;
}
function renderHumidityBtn(btn) {
    btn.innerHTML = '';
    const lines = [
        { l: btn.dataset.l1, v: btn.dataset.v1 },
        { l: btn.dataset.l2, v: btn.dataset.v2 }
    ];
    let any = false;
    lines.forEach(item => {
        if (item.v !== '' && item.v !== undefined) {
            any = true;
            const span = document.createElement('span');
            span.className = 'co2-line humidity-line';
            span.style.color = humidityColor(item.v);
            span.textContent = (item.l || '').charAt(0).toUpperCase() + item.v + '%';
            btn.appendChild(span);
        }
    });
    if (!any) {
        const span = document.createElement('span');
        span.className = 'co2-plus';
        span.textContent = '+';
        btn.appendChild(span);
    }
}
function saveHumidity() {
    if (!humidityCtx) return;
    const ctx = humidityCtx;
    const payload = {
        week: ctx.week,
        day: ctx.day,
        label1: document.getElementById('humidity-label1').value.trim(),
        value1: document.getElementById('humidity-value1').value.trim(),
        label2: document.getElementById('humidity-label2').value.trim(),
        value2: document.getElementById('humidity-value2').value.trim()
    };
    postJson('save_humidity', payload).then(data => {
        if (data.success) {
            ctx.btn.dataset.l1 = data.label1;
            ctx.btn.dataset.l2 = data.label2;
            ctx.btn.dataset.v1 = data.value1 === null ? '' : String(data.value1);
            ctx.btn.dataset.v2 = data.value2 === null ? '' : String(data.value2);
            renderHumidityBtn(ctx.btn);
            closeHumidity();
        } else {
            alert(data.message || 'Chyba při ukládání vlhkosti.');
        }
    }).catch(err => {
        console.error('Chyba při ukládání vlhkosti:', err);
        alert('Chyba při ukládání vlhkosti: ' + err.message);
    });
}

function saveTemperature() {
    if (!tempCtx) return;
    const ctx = tempCtx;

    const payload = {
        week: ctx.week,
        day: ctx.day,
        label1: document.getElementById('temp-label1').value.trim(),
        value1: document.getElementById('temp-value1').value.trim(),
        label2: document.getElementById('temp-label2').value.trim(),
        value2: document.getElementById('temp-value2').value.trim()
    };

    postJson('save_temperature', payload)
        .then(data => {
            if (data.success) {
                ctx.btn.dataset.l1 = data.label1;
                ctx.btn.dataset.l2 = data.label2;
                ctx.btn.dataset.v1 = data.value1 === null ? '' : String(data.value1);
                ctx.btn.dataset.v2 = data.value2 === null ? '' : String(data.value2);
                renderTempBtn(ctx.btn);
                closeTemperature();
            } else {
                alert(data.message || 'Chyba při ukládání teploty.');
            }
        })
        .catch(err => {
            console.error('Chyba při ukládání teploty:', err);
            alert('Nastala chyba při ukládání teploty.');
        });
}
</script>

<div id="photo-zoom-modal" class="photo-zoom-modal" role="dialog" aria-modal="true" aria-label="Zvětšená fotografie" onclick="if(event.target===this) closePhotoZoom()">
  <div class="photo-zoom-toolbar">
    <div class="photo-zoom-title"><span id="photo-zoom-title-text">Fotografie – plné dostupné rozlišení</span><span id="photo-zoom-dimensions" class="photo-zoom-dimensions"></span></div>
    <div class="photo-zoom-actions">
      <a id="photo-zoom-open-original" href="#" target="_blank" rel="noopener noreferrer">Otevřít samostatně ↗</a>
      <button type="button" onclick="closePhotoZoom()" aria-label="Zavřít zvětšenou fotografii" title="Zavřít">×</button>
    </div>
  </div>
  <button type="button" id="photo-zoom-prev" class="photo-zoom-nav photo-zoom-nav-prev" onclick="previousGalleryPhoto()" aria-label="Předchozí fotografie" title="Předchozí fotografie" style="display:none">‹</button>
  <button type="button" id="photo-zoom-next" class="photo-zoom-nav photo-zoom-nav-next" onclick="nextGalleryPhoto()" aria-label="Další fotografie" title="Další fotografie" style="display:none">›</button>
  <div class="photo-zoom-scroll"><img id="photo-zoom-image" alt="Zvětšená fotografie"></div>
</div>
<script>
(function () {
    var previousBodyOverflow = '';
    var zoomModal = document.getElementById('photo-zoom-modal');
    var zoomImage = document.getElementById('photo-zoom-image');
    var zoomDimensions = document.getElementById('photo-zoom-dimensions');
    var originalLink = document.getElementById('photo-zoom-open-original');
    var zoomTitleText = document.getElementById('photo-zoom-title-text');
    var zoomPrevButton = document.getElementById('photo-zoom-prev');
    var zoomNextButton = document.getElementById('photo-zoom-next');
    var currentZoomFromGallery = false;
    var currentZoomFromWeekPhotos = false;
    var galleryPhotoIndex = -1;
    var weekPhotoIndex = -1;
    var weekPhotoList = [];

    function setPhotoNavigationVisible(visible) {
        if (zoomPrevButton) zoomPrevButton.style.display = visible ? 'inline-flex' : 'none';
        if (zoomNextButton) zoomNextButton.style.display = visible ? 'inline-flex' : 'none';
    }

    function displayZoomSource(src, alt, title, sourceImage) {
        if (!zoomModal || !zoomImage) throw new Error('Okno zvětšení fotografie není dostupné.');
        var alreadyOpen = zoomModal.style.display === 'flex';
        if (!alreadyOpen) previousBodyOverflow = document.body.style.overflow || '';
        zoomImage.onload = function () {
            if (zoomDimensions) zoomDimensions.textContent = zoomImage.naturalWidth + ' × ' + zoomImage.naturalHeight + ' px';
        };
        zoomImage.onerror = function () {
            if (zoomDimensions) zoomDimensions.textContent = 'Fotografii se nepodařilo načíst.';
        };
        zoomImage.src = src;
        zoomImage.alt = alt || 'Zvětšená fotografie';
        if (originalLink) originalLink.href = src;
        if (zoomTitleText) zoomTitleText.textContent = title || 'Fotografie – plné dostupné rozlišení';
        if (zoomDimensions) {
            zoomDimensions.textContent = sourceImage && sourceImage.naturalWidth && sourceImage.naturalHeight
                ? sourceImage.naturalWidth + ' × ' + sourceImage.naturalHeight + ' px'
                : 'Načítám rozměry…';
        }
        zoomModal.style.display = 'flex';
        zoomModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function resolvePhotoZoomImage(button) {
        if (!button) return null;
        var slot = button.closest('.section3-photo-slot') || button.parentElement;
        if (!slot) return null;
        var images = Array.prototype.slice.call(slot.querySelectorAll('img'));
        var selected = images.find(function (img) {
            return img.classList.contains('preview-image') && img.style.display !== 'none' && img.getAttribute('src') && img.getAttribute('src') !== '#';
        });
        if (!selected) {
            selected = images.find(function (img) {
                return !img.classList.contains('preview-image') && img.style.display !== 'none' && img.getAttribute('src') && img.getAttribute('src') !== '#';
            });
        }
        if (!selected || !(selected.currentSrc || selected.src)) return null;
        return { image: selected, src: selected.currentSrc || selected.src, alt: selected.alt || 'Zvětšená fotografie', button: button };
    }

    function showWeekPhotoAt(index) {
        if (!Array.isArray(weekPhotoList) || !weekPhotoList.length) return;
        weekPhotoIndex = ((Number(index) % weekPhotoList.length) + weekPhotoList.length) % weekPhotoList.length;
        var item = weekPhotoList[weekPhotoIndex];
        currentZoomFromGallery = false;
        currentZoomFromWeekPhotos = true;
        setPhotoNavigationVisible(weekPhotoList.length > 1);
        displayZoomSource(item.src, item.alt, 'Growreport · týden ' + item.week + ' · fotografie ' + (weekPhotoIndex + 1) + '/' + weekPhotoList.length, item.image);
    }

    window.openGalleryPhotoZoom = function (index) {
        try {
            if (!Array.isArray(galleryPhotoList) || !galleryPhotoList.length) throw new Error('V galerii nejsou žádné fotografie k procházení.');
            var parsedIndex = Number(index);
            if (!Number.isInteger(parsedIndex)) parsedIndex = 0;
            galleryPhotoIndex = ((parsedIndex % galleryPhotoList.length) + galleryPhotoList.length) % galleryPhotoList.length;
            currentZoomFromGallery = true;
            currentZoomFromWeekPhotos = false;
            weekPhotoIndex = -1;
            weekPhotoList = [];
            var item = galleryPhotoList[galleryPhotoIndex];
            if (!item || !item.file_path) throw new Error('Vybraná fotografie nemá platnou adresu.');
            setPhotoNavigationVisible(galleryPhotoList.length > 1);
            displayZoomSource(item.file_path, item.original_name, 'Galerie · ' + (galleryPhotoIndex + 1) + '/' + galleryPhotoList.length + ' · ' + item.original_name, null);
        } catch (error) {
            console.error('Procházení fotek galerie:', error);
            if (typeof setGalleryStatus === 'function') setGalleryStatus('DEBUG GALERIE – zvětšení fotky selhalo: ' + (error && error.message ? error.message : error), true);
            else alert('Fotografii se nepodařilo zvětšit: ' + (error && error.message ? error.message : error));
        }
    };

    window.previousGalleryPhoto = function () {
        if (currentZoomFromGallery && Array.isArray(galleryPhotoList) && galleryPhotoList.length) {
            window.openGalleryPhotoZoom(galleryPhotoIndex - 1);
            return;
        }
        if (currentZoomFromWeekPhotos && Array.isArray(weekPhotoList) && weekPhotoList.length) {
            showWeekPhotoAt(weekPhotoIndex - 1);
        }
    };
    window.nextGalleryPhoto = function () {
        if (currentZoomFromGallery && Array.isArray(galleryPhotoList) && galleryPhotoList.length) {
            window.openGalleryPhotoZoom(galleryPhotoIndex + 1);
            return;
        }
        if (currentZoomFromWeekPhotos && Array.isArray(weekPhotoList) && weekPhotoList.length) {
            showWeekPhotoAt(weekPhotoIndex + 1);
        }
    };

    window.openPhotoZoom = function (button) {
        try {
            if (!button) throw new Error('Tlačítko zvětšení fotografie nebylo nalezeno.');
            var current = resolvePhotoZoomImage(button);
            if (!current) throw new Error('V tomto políčku zatím není dostupná fotografie.');
            var weekRoot = button.closest('.growreport[data-week]');
            if (weekRoot) {
                var weekNumber = weekRoot.getAttribute('data-week') || '?';
                var zoomButtons = Array.prototype.slice.call(weekRoot.querySelectorAll('button.photo-zoom-btn'));
                weekPhotoList = [];
                zoomButtons.forEach(function (photoButton) {
                    var item = resolvePhotoZoomImage(photoButton);
                    if (item) {
                        item.week = weekNumber;
                        weekPhotoList.push(item);
                    }
                });
                weekPhotoIndex = weekPhotoList.findIndex(function (item) { return item.button === button; });
                if (weekPhotoIndex < 0) {
                    weekPhotoList.unshift(Object.assign({}, current, { week: weekNumber }));
                    weekPhotoIndex = 0;
                }
                currentZoomFromGallery = false;
                currentZoomFromWeekPhotos = true;
                galleryPhotoIndex = -1;
                showWeekPhotoAt(weekPhotoIndex);
                return;
            }
            currentZoomFromGallery = false;
            currentZoomFromWeekPhotos = false;
            weekPhotoList = [];
            weekPhotoIndex = -1;
            galleryPhotoIndex = -1;
            setPhotoNavigationVisible(false);
            displayZoomSource(current.src, current.alt, 'Fotografie – plné dostupné rozlišení', current.image);
        } catch (error) {
            console.error('Zvětšení fotografie:', error);
            alert('Fotografii se nepodařilo zvětšit: ' + (error && error.message ? error.message : error));
        }
    };

    window.closePhotoZoom = function () {
        if (!zoomModal) return;
        zoomModal.style.display = 'none';
        zoomModal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = previousBodyOverflow;
        if (zoomImage) {
            zoomImage.onload = null;
            zoomImage.onerror = null;
            zoomImage.removeAttribute('src');
        }
        if (zoomDimensions) zoomDimensions.textContent = '';
        currentZoomFromGallery = false;
        currentZoomFromWeekPhotos = false;
        galleryPhotoIndex = -1;
        weekPhotoIndex = -1;
        weekPhotoList = [];
        setPhotoNavigationVisible(false);
        if (zoomTitleText) zoomTitleText.textContent = 'Fotografie – plné dostupné rozlišení';
    };

    document.addEventListener('keydown', function (event) {
        if (!zoomModal || zoomModal.style.display !== 'flex') return;
        if (event.key === 'Escape') window.closePhotoZoom();
        else if ((currentZoomFromGallery || currentZoomFromWeekPhotos) && event.key === 'ArrowLeft') { event.preventDefault(); window.previousGalleryPhoto(); }
        else if ((currentZoomFromGallery || currentZoomFromWeekPhotos) && event.key === 'ArrowRight') { event.preventDefault(); window.nextGalleryPhoto(); }
    });
})();
</script>

<script>
/* Každý denní záznam se při najetí myší otevře jako samostatný, čitelnější plovoucí náhled. */
(function () {
    var preview = null;
    var activeCard = null;
    var hideTimer = null;

    function ensurePreview() {
        if (preview) return preview;
        preview = document.createElement('div');
        preview.className = 'daily-task-hover-preview';
        preview.setAttribute('role', 'note');
        preview.setAttribute('aria-live', 'polite');
        preview.addEventListener('mouseenter', function () {
            if (hideTimer) window.clearTimeout(hideTimer);
            hideTimer = null;
        });
        preview.addEventListener('mouseleave', function (event) {
            if (activeCard && activeCard.contains(event.relatedTarget)) return;
            scheduleHide();
        });
        preview.addEventListener('click', function (event) {
            var button = event.target && event.target.closest ? event.target.closest('.daily-task-complete-btn') : null;
            if (button) window.setTimeout(hidePreview, 120);
        });
        document.body.appendChild(preview);
        return preview;
    }

    function hidePreview() {
        if (hideTimer) window.clearTimeout(hideTimer);
        hideTimer = null;
        if (preview) {
            preview.style.display = 'none';
            preview.innerHTML = '';
        }
        activeCard = null;
    }

    function scheduleHide() {
        if (hideTimer) window.clearTimeout(hideTimer);
        hideTimer = window.setTimeout(function () {
            if (preview && preview.matches(':hover')) return;
            hidePreview();
        }, 180);
    }

    function showPreview(card) {
        if (!card || !document.body.contains(card)) return;
        if (hideTimer) window.clearTimeout(hideTimer);
        hideTimer = null;
        activeCard = card;
        var box = ensurePreview();
        var copy = card.cloneNode(true);
        copy.removeAttribute('id');
        copy.querySelectorAll('[id]').forEach(function (node) { node.removeAttribute('id'); });
        box.classList.toggle('completed', card.classList.contains('completed'));
        box.replaceChildren(copy);
        box.style.display = 'block';
        box.style.width = Math.min(420, Math.max(220, window.innerWidth - 24)) + 'px';

        var rect = card.getBoundingClientRect();
        var width = box.getBoundingClientRect().width;
        var left = Math.min(Math.max(12, rect.left), window.innerWidth - width - 12);
        box.style.left = Math.max(12, left) + 'px';
        box.style.top = '12px';
        requestAnimationFrame(function () {
            if (!box || box.style.display === 'none' || activeCard !== card) return;
            var boxHeight = box.getBoundingClientRect().height;
            var top = rect.bottom + 8;
            if (top + boxHeight > window.innerHeight - 10) top = rect.top - boxHeight - 8;
            top = Math.max(10, Math.min(top, window.innerHeight - boxHeight - 10));
            var finalLeft = Math.min(Math.max(12, rect.left), window.innerWidth - box.getBoundingClientRect().width - 12);
            box.style.left = Math.max(12, finalLeft) + 'px';
            box.style.top = top + 'px';
        });
    }

    document.addEventListener('mouseover', function (event) {
        var card = event.target && event.target.closest ? event.target.closest('.daily-task-card') : null;
        if (!card || (preview && preview.contains(card))) return;
        if (card === activeCard) {
            if (hideTimer) window.clearTimeout(hideTimer);
            hideTimer = null;
            return;
        }
        showPreview(card);
    });

    document.addEventListener('mouseout', function (event) {
        var card = event.target && event.target.closest ? event.target.closest('.daily-task-card') : null;
        if (!card || (preview && preview.contains(card))) return;
        if (card.contains(event.relatedTarget)) return;
        if (preview && preview.contains(event.relatedTarget)) return;
        scheduleHide();
    });

    document.addEventListener('focusin', function (event) {
        var card = event.target && event.target.closest ? event.target.closest('.daily-task-card') : null;
        if (card && !(preview && preview.contains(card))) showPreview(card);
    });

    document.addEventListener('focusout', function (event) {
        if (preview && preview.contains(event.relatedTarget)) return;
        scheduleHide();
    });

    window.addEventListener('resize', hidePreview);
    window.addEventListener('scroll', function (event) {
        if (preview && (event.target === document || event.target === document.body)) hidePreview();
    }, true);
})();
</script>


<script>
(function(){
'use strict';
const stages=[
 {key:'early_growth',name:'A – Raný růst',min:100,max:250,target:175,airMin:1,airMax:1.5},
 {key:'late_growth',name:'B – Pokročilý růst',min:200,max:500,target:350,airMin:1.5,airMax:2},
 {key:'early_flower',name:'C – Raný květ',min:300,max:700,target:500,airMin:1.5,airMax:2},
 {key:'late_flower',name:'D – Pokročilý květ',min:400,max:900,target:650,airMin:2,airMax:2.5}
];
let lights=[],fans=[];
const el=id=>document.getElementById(id), val=id=>Number(el(id)?.value||0);
const safe=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function renderLights(){el('audit-lights').innerHTML=lights.map((x,i)=>`<div style="border:1px solid #dbe5ec;border-radius:7px;padding:8px;margin:7px 0"><div style="display:flex;justify-content:space-between"><b>Světlo ${i+1}</b><button type="button" onclick="auditRemoveLight(${i})">Odebrat</button></div><label>Model / název <input data-l="${i}" data-k="name" value="${safe(x.name)}" style="width:100%;box-sizing:border-box"></label><label>Předvolba zařízení <select data-l="${i}" data-preset="1"><option value="">Vlastní údaje</option><option value="mars6500">Mars Hydro SP6500*</option><option value="omega">Omega Infinity 2.7 PRO</option><option value="se1000">Spider Farmer SE1000W</option><option value="sf2000">Spider Farmer SF2000</option><option value="sf1000">Spider Farmer SF1000</option><option value="vipar">VIPARSPECTRA KS5000</option><option value="acinf">AC Infinity EVO6</option></select></label><div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px"><label>Watty<input data-l="${i}" data-k="watts" type="number" min="1" value="${x.watts}" style="width:100%;box-sizing:border-box"></label><label>PPF µmol/s<input data-l="${i}" data-k="ppf" type="number" min="1" value="${x.ppf}" style="width:100%;box-sizing:border-box"></label><label>Účinnost µmol/J<input data-l="${i}" data-k="ppe" type="number" min=".1" step=".01" value="${x.ppe}" style="width:100%;box-sizing:border-box"></label><label>Počet kusů<input data-l="${i}" data-k="quantity" type="number" min="1" max="20" value="${x.quantity}" style="width:100%;box-sizing:border-box"></label><label>Vzdálenost cm<input data-l="${i}" data-k="distance" type="number" min="10" max="150" value="${x.distance}" style="width:100%;box-sizing:border-box"></label><label>Využití světla (0–1)<input data-l="${i}" data-k="coverage" type="number" min=".1" max="1" step=".05" value="${x.coverage}" style="width:100%;box-sizing:border-box"></label></div></div>`).join('');}
function renderFans(){el('audit-fans').innerHTML=fans.map((x,i)=>`<div style="border:1px solid #d8e8d8;border-radius:7px;padding:8px;margin:7px 0"><div style="display:flex;justify-content:space-between"><b>${safe(x.name)}</b><button type="button" onclick="auditRemoveFan(${i})">Odebrat</button></div><div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px"><label>Typ<select data-f="${i}" data-k="type"><option value="odtah" ${x.type==='odtah'?'selected':''}>Odtah</option><option value="privod" ${x.type==='privod'?'selected':''}>Přívod</option><option value="cirkulace" ${x.type==='cirkulace'?'selected':''}>Cirkulační</option></select></label><label>Watty<input data-f="${i}" data-k="watts" type="number" min="0" value="${x.watts}"></label><label>Průtok m³/h<input data-f="${i}" data-k="m3h" type="number" min="0" value="${x.m3h}"></label><label>CFM<input data-f="${i}" data-k="cfm" type="number" min="0" step=".1" value="${x.cfm}"></label><label>Max. rychlost vzduchu m/s<input data-f="${i}" data-k="ms" type="number" min="0" step=".1" value="${x.ms}"></label><label>Počet kusů<input data-f="${i}" data-k="quantity" type="number" min="1" max="20" value="${x.quantity}"></label></div></div>`).join('');}
el('audit-lights').addEventListener('input',e=>{let i=+e.target.dataset.l,k=e.target.dataset.k;if(k&&lights[i])lights[i][k]=k==='name'?e.target.value:Number(e.target.value);});
const presets={mars6500:{name:'Mars Hydro SP6500*',watts:220,ppf:1890,ppe:2.8},omega:{name:'Omega Infinity 2.7 PRO',watts:220,ppf:540,ppe:2.7},se1000:{name:'Spider Farmer SE1000W',watts:1000,ppf:2940,ppe:2.9},sf2000:{name:'Spider Farmer SF2000',watts:200,ppf:608.5,ppe:2.7},sf1000:{name:'Spider Farmer SF1000',watts:100,ppf:249.21,ppe:2.5},vipar:{name:'VIPARSPECTRA KS5000',watts:500,ppf:1200,ppe:2.4},acinf:{name:'AC Infinity EVO6',watts:500,ppf:1420,ppe:3.14}};
el('audit-lights').addEventListener('change',e=>{const i=Number(e.target.dataset.l),key=e.target.value,p=e.target.dataset.preset;if(p&&presets[key]&&lights[i]){Object.assign(lights[i],presets[key]);renderLights();}});

el('audit-fans').addEventListener('input',e=>{let i=+e.target.dataset.f,k=e.target.dataset.k;if(k&&fans[i])fans[i][k]=k==='type'?e.target.value:Number(e.target.value);});
el('audit-fans').addEventListener('change',e=>{const i=Number(e.target.dataset.f),k=e.target.dataset.k;if(!fans[i]||!k)return;if(k==='cfm'){fans[i].m3h=Number(e.target.value)*1.699;renderFans();}else if(k==='m3h'){fans[i].cfm=Number(e.target.value)/1.699;renderFans();}});

window.auditAddLight=()=>{lights.push({name:'Vlastní LED',watts:220,ppf:540,ppe:2.45,quantity:1,distance:40,coverage:.75});renderLights();};
window.auditAddFan=()=>{fans.push({name:'Větrák '+(fans.length+1),watts:40,m3h:300,cfm:176.6,ms:0,quantity:1,type:'odtah'});renderFans();};
window.auditRemoveLight=i=>{lights.splice(i,1);renderLights();};window.auditRemoveFan=i=>{fans.splice(i,1);renderFans();};
let auditLastUnit='cm';
window.auditUnitChanged=()=>{const next=el('audit-unit').value;if(next===auditLastUnit)return;const ratio=auditLastUnit==='cm'?1/2.54:2.54;['audit-length','audit-width','audit-height'].forEach(id=>{el(id).value=(Number(el(id).value||0)*ratio).toFixed(2);});auditLastUnit=next;};
function collect(){
 const unit=el('audit-unit').value,f=unit==='in'?.0254:.01;
 const dims={length:val('audit-length')*f,width:val('audit-width')*f,height:val('audit-height')*f};
 if(Object.values(dims).some(v=>!Number.isFinite(v)||v<=0))throw Error('Vyplň platnou délku, šířku a výšku prostoru.');
 if(!lights.length||!fans.length)throw Error('Přidej alespoň jedno světlo a jeden větrák.');
 lights.forEach((x,i)=>{if(x.watts<=0||x.ppf<=0||x.ppe<=0||x.quantity<1||x.distance<10||x.coverage<=0||x.coverage>1)throw Error('Zkontroluj údaje světla '+(i+1)+'.');});
 fans.forEach((x,i)=>{if(x.m3h<0||x.cfm<0||x.quantity<1||x.watts<0)throw Error('Zkontroluj údaje větráku '+(i+1)+'.');});
 return {unit,dimensions:dims,stage:el('audit-stage').value,speed_steps:el('audit-speeds').value,lights,fans};
}
function calculate(c){
 const area=c.dimensions.length*c.dimensions.width,volume=area*c.dimensions.height;
 const ppf=c.lights.reduce((a,x)=>a+x.ppf*x.quantity*x.coverage,0);
 const watts=c.lights.reduce((a,x)=>a+x.watts*x.quantity,0);
 const ppe=watts?c.lights.reduce((a,x)=>a+x.ppe*x.watts*x.quantity,0)/watts:0;
 const exhaust=c.fans.filter(x=>x.type==='odtah').reduce((a,x)=>a+x.m3h*x.quantity,0);
 const avgDistance=c.lights.reduce((a,x)=>a+x.distance*x.quantity,0)/c.lights.reduce((a,x)=>a+x.quantity,0);
 const phaseResults=stages.map(st=>{
  const ppfd=ppf/area,ratio=ppf?(st.target*area/ppf):1;
  const airMid=(st.airMin+st.airMax)/2,needed=volume*60/airMid;
  const level=exhaust?Math.min(100,needed/exhaust*100):100;
  const stepCount=c.speed_steps==='variable'?null:Number(c.speed_steps);
  const step=stepCount?Math.max(1,Math.min(stepCount,Math.ceil(level/100*stepCount))):null;
  const dist=ppfd>0?Math.max(10,Math.min(150,avgDistance*Math.sqrt(ppfd/st.target))):avgDistance;
  const warnings=[];
  if(ppfd<st.min)warnings.push('Světla jsou slabá pro doporučený rozsah této fáze.');
  if(ppfd>st.max)warnings.push('Světla jsou příliš silná pro doporučený rozsah této fáze.');
  if(exhaust<needed)warnings.push('Odtah nedosahuje orientační požadované kapacity.');
  return {key:st.key,name:st.name,ppfd_min:st.min,ppfd_max:st.max,ppfd_target:st.target,ppfd_estimated:ppfd,watts_current:watts,watts_recommended:watts*ratio,light_percent:Math.round(ratio*100),distance_cm:dist,airflow_required_m3h:needed,airflow_available_m3h:exhaust,fan_speed_percent:Math.round(level),fan_speed:step,warnings};
 });
 return {area_m2:area,volume_m3:volume,estimated_ppfd:ppf/area,total_watts:watts,total_ppf:ppf,weighted_ppe:ppe,airflow_available_m3h:exhaust,stages:phaseResults};
}
function render(r){
 el('audit-light-result').innerHTML=`<div style="background:#eef5fb;padding:9px;border-radius:7px"><b>Aktuální sestava</b><br>Odhad PPFD: <b>${Math.round(r.estimated_ppfd)} µmol/m²/s</b><br>Příkon: <b>${Math.round(r.total_watts)} W</b><br>PPF po korekci: ${Math.round(r.total_ppf)} µmol/s · PPE: ${r.weighted_ppe.toFixed(2)} µmol/J</div>`;
 el('audit-air-result').innerHTML=`<div style="background:#eef7ee;padding:9px;border-radius:7px"><b>Prostor</b><br>Objem ${r.volume_m3.toFixed(2)} m³ · plocha ${r.area_m2.toFixed(2)} m²<br>Odtah ${Math.round(r.airflow_available_m3h)} m³/h</div>`;
 el('audit-all-phases').innerHTML='<h3>Doporučení pro všechny čtyři fáze</h3>'+r.stages.map(x=>`<div style="border:1px solid #d5e0e8;border-radius:8px;padding:10px;margin:8px 0"><b>${x.name}</b><div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;margin-top:7px"><div>PPFD cíl ${x.ppfd_min}–${x.ppfd_max}</div><div>Odhad nyní <b>${Math.round(x.ppfd_estimated)}</b></div><div>Výkon světel <b>${Math.round(x.watts_recommended)} W</b> (${x.light_percent} %)</div><div>Vzdálenost přibližně <b>${Math.round(x.distance_cm)} cm</b></div><div>Potřeba vzduchu <b>${Math.round(x.airflow_required_m3h)} m³/h</b></div><div>Odtah ${Math.round(x.airflow_available_m3h)} m³/h · větrák ${x.fan_speed_percent}%${x.fan_speed?' / stupeň '+x.fan_speed:''}</div></div>${x.warnings.length?'<div style="color:#b71c1c;font-weight:600;margin-top:7px">⚠ '+x.warnings.map(safe).join('<br>⚠ ')+'</div>':'<div style="color:#2e7d32;margin-top:7px">✓ Odhad odpovídá nastavenému rozsahu.</div>'}</div>`).join('');
}
window.calculateGrowAudit=async()=>{try{const c=collect(),r=calculate(c);render(r);el('audit-message').textContent='Ukládám audit…';const d=await postJson('audit_save',{settings:c,results:r});if(!d.success)throw Error(d.message||'Uložení se nezdařilo.');el('audit-message').textContent='Audit byl vypočítán a uložen do SQL.';}catch(e){el('audit-message').textContent=e.message||String(e);}};
window.openGrowAudit=async()=>{el('grow-audit-dialog').style.display='flex';el('audit-message').textContent='Načítám…';if(!lights.length)window.auditAddLight();if(!fans.length)window.auditAddFan();try{const d=await postJson('audit_get',{});if(d.settings){const c=d.settings;lights=Array.isArray(c.lights)?c.lights:[];fans=Array.isArray(c.fans)?c.fans:[];renderLights();renderFans();el('audit-unit').value=c.unit||'cm';auditLastUnit=el('audit-unit').value;el('audit-stage').value=c.stage||'early_growth';el('audit-speeds').value=String(c.speed_steps||'10');const f=el('audit-unit').value==='in'?1/.0254:100;el('audit-length').value=(c.dimensions.length*f).toFixed(2);el('audit-width').value=(c.dimensions.width*f).toFixed(2);el('audit-height').value=(c.dimensions.height*f).toFixed(2);if(d.results)render(d.results);el('audit-message').textContent='Uložená data načtena.';}else el('audit-message').textContent='Vyplň zařízení a rozměry, poté stiskni OK.';}catch(e){el('audit-message').textContent='Načtení: '+e.message;}};
window.closeGrowAudit=()=>{el('grow-audit-dialog').style.display='none';};
window.deleteGrowAudit=async()=>{if(!confirm('Vymazat všechna uložená data auditu tohoto Growreportu?'))return;try{const d=await postJson('audit_delete',{});if(!d.success)throw Error(d.message||'Mazání selhalo.');lights=[];fans=[];renderLights();renderFans();el('audit-light-result').innerHTML='';el('audit-air-result').innerHTML='';el('audit-all-phases').innerHTML='';el('audit-message').textContent='Data auditu byla vymazána.';}catch(e){el('audit-message').textContent=e.message;}};
})();
</script>

</body>
</html>
