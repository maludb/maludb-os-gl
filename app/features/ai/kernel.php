<?php
declare(strict_types=1);

/**
 * The kernel's AI statement over its internal API (kernel A5): `GET {OS_INTERNAL_URL}/api/v1/ledger/periods.php` lists the months and their status (os.ledger-periods/1);
 * `?period=YYYY-MM` is one month's document (os.ledger-period/1) — both with the application's token (OS_APPLICATION_TOKEN) through auth.php's kernel_call().
 * A failure is an AiKernelError carrying the HTTP status the screen answers and the sentence it says.
 */

final class AiKernelError extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 502)
    {
        parent::__construct($message);
    }
}

/** The answer of one call, or an AiKernelError in words: unreachable / 5xx → "The OS did not answer: …"; 401 and 403 → "The application token is not accepted by the OS"; 4xx → the kernel's own message. */
function ai_kernel_get(string $query): array
{
    $answer = kernel_call('GET', '/api/v1/ledger/periods.php' . $query);
    if ($answer === null) {
        throw new AiKernelError('The OS did not answer: it could not be reached (check OS_INTERNAL_URL and OS_APPLICATION_TOKEN).');
    }
    if (in_array($answer['status'], [401, 403], true)) {
        throw new AiKernelError('The application token is not accepted by the OS.');
    }
    if ($answer['status'] >= 500 || $answer['body'] === null) {
        throw new AiKernelError('The OS did not answer: it replied HTTP ' . $answer['status'] . ($answer['body'] === null ? ' with something that is not JSON' : '') . '.');
    }
    if ($answer['status'] >= 400) {
        throw new AiKernelError('The OS refused: ' . (string) ($answer['body']['error']['message'] ?? 'HTTP ' . $answer['status']) . '.', 422);
    }
    return $answer['body'];
}

/** The months the kernel lists: [{period, period_start, period_end, status, closed_at, statement_lines, ledger_calls}], newest first. */
function pull_ai_periods(): array
{
    $doc = ai_kernel_get('');
    if (($doc['schema'] ?? '') !== 'os.ledger-periods/1' || !is_array($doc['periods'] ?? null)) {
        throw new AiKernelError('The OS answered with something that is not os.ledger-periods/1.');
    }
    $rows = array_values(array_filter($doc['periods'], static fn ($p): bool => is_array($p) && preg_match('/^[0-9]{4}-[0-9]{2}$/', (string) ($p['period'] ?? '')) === 1));
    usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['period'], (string) $a['period']));
    return $rows;
}

/** One month's document (os.ledger-period/1). */
function pull_ai_period(string $period): array
{
    if (!preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $period)) {
        throw new AiKernelError('A period is written YYYY-MM.', 422);
    }
    $doc = ai_kernel_get('?period=' . rawurlencode($period));
    if (($doc['schema'] ?? '') !== 'os.ledger-period/1') {
        throw new AiKernelError('The OS answered with something that is not os.ledger-period/1.');
    }
    return $doc;
}
