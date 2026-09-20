<?php
// Categories and folders live in the encrypted system_config record. Read the vault once
// before normalizing records, because normalization removes system_config rows.
$vaultCategories = [];
$vaultFolders = [];
$rawVaultRecords = is_array($passwords ?? null) ? $passwords : [];

foreach ($rawVaultRecords as $vaultConfigRow) {
    if (($vaultConfigRow['type'] ?? '') !== 'system_config') continue;
    if (is_array($vaultConfigRow['categories'] ?? null)) {
        $vaultCategories = array_values(array_unique(array_filter(array_map(static fn($value): string => trim((string)$value), $vaultConfigRow['categories']), static fn(string $value): bool => $value !== '')));
    }
    if (is_array($vaultConfigRow['folders'] ?? null)) {
        foreach ($vaultConfigRow['folders'] as $folderCategory => $folderList) {
            if (!is_string($folderCategory) || !is_array($folderList)) continue;
            $cleanFolders = array_values(array_unique(array_filter(array_map(static fn($value): string => trim((string)$value), $folderList), static fn(string $value): bool => $value !== '')));
            if ($cleanFolders !== []) $vaultFolders[$folderCategory] = $cleanFolders;
        }
    }
    break;
}
if ($vaultCategories === [] && is_array($systemConfig ?? null) && is_array($systemConfig['categories'] ?? null)) {
    $vaultCategories = array_values(array_unique(array_filter(array_map(static fn($value): string => trim((string)$value), $systemConfig['categories']), static fn(string $value): bool => $value !== '')));
}
if ($vaultFolders === [] && is_array($systemConfig ?? null) && is_array($systemConfig['folders'] ?? null)) {
    foreach ($systemConfig['folders'] as $folderCategory => $folderList) {
        if (!is_string($folderCategory) || !is_array($folderList)) continue;
        $cleanFolders = array_values(array_unique(array_filter(array_map(static fn($value): string => trim((string)$value), $folderList), static fn(string $value): bool => $value !== '')));
        if ($cleanFolders !== []) $vaultFolders[$folderCategory] = $cleanFolders;
    }
}

// Keep the raw record location metadata because the secure runtime's normalizer may
// intentionally expose only the fields needed by the dashboard. Folder assignment is
// still authoritative in the encrypted record, so restore it by record ID before filtering.
$rawLocationById = [];
foreach ($rawVaultRecords as $rawRow) {
    if (!is_array($rawRow) || ($rawRow['type'] ?? '') === 'system_config') continue;
    $rawId = trim((string)($rawRow['id'] ?? ''));
    if ($rawId === '') continue;
    $rawLocationById[$rawId] = [
        'category' => trim((string)($rawRow['category'] ?? '')),
        'folder' => trim((string)($rawRow['folder'] ?? '')),
    ];
}

$passwords = normalize_vault_records($rawVaultRecords);
$passwords = array_values(array_filter($passwords, static fn(array $row): bool => ($row['type'] ?? '') !== 'system_config'));
foreach ($passwords as $index => $row) {
    $rowId = trim((string)($row['id'] ?? ''));
    if ($rowId !== '' && isset($rawLocationById[$rowId])) {
        $location = $rawLocationById[$rowId];
        if ($location['category'] !== '') $row['category'] = $location['category'];
        $row['folder'] = $location['folder'];
        $passwords[$index] = $row;
    }
}
$activeVaultView = trim((string)($_GET['vault_view'] ?? 'records'));
$activeVaultFolder = trim((string)($_GET['vault_folder'] ?? ''));
if ($activeVaultView !== 'records' && !in_array($activeVaultView, $vaultCategories, true)) $activeVaultView = 'records';
