<?php
//task#49999
use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\UI\Form\EntityEditorConfiguration;
use Bitrix\UI\Form\EntityEditorConfigScope;

global $USER;

/**
 * RESULT_MODIFIER
 * Purpose:
 * - Hide grid columns that are not present in the user's CRM "Details" form configuration
 * - Works for classic CRM lists and Smart Process (DYNAMIC_*) lists
 *
 * Fallbacks:
 * 1) Standard EntityEditorConfiguration load (scope + PERSONAL -> COMMON)
 * 2) Direct DB lookup in b_user_option (USER_ID=0, *_common)
 * 3) If categoryId unknown (0) -> pick nearest common config with category from DB
 * 4) Nearest user config (USER_ID=user) -> retry load
 *
 * Logging:
 * - Readable multiline logs
 */

Loader::includeModule('main');
Loader::includeModule('ui');
Loader::includeModule('crm');
Loader::includeModule('intranet');

// -----------------------------------------------------
// Logging (readable, multiline)
// -----------------------------------------------------
$logFile = $_SERVER['DOCUMENT_ROOT'] . '/local/logs/field-settings-log.txt';
$ENABLE_LOG = true;

if ($ENABLE_LOG) {
    $dir = dirname($logFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

$logBlock = static function(string $label, $data = null) use ($logFile, $ENABLE_LOG) {
    if (!$ENABLE_LOG) {
        return;
    }

    $out = date('c') . ' | ' . $label . PHP_EOL;

    if (is_array($data)) {
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $out .= $k . ':' . PHP_EOL;
                foreach ($v as $vv) {
                    $out .= '  - ' . (string)$vv . PHP_EOL;
                }
            } else {
                $out .= $k . ': ' . (string)$v . PHP_EOL;
            }
        }
    } elseif ($data !== null) {
        $out .= (string)$data . PHP_EOL;
    }

    $out .= str_repeat('-', 60) . PHP_EOL;
    file_put_contents($logFile, $out, FILE_APPEND | LOCK_EX);
};

$logFields = static function(string $label, array $fields) use ($logFile, $ENABLE_LOG) {
    if (!$ENABLE_LOG) {
        return;
    }

    $fields = array_values($fields);

    $out  = date('c') . ' | ' . $label . ' | count=' . count($fields) . PHP_EOL;
    $out .= implode(PHP_EOL, $fields) . PHP_EOL;
    $out .= str_repeat('-', 60) . PHP_EOL;

    file_put_contents($logFile, $out, FILE_APPEND | LOCK_EX);
};

// -----------------------------------------------------
// Preconditions: require columns array in arResult
// -----------------------------------------------------
if (empty($arResult['COLUMNS_ALL_WITH_SECTIONS']) || !is_array($arResult['COLUMNS_ALL_WITH_SECTIONS'])) {
    return;
}

// -----------------------------------------------------
// User check + admin bypass
// -----------------------------------------------------
$userId = (int)($USER ? $USER->GetID() : 0);
if ($userId <= 0) {
    return;
}

$groups  = \CUser::GetUserGroup($userId);
$isAdmin = in_array(1, $groups); // MUST be non-strict
if ($isAdmin) {
    return;
}

// -----------------------------------------------------
// Resolve entityTypeId / categoryId from GRID_ID (or arParams if present)
// -----------------------------------------------------
$gridId = (string)($arResult['GRID_ID'] ?? '');

$entityTypeId = (int)($arParams['ENTITY_TYPE_ID'] ?? 0);
$categoryId   = (int)($arParams['CATEGORY_ID'] ?? $arParams['categoryId'] ?? 0);

if ($entityTypeId <= 0) {
    // SPA grid: crm-type-item-list-168 or crm-type-item-list-168-12
    if (preg_match('~^crm-type-item-list-(\d+)(?:-(\d+))?~i', $gridId, $m)) {
        $entityTypeId = (int)$m[1];
        $categoryId   = (int)($m[2] ?? 0);
    }
    // Classic CRM grids
    elseif (preg_match('~^CRM_(LEAD|DEAL|CONTACT|COMPANY|QUOTE)_LIST~i', $gridId, $m)) {
        $map = [
            'LEAD'    => 1,
            'DEAL'    => 2,
            'CONTACT' => 3,
            'COMPANY' => 4,
            'QUOTE'   => 7,
        ];
        $entityTypeId = (int)($map[strtoupper((string)$m[1])] ?? 0);
    }
}

if ($entityTypeId <= 0) {
    return;
}

// -----------------------------------------------------
// Helpers: baseName + configId
// -----------------------------------------------------
$getBaseName = static function(int $entityTypeId): string {
    switch ($entityTypeId) {
        case 1: return 'lead';
        case 2: return 'deal';
        case 3: return 'contact';
        case 4: return 'company';
        case 7: return 'QUOTE'; // intentionally uppercase
        default:
            return 'DYNAMIC_' . $entityTypeId;
    }
};

$baseName = $getBaseName($entityTypeId);
$configId = ($categoryId > 0)
    ? "{$baseName}_details_C{$categoryId}"
    : "{$baseName}_details";

$logBlock('CONTEXT', [
    'userId' => $userId,
    'gridId' => $gridId,
    'entityTypeId' => $entityTypeId,
    'categoryId' => $categoryId,
    'baseName' => $baseName,
    'configId_initial' => $configId,
]);

// -----------------------------------------------------
// Helper: robust extract ALL column IDs from arResult
// -----------------------------------------------------
$extractColumnIds = static function($columnsAny): array {
    $ids = [];

    $pushId = static function($id) use (&$ids) {
        $id = trim((string)$id);
        if ($id !== '') {
            $ids[] = $id;
        }
    };

    $isColumn = static function($arr): bool {
        return (is_array($arr) && isset($arr['id']) && $arr['id'] !== '');
    };

    $walk = static function($node) use (&$walk, $isColumn, $pushId) {
        if (!is_array($node)) {
            return;
        }
        if ($isColumn($node)) {
            $pushId($node['id']);
            return;
        }
        foreach ($node as $v) {
            if (is_array($v)) {
                $walk($v);
            }
        }
    };

    if (is_array($columnsAny) && isset($columnsAny[0]) && is_array($columnsAny[0]) && $isColumn($columnsAny[0])) {
        foreach ($columnsAny as $col) {
            if ($isColumn($col)) {
                $pushId($col['id']);
            }
        }
    } else {
        $walk($columnsAny);
    }

    $ids = array_values(array_unique($ids));
    sort($ids, SORT_STRING);
    return $ids;
};

$extractArResultColumnIds = static function(array $arResult) use ($extractColumnIds): array {
    $ids = [];

    if (isset($arResult['COLUMNS_ALL_WITH_SECTIONS'])) {
        $ids = array_merge($ids, $extractColumnIds($arResult['COLUMNS_ALL_WITH_SECTIONS']));
    }
    if (!empty($arResult['COLUMNS_ALL']) && is_array($arResult['COLUMNS_ALL'])) {
        $ids = array_merge($ids, $extractColumnIds($arResult['COLUMNS_ALL']));
    }

    $ids = array_values(array_unique($ids));
    sort($ids, SORT_STRING);
    return $ids;
};

$logFields('ARRESULT_FIELDS_BEFORE', $extractArResultColumnIds($arResult));

// -----------------------------------------------------
// DB helpers (b_user_option)
// -----------------------------------------------------
$connection = Application::getConnection();
$sqlHelper  = $connection->getSqlHelper();

$fetchOptionValue = static function(int $userIdRow, string $name) use ($connection, $sqlHelper) {
    $cat  = $sqlHelper->forSql('crm.entity.editor');
    $name = $sqlHelper->forSql($name);

    $sql = "
        SELECT VALUE
        FROM b_user_option
        WHERE CATEGORY = '{$cat}'
          AND USER_ID = " . (int)$userIdRow . "
          AND NAME = '{$name}'
        ORDER BY ID DESC
        LIMIT 1
    ";

    $row = $connection->query($sql)->fetch();
    return $row ? (string)$row['VALUE'] : null;
};

$unserializeValue = static function($value) {
    if ($value === null || $value === '') {
        return null;
    }

    // In Bitrix, VALUE is usually serialized array
    if (is_array($value)) {
        return $value;
    }

    $value = (string)$value;

    // suppress warnings on bad data
    $tmp = @unserialize($value);
    if (is_array($tmp)) {
        return $tmp;
    }

    // sometimes Bitrix stores JSON (rare), try decode
    $json = json_decode($value, true);
    if (is_array($json)) {
        return $json;
    }

    return null;
};

$stripCommonSuffix = static function(string $name): string {
    return preg_replace('/_common$/i', '', $name);
};

$buildCommonNameCandidates = static function(string $baseName, int $categoryId) {
    // We intentionally try multiple patterns because Bitrix portals differ:
    // - deal_details_C5_common
    // - deal_details_c_5_common
    // - DYNAMIC_161_details_C9_common
    // - DYNAMIC_161_details_c_9_common
    $cands = [];

    if ($categoryId > 0) {
        $cands[] = "{$baseName}_details_C{$categoryId}_common";
        $cands[] = "{$baseName}_details_c_{$categoryId}_common";
    } else {
        $cands[] = "{$baseName}_details_common";
    }

    // Extra for safety (some portals store lowercase baseName for classic entities)
    $lower = strtolower($baseName);
    if ($lower !== $baseName) {
        if ($categoryId > 0) {
            $cands[] = "{$lower}_details_C{$categoryId}_common";
            $cands[] = "{$lower}_details_c_{$categoryId}_common";
        } else {
            $cands[] = "{$lower}_details_common";
        }
    }

    // Unique
    $cands = array_values(array_unique($cands));
    return $cands;
};

$findNearestCommonNameWithCategory = static function(string $baseName) use ($connection, $sqlHelper) {
    $cat = $sqlHelper->forSql('crm.entity.editor');
    $p1  = $sqlHelper->forSql($baseName . '_details_C%_common');
    $p2  = $sqlHelper->forSql($baseName . '_details_c\_%\_common'); // escaped underscore in LIKE is tricky; we keep broad below

    // Use broad LIKEs; order by ID desc = "nearest / latest"
    $sql = "
        SELECT NAME
        FROM b_user_option
        WHERE CATEGORY = '{$cat}'
          AND USER_ID = 0
          AND (
            NAME LIKE '{$p1}'
            OR NAME LIKE '" . $sqlHelper->forSql($baseName . "_details_c_%_common") . "'
          )
        ORDER BY ID DESC
        LIMIT 1
    ";

    $row = $connection->query($sql)->fetch();
    return $row ? (string)$row['NAME'] : null;
};

$findNearestUserConfigName = static function(int $userId, string $baseName) use ($connection, $sqlHelper) {
    $cat  = $sqlHelper->forSql('crm.entity.editor');
    $like = $sqlHelper->forSql($baseName . '_details%');

    // Prefer user-specific configs; fallback to common; pick latest ID
    $sql = "
        SELECT NAME, USER_ID
        FROM b_user_option
        WHERE CATEGORY = '{$cat}'
          AND (USER_ID = " . (int)$userId . " OR USER_ID = 0)
          AND NAME LIKE '{$like}'
        ORDER BY (USER_ID = " . (int)$userId . ") DESC, ID DESC
        LIMIT 1
    ";

    $row = $connection->query($sql)->fetch();
    return $row ? ['NAME' => (string)$row['NAME'], 'USER_ID' => (int)$row['USER_ID']] : null;
};

// -----------------------------------------------------
// Load config via EntityEditorConfiguration + scope resolution
// + DB fallbacks (same idea as ajax.php)
// -----------------------------------------------------
$loadConfigViaEntityEditor = static function(int $userId, string $configId, &$debug) {
    $categoryName  = 'crm.entity.editor';
    $configuration = new EntityEditorConfiguration($categoryName, $userId);

    $storedScope = $configuration->getScope($configId);

    $configScope = EntityEditorConfigScope::UNDEFINED;
    $userScopeId = 0;

    if (is_array($storedScope)) {
        $configScope = (string)($storedScope['scope'] ?? EntityEditorConfigScope::UNDEFINED);
        $userScopeId = (int)($storedScope['userScopeId'] ?? 0);
    } else {
        $configScope = (string)$storedScope;
    }

    $debug[] = "EntityEditor getScope: " . (is_array($storedScope) ? ('array(scope=' . ($configScope ?: 'n/a') . ', userScopeId=' . $userScopeId . ')') : (string)$storedScope);
    $debug[] = "Resolved scope: {$configScope}, userScopeId={$userScopeId}";

    $config = null;

    // CUSTOM scope resolve
    if ($configScope === EntityEditorConfigScope::CUSTOM && class_exists('\Bitrix\Ui\EntityForm\Scope')) {
        if ($userScopeId > 0) {
            $config = \Bitrix\Ui\EntityForm\Scope::getInstance($userId)->getScopeById($userScopeId);
        }
        if (!$config) {
            $debug[] = "CUSTOM scope resolve failed -> fallback UNDEFINED";
            $configScope = EntityEditorConfigScope::UNDEFINED;
        }
    }

    if (!$config && EntityEditorConfigScope::isDefined($configScope)) {
        $config = $configuration->get($configId, $configScope);
        $debug[] = "EntityEditor get(configId, scope) -> " . (is_array($config) ? 'array' : 'null');
    }

    if (!$config) {
        $config = $configuration->get($configId, EntityEditorConfigScope::PERSONAL);
        $debug[] = "EntityEditor get(configId, PERSONAL) -> " . (is_array($config) ? 'array' : 'null');

        if (!is_array($config) || empty($config)) {
            $config = $configuration->get($configId, EntityEditorConfigScope::COMMON);
            $debug[] = "EntityEditor get(configId, COMMON) -> " . (is_array($config) ? 'array' : 'null');
        }
    }

    if (!is_array($config) || empty($config)) {
        return null;
    }

    return $config;
};

$debugLoad = [];
$resolvedConfigId = $configId;

// 1) standard load
$config = $loadConfigViaEntityEditor($userId, $resolvedConfigId, $debugLoad);
$configArray = (is_array($config) ? ($config['CONFIG'] ?? $config) : null);

// 2) DB common for exact configId/category
if (!is_array($configArray) || empty($configArray)) {
    $debugLoad[] = "Standard load returned empty -> try DB common *_common";

    $cands = $buildCommonNameCandidates($baseName, $categoryId);
    $debugLoad[] = "DB candidates: " . implode(', ', $cands);

    foreach ($cands as $nameCand) {
        $raw = $fetchOptionValue(0, $nameCand);
        $arr = $unserializeValue($raw);

        if (is_array($arr) && !empty($arr)) {
            $debugLoad[] = "DB HIT: USER_ID=0 NAME={$nameCand}";
            $configArray = ($arr['CONFIG'] ?? $arr);
            break;
        }
    }
}

// 3) If categoryId unknown -> pick nearest common config with category and retry
if ((!is_array($configArray) || empty($configArray)) && $categoryId <= 0) {
    $debugLoad[] = "categoryId=0 and still empty -> find nearest common config with category";

    $nearestCommonName = $findNearestCommonNameWithCategory($baseName);
    if ($nearestCommonName) {
        $debugLoad[] = "Nearest common NAME: {$nearestCommonName}";

        // Try API load by converting NAME -> configId
        $altConfigId = $stripCommonSuffix($nearestCommonName);
        $resolvedConfigId = $altConfigId;

        $debugLoad[] = "Retry standard load with altConfigId={$altConfigId}";
        $config = $loadConfigViaEntityEditor($userId, $altConfigId, $debugLoad);
        $configArray = (is_array($config) ? ($config['CONFIG'] ?? $config) : null);

        // If still empty, use DB value directly (we already have NAME)
        if (!is_array($configArray) || empty($configArray)) {
            $raw = $fetchOptionValue(0, $nearestCommonName);
            $arr = $unserializeValue($raw);
            if (is_array($arr) && !empty($arr)) {
                $debugLoad[] = "DB HIT (nearest): USER_ID=0 NAME={$nearestCommonName}";
                $configArray = ($arr['CONFIG'] ?? $arr);
            }
        }
    } else {
        $debugLoad[] = "Nearest common with category: NOT FOUND";
    }
}

// 4) Nearest user config (USER_ID=user) and retry
if (!is_array($configArray) || empty($configArray)) {
    $debugLoad[] = "Still empty -> find nearest user config in b_user_option and retry";

    $nearest = $findNearestUserConfigName($userId, $baseName);
    if ($nearest && !empty($nearest['NAME'])) {
        $debugLoad[] = "Nearest option: USER_ID={$nearest['USER_ID']} NAME={$nearest['NAME']}";

        // Convert to configId and retry API
        $altConfigId = $stripCommonSuffix((string)$nearest['NAME']);
        $resolvedConfigId = $altConfigId;

        $debugLoad[] = "Retry standard load with altConfigId={$altConfigId}";
        $config = $loadConfigViaEntityEditor($userId, $altConfigId, $debugLoad);
        $configArray = (is_array($config) ? ($config['CONFIG'] ?? $config) : null);

        // If still empty, try DB directly with that NAME (both USER_ID=user and USER_ID=0)
        if (!is_array($configArray) || empty($configArray)) {
            $raw = $fetchOptionValue((int)$nearest['USER_ID'], (string)$nearest['NAME']);
            $arr = $unserializeValue($raw);
            if (is_array($arr) && !empty($arr)) {
                $debugLoad[] = "DB HIT (nearest): USER_ID={$nearest['USER_ID']} NAME={$nearest['NAME']}";
                $configArray = ($arr['CONFIG'] ?? $arr);
            }
        }
    } else {
        $debugLoad[] = "Nearest user config: NOT FOUND";
    }
}

$logBlock('CONFIG_LOAD_DEBUG', $debugLoad);

// -----------------------------------------------------
// Extract allowed field codes from config
// -----------------------------------------------------
$allowedCodes = [];

if (is_array($configArray) && !empty($configArray)) {
    $stack = [$configArray];

    while (!empty($stack)) {
        $arr = array_pop($stack);
        if (!is_array($arr)) {
            continue;
        }

        foreach ($arr as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (!empty($item['elements']) && is_array($item['elements'])) {
                $stack[] = $item['elements'];
            }

            $name = trim((string)($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $type = (string)($item['type'] ?? '');
            if (in_array($type, ['column', 'section'], true)) {
                continue;
            }

            $allowedCodes[] = $name;
        }
    }
}

$allowedCodes = array_values(array_unique($allowedCodes));

// Alias: *_ID -> * (non-UF only)
foreach ($allowedCodes as $code) {
    if (stripos($code, 'UF_') === 0) {
        continue;
    }
    if (preg_match('/_ID$/', $code)) {
        $alias = preg_replace('/_ID$/', '', $code);
        if ($alias && !in_array($alias, $allowedCodes, true)) {
            $allowedCodes[] = $alias;
        }
    }
}

// Special aliases (Bitrix naming mismatch)
$specialAliases = [
    'OBSERVER'  => 'OBSERVERS',
    'OBSERVERS' => 'OBSERVER',
];
foreach ($specialAliases as $from => $to) {
    if (in_array($from, $allowedCodes, true) && !in_array($to, $allowedCodes, true)) {
        $allowedCodes[] = $to;
    }
}

// Always keep ID
if (!in_array('ID', $allowedCodes, true)) {
    $allowedCodes[] = 'ID';
}

$allowedCodes = array_values(array_unique($allowedCodes));

$logBlock('CONFIG_RESOLVED', [
    'configId_used' => $resolvedConfigId,
    'allowedCodesCount' => count($allowedCodes),
]);

$logFields('CONFIG_FIELDS_DB', $allowedCodes);

// -----------------------------------------------------
// If config empty -> hide everything
// -----------------------------------------------------
if (empty($allowedCodes)) {
    $arResult['COLUMNS_ALL_WITH_SECTIONS'] = [];
    if (isset($arResult['COLUMNS_ALL']) && is_array($arResult['COLUMNS_ALL'])) {
        $arResult['COLUMNS_ALL'] = [];
    }
    $logFields('ARRESULT_FIELDS_AFTER', []);
    return;
}

// -----------------------------------------------------
// Build allowed set (UPPERCASE) for fast checks
// -----------------------------------------------------
$allowedSet = [];
foreach ($allowedCodes as $c) {
    $c = strtoupper(trim((string)$c));
    if ($c !== '') {
        $allowedSet[$c] = true;
    }
}

// -----------------------------------------------------
// Sorting helper: A–Z by column title/name
// -----------------------------------------------------
$sortCols = static function(array $cols): array {
    usort($cols, static function($a, $b) {
        $tA = trim((string)($a['name'] ?? $a['original_name'] ?? $a['id'] ?? ''));
        $tB = trim((string)($b['name'] ?? $b['original_name'] ?? $b['id'] ?? ''));
        return strcasecmp($tA, $tB);
    });
    return $cols;
};

// -----------------------------------------------------
// Filter COLUMNS_ALL_WITH_SECTIONS + sort + drop empty sections
// -----------------------------------------------------
foreach ($arResult['COLUMNS_ALL_WITH_SECTIONS'] as $sectionKey => $cols) {
    if (!is_array($cols)) {
        continue;
    }

    $filtered = [];
    foreach ($cols as $col) {
        if (!is_array($col)) {
            continue;
        }

        $id = strtoupper(trim((string)($col['id'] ?? '')));
        if ($id !== '' && isset($allowedSet[$id])) {
            $filtered[] = $col;
        }
    }

    $arResult['COLUMNS_ALL_WITH_SECTIONS'][$sectionKey] = $sortCols($filtered);
}

// Drop empty sections
foreach ($arResult['COLUMNS_ALL_WITH_SECTIONS'] as $sectionKey => $cols) {
    if (is_array($cols) && empty($cols)) {
        unset($arResult['COLUMNS_ALL_WITH_SECTIONS'][$sectionKey]);
    }
}

// -----------------------------------------------------
// Filter COLUMNS_ALL (flat) + sort
// -----------------------------------------------------
if (isset($arResult['COLUMNS_ALL']) && is_array($arResult['COLUMNS_ALL'])) {
    $flat = array_values(array_filter(
        $arResult['COLUMNS_ALL'],
        static function($col) use ($allowedSet) {
            if (!is_array($col)) {
                return false;
            }
            $id = strtoupper(trim((string)($col['id'] ?? '')));
            return ($id !== '' && isset($allowedSet[$id]));
        }
    ));

    $arResult['COLUMNS_ALL'] = $sortCols($flat);
}

$logFields('ARRESULT_FIELDS_AFTER', $extractArResultColumnIds($arResult));
