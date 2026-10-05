<?php
/**
 * Helpers for the slice 2 proofs (docs/build-specs/journal-core.md, "Proof"). Builds on the slice 1 library (members, act(), screen(), tokens,
 * post_entry() by SQL, balances). Adds: a multipart upload, a draft through the handler, the entry's JSON.
 */
require dirname(__DIR__) . '/slice1/lib.php';

/** A multipart POST (an upload) as a signed-on person: [status, decoded body, raw]. $files: field => [path, name]. */
function act_upload(string $jar, string $path, array $form, array $files, array $headers = []): array
{
    static $tok = [];
    $tok[$jar] ??= csrf_of(req('GET', '/', ['jar' => $jar])['body']);
    $fields = $form + ['csrf_token' => $tok[$jar]];
    foreach ($files as $field => [$file, $name]) { $fields[$field] = new CURLFile($file, mime_content_type($file) ?: 'application/octet-stream', $name); }
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => array_merge(JSONH, $headers), CURLOPT_TIMEOUT => 30]);
    $r = (string) curl_exec($ch);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($r, $hs);
    return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($body, true) ?? [], ['headers' => substr($r, 0, $hs), 'body' => $body]];
}
/** A draft through the handler as a person: [status, body, id]. $lines: rows for `lines` JSON. */
function draft(string $jar, string $date, array $lines, string $memo = 'SMOKE draft', array $extra = []): array
{
    [$c, $b] = act($jar, '/journal/save.php', ['entry_date' => $date, 'memo' => $memo, 'lines' => json_encode($lines)] + $extra);
    return [$c, $b, (int) ($b['record_id'] ?? 0)];
}
function entry_json(string $jar, int $id): array { return screen($jar, '/journal/' . $id)[1]; }
function entry_status(int $id): string { return (string) one('SELECT status FROM journal_entries WHERE id = :i', ['i' => $id]); }
function ensure_year(string $jar): void { if (one("SELECT count(*) FROM fiscal_years") == 0) { act($jar, '/periods/save.php', ['start' => '2026-01-01']); } }
/** A small PDF file on disk for the upload proofs. */
function tmp_pdf(string $name = 'SMOKE.pdf', int $bytes = 0): string
{
    $p = sys_get_temp_dir() . '/' . $name;
    $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\nxref\n0 4\n0000000000 65535 f \ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n0\n%%EOF\n";
    if ($bytes > strlen($pdf)) { $pdf .= str_repeat(' ', $bytes - strlen($pdf)); }
    file_put_contents($p, $pdf);
    return $p;
}
