<?php
/**
 * Floating AI chat (Modules\Assistant\AssistantWidget). The behaviour is in
 * public/assets/js/assistant.js; this is the static shell with the prompt
 * library (Modules\Assistant\AssistantPrompts).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var string $csrf
 * @var string $workspace
 * @var string $provider  Claude or GPT
 * @var array<string, array{label: string, prompts: list<array{title: string, text: string}>}> $prompts
 */
$first = array_key_first($prompts);
?>
<div class="assistant" data-assistant data-csrf="<?= $view->e($csrf) ?>" data-workspace="<?= $view->e($workspace) ?>">
    <button type="button" class="assistant-launcher" data-assistant-toggle aria-expanded="false" aria-controls="assistant-panel">
        <?= $view->icon('chat') ?><span>Ask AI</span>
    </button>
    <section class="assistant-panel" id="assistant-panel" role="dialog" aria-label="AI assistant" hidden>
        <header class="assistant-header">
            <strong>AI assistant</strong>
            <span class="assistant-header-actions">
                <button type="button" class="button button-small button-ghost" data-assistant-library-toggle aria-expanded="false" aria-controls="assistant-library">Prompts</button>
                <button type="button" class="button button-small button-ghost" data-assistant-export title="Download this conversation as a Markdown file">Export</button>
                <button type="button" class="button button-small button-ghost" data-assistant-new title="Start a new conversation">New chat</button>
                <button type="button" class="icon-button assistant-expand" data-assistant-expand aria-pressed="false" aria-label="Make the assistant wider"><?= $view->icon('expand') ?></button>
                <button type="button" class="icon-button" data-assistant-toggle aria-label="Close the assistant"><?= $view->icon('close') ?></button>
            </span>
        </header>
        <div class="assistant-log" data-assistant-log aria-live="polite">
            <div class="assistant-empty" data-assistant-empty>
                <p class="assistant-empty-lead">Ask about this workspace's SEO data, or start from a prompt. Selecting one puts it in the message box so you can adjust dates or thresholds first.</p>
                <div class="assistant-library" id="assistant-library" data-assistant-library>
                    <div class="assistant-library-tabs" role="tablist" aria-label="Prompt categories">
                        <?php foreach ($prompts as $key => $group): ?>
                            <button type="button" role="tab" id="assistant-tab-<?= $view->e($key) ?>" aria-controls="assistant-prompts-<?= $view->e($key) ?>" aria-selected="<?= $key === $first ? 'true' : 'false' ?>" tabindex="<?= $key === $first ? '0' : '-1' ?>"><?= $view->e($group['label']) ?></button>
                        <?php endforeach; ?>
                    </div>
                    <?php foreach ($prompts as $key => $group): ?>
                        <ul class="assistant-prompts" role="tabpanel" id="assistant-prompts-<?= $view->e($key) ?>" aria-labelledby="assistant-tab-<?= $view->e($key) ?>"<?= $key === $first ? '' : ' hidden' ?>>
                            <?php foreach ($group['prompts'] as $prompt): ?>
                                <li><button type="button" class="assistant-prompt" data-assistant-prompt="<?= $view->e($prompt['text']) ?>"><strong><?= $view->e($prompt['title']) ?></strong><span><?= $view->e($prompt['text']) ?></span></button></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="assistant-confirm" data-assistant-confirm hidden>
            <p data-assistant-action></p>
            <div class="actions">
                <button type="button" class="button button-small" data-assistant-approve>Confirm</button>
                <button type="button" class="button button-small button-ghost" data-assistant-decline>Cancel</button>
            </div>
        </div>
        <form class="assistant-form" data-assistant-form>
            <label class="visually-hidden" for="assistant-input">Message</label>
            <textarea id="assistant-input" name="message" rows="1" maxlength="4000" placeholder="Ask about your SEO data…" data-assistant-input></textarea>
            <button type="submit" class="icon-button assistant-send" aria-label="Send"><?= $view->icon('send') ?></button>
        </form>
        <p class="assistant-note">Answers come from <?= $view->e($provider) ?> and the stored data; check important numbers. Enter sends, Shift+Enter adds a line.</p>
    </section>
</div>
<script type="module" src="/assets/js/assistant.js?v=<?= $view->e((string) ($assetVersion ?? '0')) ?>"></script>
