<?php
declare(strict_types=1);

/** Recurring templates' writes (slice 8). The shape is validated by validate_template() (templates.php); the schedule's arithmetic is the database's (gl_recurring_next / gl_recurring_advance). */

/** Add ($id null) or change a template. $f: name, kind, cadence, every_n, next_date, end_date, active. $template is already validated. Answers the id. */
function save_template(PDO $pdo, ?int $id, array $f, array $template, int $by): int
{
    $dupe = $pdo->prepare('SELECT 1 FROM recurring_templates WHERE lower(name) = lower(:n) AND active AND id IS DISTINCT FROM :id');
    $dupe->execute(['n' => $f['name'], 'id' => $id]);
    if ($f['active'] && $dupe->fetchColumn() !== false) {
        throw new DomainException('An active template named ' . $f['name'] . ' exists.');
    }
    $args = ['n' => $f['name'], 'k' => $f['kind'], 'c' => $f['cadence'], 'e' => $f['every_n'], 'nd' => $f['next_date'], 'ed' => $f['end_date'], 't' => json_encode($template, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'a' => $f['active'] ? 't' : 'f'];
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO recurring_templates (name, kind, cadence, every_n, next_date, end_date, template, active, created_by) VALUES (:n, :k, :c, :e, :nd, :ed, CAST(:t AS jsonb), :a, :by) RETURNING id');
        $st->execute($args + ['by' => $by]);
        return (int) $st->fetchColumn();
    }
    $cur = template_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($cur['kind'] !== $f['kind']) {
        throw new DomainException('A template\'s kind is fixed once made; make a new template.');
    }
    $pdo->prepare('UPDATE recurring_templates SET name = :n, cadence = :c, every_n = :e, next_date = :nd, end_date = :ed, template = CAST(:t AS jsonb), active = :a WHERE id = :id')
        ->execute(['n' => $args['n'], 'c' => $args['c'], 'e' => $args['e'], 'nd' => $args['nd'], 'ed' => $args['ed'], 't' => $args['t'], 'a' => $args['a'], 'id' => $id]);
    return $id;
}

function toggle_template(PDO $pdo, int $id, bool $active): array
{
    $cur = template_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($active && $cur['end_date'] !== null && $cur['next_date'] > $cur['end_date']) {
        throw new DomainException($cur['name'] . ' has run past its end date; change the dates first.');
    }
    if ($active) {
        $dupe = $pdo->prepare('SELECT 1 FROM recurring_templates WHERE lower(name) = lower(:n) AND active AND id <> :id');
        $dupe->execute(['n' => $cur['name'], 'id' => $id]);
        if ($dupe->fetchColumn() !== false) {
            throw new DomainException('An active template named ' . $cur['name'] . ' exists.');
        }
    }
    $pdo->prepare('UPDATE recurring_templates SET active = :a WHERE id = :id')->execute(['a' => $active ? 't' : 'f', 'id' => $id]);
    return $cur;
}

/** Delete a template (its runs go with it; the documents it drafted stay). */
function delete_template(PDO $pdo, int $id): array
{
    $cur = template_state($pdo, $id) ?? throw new DomainException('Not found.');
    $pdo->prepare('DELETE FROM recurring_templates WHERE id = :id')->execute(['id' => $id]);
    return $cur;
}
