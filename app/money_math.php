<?php
declare(strict_types=1);

/**
 * Exact two-decimal money arithmetic on numeric strings, in whole cents (no float, and no bcmath: the extension is not on every host).
 * m_add / m_sub / m_cmp / m_neg / m_abs; m_pct() gives a variance percentage to one decimal. Amounts are the strings the database returns ("1608.24", "-0.50").
 */
function m_cents(string|int|float|null $v): int
{
    $s = trim((string) ($v ?? '0'));
    if ($s === '') {
        return 0;
    }
    $neg = str_starts_with($s, '-');
    $s = ltrim($s, '+-');
    [$i, $f] = array_pad(explode('.', $s, 2), 2, '');
    $c = ((int) ($i === '' ? '0' : $i)) * 100 + (int) str_pad(substr($f, 0, 2), 2, '0');
    if (strlen($f) > 2 && (int) $f[2] >= 5) {
        $c++;
    }
    return $neg ? -$c : $c;
}

function m_str(int $cents): string
{
    return ($cents < 0 ? '-' : '') . intdiv(abs($cents), 100) . '.' . str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
}

function m_add(string|int|float|null $a, string|int|float|null $b): string { return m_str(m_cents($a) + m_cents($b)); }
function m_sub(string|int|float|null $a, string|int|float|null $b): string { return m_str(m_cents($a) - m_cents($b)); }
function m_neg(string|int|float|null $a): string { return m_str(-m_cents($a)); }
function m_cmp(string|int|float|null $a, string|int|float|null $b): int { return m_cents($a) <=> m_cents($b); }

/** The change from $prior to $amount as a percentage of |$prior| to one decimal ("12.5"), or null when the prior is zero. */
function m_pct(string $amount, string $prior): ?string
{
    $p = m_cents($prior);
    return $p === 0 ? null : number_format(((m_cents($amount) - $p) / abs($p)) * 100, 1, '.', '');
}
