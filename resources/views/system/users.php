<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var list<SeoControlCenter\Domain\Accounts\UserOverview> $users
 * @var int $actorId
 * @var bool $canSendResetLinks
 */
use SeoControlCenter\Domain\Accounts\SystemRole;

$view->extend('layouts/app', ['title' => 'Users']);
$action = static fn(string $publicId, string $name): string => '/system/users/' . $publicId . '/' . $name;
?>
<div class="page-header">
    <h1>Users</h1>
</div>

<section class="card">
    <p class="muted">Everyone with an account on this installation. Workspace roles are managed under <em>Workspace → Members</em>; here you manage the accounts themselves. Deactivated users cannot sign in, their sessions end immediately and their AI access tokens stop working. Nothing is deleted, so you can reactivate them later.</p>
    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">User</th>
                    <th scope="col">Workspaces</th>
                    <th scope="col">Status</th>
                    <th scope="col">Two-factor</th>
                    <th scope="col">Last sign-in (UTC)</th>
                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $row): ?>
                    <?php $user = $row->user; $self = $user->id === $actorId; $isAdmin = $user->systemRole === SystemRole::Admin; ?>
                    <tr>
                        <td>
                            <strong><?= $view->e($user->name) ?></strong><?= $self ? ' <span class="muted small">(you)</span>' : '' ?>
                            <?php if ($isAdmin): ?> <span class="badge badge-info">Admin</span><?php endif; ?>
                            <br><span class="muted small"><?= $view->e($user->email) ?></span>
                        </td>
                        <td><?= $row->workspaces === [] ? '<span class="muted">None</span>' : $view->e(implode(', ', $row->workspaces)) ?></td>
                        <td><?= $user->active ? 'Active' : '<span class="muted">Deactivated</span>' ?></td>
                        <td><?= $row->mfaEnabled ? 'On' : '<span class="muted">Off</span>' ?></td>
                        <td><?= $view->e($row->lastLoginAt ?? '—') ?></td>
                        <td>
                            <div class="row-actions">
                                <?php if (!$self): ?>
                                    <?php if ($user->active): ?>
                                        <form method="post" action="<?= $view->e($action($user->publicId, 'deactivate')) ?>" class="inline-form" data-confirm="Deactivate <?= $view->e($user->name) ?>? They are signed out immediately.">
                                            <?= $view->csrfField() ?>
                                            <button type="submit" class="button button-ghost button-small">Deactivate<span class="visually-hidden"> <?= $view->e($user->name) ?></span></button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" action="<?= $view->e($action($user->publicId, 'activate')) ?>" class="inline-form">
                                            <?= $view->csrfField() ?>
                                            <button type="submit" class="button button-ghost button-small">Reactivate<span class="visually-hidden"> <?= $view->e($user->name) ?></span></button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($row->mfaEnabled && !$self): ?>
                                    <form method="post" action="<?= $view->e($action($user->publicId, 'reset-mfa')) ?>" class="inline-form" data-confirm="Reset the two-factor sign-in of <?= $view->e($user->name) ?>? They can then sign in with their password alone.">
                                        <?= $view->csrfField() ?>
                                        <button type="submit" class="button button-ghost button-small">Reset two-factor<span class="visually-hidden"> of <?= $view->e($user->name) ?></span></button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($canSendResetLinks && $user->active): ?>
                                    <form method="post" action="<?= $view->e($action($user->publicId, 'send-reset')) ?>" class="inline-form">
                                        <?= $view->csrfField() ?>
                                        <button type="submit" class="button button-ghost button-small">Send reset link<span class="visually-hidden"> to <?= $view->e($user->name) ?></span></button>
                                    </form>
                                <?php endif; ?>
                                <?php if (!$self && $user->active): ?>
                                    <form method="post" action="<?= $view->e($action($user->publicId, $isAdmin ? 'revoke-admin' : 'grant-admin')) ?>" class="inline-form" data-confirm="<?= $isAdmin ? 'Remove the administrator rights of ' . $view->e($user->name) . '?' : 'Make ' . $view->e($user->name) . ' an installation administrator? They can then manage the whole installation.' ?>">
                                        <?= $view->csrfField() ?>
                                        <button type="submit" class="button button-ghost button-small"><?= $isAdmin ? 'Remove admin' : 'Make admin' ?><span class="visually-hidden"> <?= $view->e($user->name) ?></span></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (!$canSendResetLinks): ?>
        <p class="muted small">Set up <a href="/system/email">e-mail</a> to send password-reset links to users.</p>
    <?php endif; ?>
</section>
