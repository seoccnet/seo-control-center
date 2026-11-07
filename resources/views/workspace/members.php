<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var list<SeoControlCenter\Domain\Workspaces\MemberView> $members
 * @var list<SeoControlCenter\Domain\Workspaces\Invitation> $invitations
 * @var bool $canManage
 * @var int $currentUserId
 * @var array<string, string> $roles
 * @var int $invitationDays
 * @var string|null $newLink
 * @var string|null $mailedTo the invitation was also sent by e-mail to this address
 * @var array<string, string> $errors
 * @var array<string, mixed> $old
 */
$view->extend('layouts/app', ['title' => 'Members']);
$currentRole = '';
foreach ($members as $m) {
    if ($m->userId === $currentUserId) {
        $currentRole = $m->roleKey;
    }
}
$oldRole = is_string($old['role'] ?? null) ? $old['role'] : 'member';
?>
<div class="page-header">
    <h1>Members</h1>
</div>

<?php if ($newLink !== null): ?>
    <section class="card">
        <h2>Invitation link</h2>
        <?php if ($mailedTo !== null): ?>
            <p class="notice notice-success">We sent the invitation to <strong><?= $view->e($mailedTo) ?></strong>. You can also copy the link below; it is shown only once, works once and expires in <?= $view->e($invitationDays) ?> days.</p>
        <?php else: ?>
            <p class="notice notice-success">Copy the link now and send it to the person you invited. It is shown only once, works once and expires in <?= $view->e($invitationDays) ?> days.</p>
        <?php endif; ?>
        <p><code class="token-value"><?= $view->e($newLink) ?></code></p>
    </section>
<?php endif; ?>

<section class="card">
    <table class="table">
        <thead>
            <tr>
                <th scope="col">Name</th><th scope="col">E-mail</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Joined (UTC)</th>
                <?php if ($canManage): ?><th scope="col"><span class="visually-hidden">Actions</span></th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($members as $member): ?>
                <?php $editable = $canManage && $member->userId !== $currentUserId && $member->roleKey !== 'owner'; ?>
                <tr>
                    <td><?= $view->e($member->name) ?><?= $member->userId === $currentUserId ? ' <span class="muted small">(you)</span>' : '' ?></td>
                    <td><?= $view->e($member->email) ?></td>
                    <td>
                        <?php if ($editable): ?>
                            <form method="post" action="/workspace/members/<?= $view->e($member->userPublicId) ?>/role" class="inline-form">
                                <?= $view->csrfField() ?>
                                <label class="visually-hidden" for="role-<?= $view->e($member->userPublicId) ?>">Role of <?= $view->e($member->name) ?></label>
                                <select id="role-<?= $view->e($member->userPublicId) ?>" name="role">
                                    <?php foreach ($roles as $key => $label): ?>
                                        <option value="<?= $view->e($key) ?>"<?= $key === $member->roleKey ? ' selected' : '' ?>><?= $view->e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="button button-ghost button-small">Save</button>
                            </form>
                        <?php else: ?>
                            <?= $view->e($member->roleLabel) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= $view->e($member->status) ?></td>
                    <td><?= $view->e($member->joinedAt) ?></td>
                    <?php if ($canManage): ?>
                        <td>
                            <?php if ($member->userId !== $currentUserId && ($member->roleKey !== 'owner' || $currentRole === 'owner')): ?>
                                <form method="post" action="/workspace/members/<?= $view->e($member->userPublicId) ?>/remove" class="inline-form" data-confirm="Remove <?= $view->e($member->name) ?> from this workspace?">
                                    <?= $view->csrfField() ?>
                                    <button type="submit" class="button button-ghost button-small">Remove<span class="visually-hidden"> <?= $view->e($member->name) ?></span></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>

<?php if ($canManage): ?>
    <section class="card">
        <h2>Add a member</h2>
        <p class="muted">If the address already has an account, the person is added right away. Otherwise you get a one-time invitation link to send them; they choose their name and password when they open it.</p>
        <form method="post" action="/workspace/members" class="form" novalidate>
            <?= $view->csrfField() ?>
            <div class="field<?= isset($errors['email']) ? ' has-error' : '' ?>">
                <label for="email">E-mail address</label>
                <input id="email" name="email" type="email" maxlength="191" required autocomplete="off" value="<?= $view->e(is_string($old['email'] ?? null) ? $old['email'] : '') ?>"<?= $view->invalid($errors, 'email', 'email') ?>>
                <?= $view->fieldError($errors, 'email', 'email') ?>
            </div>
            <div class="field<?= isset($errors['role']) ? ' has-error' : '' ?>">
                <label for="role">Role</label>
                <select id="role" name="role"<?= $view->invalid($errors, 'role', 'role') ?>>
                    <?php foreach ($roles as $key => $label): ?>
                        <option value="<?= $view->e($key) ?>"<?= $key === $oldRole ? ' selected' : '' ?>><?= $view->e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $view->fieldError($errors, 'role', 'role') ?>
            </div>
            <p class="muted small">Admin: everything except deleting the workspace. Member: view and edit projects, start analyses. Viewer: view only.</p>
            <button type="submit" class="button">Add member</button>
        </form>
    </section>

    <?php if ($invitations !== []): ?>
        <section class="card">
            <h2>Open invitations</h2>
            <table class="table">
                <thead>
                    <tr><th scope="col">E-mail</th><th scope="col">Role</th><th scope="col">Invited by</th><th scope="col">Expires (UTC)</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($invitations as $invitation): ?>
                        <tr>
                            <td><?= $view->e($invitation->email) ?></td>
                            <td><?= $view->e($roles[$invitation->roleKey] ?? $invitation->roleKey) ?></td>
                            <td><?= $view->e($invitation->invitedByName ?? '—') ?></td>
                            <td><?= $view->e($invitation->expiresAt) ?></td>
                            <td>
                                <form method="post" action="/workspace/invitations/<?= $view->e($invitation->publicId) ?>/revoke" class="inline-form">
                                    <?= $view->csrfField() ?>
                                    <button type="submit" class="button button-ghost button-small">Revoke<span class="visually-hidden"> invitation for <?= $view->e($invitation->email) ?></span></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="muted small">Links are shown only once. If one was lost, revoke the invitation and add the person again.</p>
        </section>
    <?php endif; ?>
<?php endif; ?>
