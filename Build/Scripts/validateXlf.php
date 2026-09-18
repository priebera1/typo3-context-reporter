<?php

declare(strict_types=1);

/*
 * Validates the XLIFF files of the extension:
 *
 * - every file is well-formed XML with the structure TYPO3 expects,
 * - trans-unit ids are unique within a file,
 * - every translation (de.*.xlf) has exactly the ids of its English source,
 * - translations carry the <target> element TYPO3 reads.
 *
 * Run through Build/Scripts/runTests.sh -s xlf.
 */

$root = dirname(__DIR__, 2);
$directory = $root . '/Resources/Private/Language';
$errors = [];

/**
 * @return array<string, string> trans-unit id => target (or source for the English files)
 */
$readUnits = static function (string $file) use (&$errors): array {
    $previous = libxml_use_internal_errors(true);
    $document = simplexml_load_file($file);
    $libxmlErrors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if ($document === false) {
        foreach ($libxmlErrors as $error) {
            $errors[] = sprintf('%s: not well-formed (line %d): %s', basename($file), $error->line, trim($error->message));
        }
        return [];
    }
    if (!isset($document->file->body)) {
        $errors[] = sprintf('%s: missing <file><body>.', basename($file));
        return [];
    }
    $isTranslation = str_starts_with(basename($file), 'de.');
    if ((string)($document->file['source-language'] ?? '') !== 'en') {
        $errors[] = sprintf('%s: source-language must be "en".', basename($file));
    }
    $targetLanguage = (string)($document->file['target-language'] ?? '');
    if ($isTranslation && $targetLanguage !== 'de') {
        $errors[] = sprintf('%s: target-language must be "de".', basename($file));
    }
    if (!$isTranslation && $targetLanguage !== '') {
        $errors[] = sprintf('%s: the source file must not declare a target-language.', basename($file));
    }

    $units = [];
    foreach ($document->file->body->{'trans-unit'} as $unit) {
        $id = (string)($unit['id'] ?? '');
        if ($id === '') {
            $errors[] = sprintf('%s: a trans-unit has no id.', basename($file));
            continue;
        }
        if (array_key_exists($id, $units)) {
            $errors[] = sprintf('%s: duplicate trans-unit id "%s".', basename($file), $id);
            continue;
        }
        if ((string)($unit->source ?? '') === '') {
            $errors[] = sprintf('%s: trans-unit "%s" has an empty <source>.', basename($file), $id);
        }
        if ($isTranslation && !isset($unit->target)) {
            $errors[] = sprintf('%s: trans-unit "%s" has no <target>.', basename($file), $id);
        }
        $units[$id] = $isTranslation ? (string)($unit->target ?? '') : (string)($unit->source ?? '');
    }
    return $units;
};

$files = glob($directory . '/*.xlf') ?: [];
sort($files);
if ($files === []) {
    fwrite(STDERR, 'No XLIFF files found in ' . $directory . PHP_EOL);
    exit(1);
}

$sources = [];
$translations = [];
foreach ($files as $file) {
    $name = basename($file);
    $units = $readUnits($file);
    if (str_starts_with($name, 'de.')) {
        $translations[substr($name, 3)] = $units;
    } else {
        $sources[$name] = $units;
    }
}

foreach ($sources as $name => $units) {
    if (!array_key_exists($name, $translations)) {
        $errors[] = sprintf('%s: no German translation (de.%s).', $name, $name);
        continue;
    }
    $missing = array_diff(array_keys($units), array_keys($translations[$name]));
    $surplus = array_diff(array_keys($translations[$name]), array_keys($units));
    foreach ($missing as $id) {
        $errors[] = sprintf('de.%s: missing trans-unit "%s".', $name, $id);
    }
    foreach ($surplus as $id) {
        $errors[] = sprintf('de.%s: trans-unit "%s" does not exist in %s.', $name, $id, $name);
    }
}
foreach (array_keys($translations) as $name) {
    if (!array_key_exists($name, $sources)) {
        $errors[] = sprintf('de.%s: there is no source file %s.', $name, $name);
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

printf("%d XLIFF files are valid (%d labels).\n", count($files), array_sum(array_map('count', $sources)));
