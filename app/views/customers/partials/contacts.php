<?php /** The contacts of a party, inline add/edit/remove (customer page; vendors reuse it in slice 5). Data: kind (customer|vendor), party, contacts, may (write), here */ $p = $kind === 'customer' ? 'customer' : 'vendor'; ?>
<div class="card mb-3" id="<?= e($p) ?>-contacts"><div class="card-header"><h5 class="card-title mb-0">Contacts</h5></div>
    <div class="list-group list-group-flush">
        <?php if ($contacts === []): ?><div class="list-group-item text-muted fs-12" id="<?= e($p) ?>-contacts-empty">No contact yet.</div><?php endif; ?>
        <?php foreach ($contacts as $k): $kid = (int) $k['party_contact_id']; ?>
            <div class="list-group-item" id="contact-<?= $kid ?>">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="min-w-0"><span class="fw-semibold"><?= e($k['name']) ?></span><?= $k['is_primary'] ? ' <span class="badge bg-soft-primary text-primary">Primary</span>' : '' ?><div class="fs-12 text-muted"><?= e(trim(($k['role'] ?? '') . ' ' . ($k['email'] ?? '') . ' ' . ($k['phone'] ?? ''))) ?></div></div>
                    <?php if ($may['write']): ?><form method="post" action="/customers/contact-remove.php" hx-post="/customers/contact-remove.php" hx-target="#flash" hx-confirm="Remove <?= e($k['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="contact" value="<?= $kid ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>"><button type="submit" class="btn btn-sm btn-light text-danger" id="contact-<?= $kid ?>-remove-btn" title="Remove">&times;</button></form><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($may['write']): ?>
    <div class="card-body border-top">
        <form method="post" action="/customers/contact.php" hx-post="/customers/contact.php" hx-target="#flash" id="<?= e($p) ?>-contact-form" class="row g-2">
            <?= csrf_field() ?><input type="hidden" name="party_kind" value="<?= e($kind) ?>"><input type="hidden" name="party" value="<?= (int) $party ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <div class="col-12 col-md-6"><input type="text" name="name" class="form-control btn-touch" placeholder="Name" maxlength="120" required id="<?= e($p) ?>-contact-name"></div>
            <div class="col-12 col-md-6"><input type="email" name="email" class="form-control btn-touch" placeholder="Email" maxlength="200" id="<?= e($p) ?>-contact-email"></div>
            <div class="col-6"><input type="text" name="phone" class="form-control btn-touch" placeholder="Phone" maxlength="60" id="<?= e($p) ?>-contact-phone"></div>
            <div class="col-6"><input type="text" name="role" class="form-control btn-touch" placeholder="Role (Accounts payable)" maxlength="80" id="<?= e($p) ?>-contact-role"></div>
            <div class="col-7"><input type="hidden" name="is_primary" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="<?= e($p) ?>-contact-primary"><input type="checkbox" class="form-check-input mt-0" name="is_primary" value="yes" id="<?= e($p) ?>-contact-primary">The primary contact</label></div>
            <div class="col-5"><button type="submit" class="btn btn-light btn-touch w-100" id="<?= e($p) ?>-contact-add-btn">Add</button></div>
        </form>
    </div>
    <?php endif; ?>
</div>
