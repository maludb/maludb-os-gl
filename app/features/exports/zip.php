<?php
declare(strict_types=1);

/** A ZIP of every export file for a range (slice 9): PHP's ZipArchive into a temp file the handler streams and deletes. */

function export_zip(PDO $pdo, array $range): string
{
    $path = tempnam(sys_get_temp_dir(), 'glexp');
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('The archive could not be made.');
    }
    foreach (array_keys(EXPORT_FILES) as $slug) {
        $zip->addFromString(export_filename($slug, $range), export_csv($pdo, $slug, $range));
    }
    $zip->close();
    return $path;
}
