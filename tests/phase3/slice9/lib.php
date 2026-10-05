<?php
/** Helpers for the slice 9 proofs (docs/build-specs/reports-settings.md, "Proof"): the audit page, the exports and the home over a small world made through the handlers. */
require dirname(__DIR__) . '/slice8/lib.php';

/** Draft and post an entry through the handlers as the Controller; answers its id. */
function post_as_carl(string $date, array $lines, string $memo): int
{
    [$c, $b, $id] = draft(as_member(28), $date, $lines, $memo);
    if ($id === 0) { fwrite(STDERR, "draft failed: $c " . msg($b) . "\n"); }
    [$pc, $pb] = act(as_member(28), '/journal/post.php', ['entry' => $id]);
    if ($pc !== 200) { fwrite(STDERR, "post failed: $pc " . msg($pb) . "\n"); }
    return $id;
}
function line(string $account, string $dr, string $cr, ?string $desc = null): array { return ['account' => $account, 'debit' => $dr, 'credit' => $cr, 'description' => $desc]; }
