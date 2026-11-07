(() => {
  'use strict';
  const root = document.querySelector('[data-installer]');
  if (root) {
    const form = root.querySelector('[data-install-form]');
    const steps = [...form.querySelectorAll('[data-setup-step]')];
    const links = [...root.querySelectorAll('[data-step-link]')];
    const back = form.querySelector('[data-step-back]');
    const next = form.querySelector('[data-step-next]');
    const status = root.querySelector('[data-step-status]');
    let current = 0;
    const valid = (step) => {
      const repeat = form.elements.admin_password_confirm;
      repeat.setCustomValidity(form.elements.admin_password.value === repeat.value ? '' : 'Your passwords do not match.');
      for (const input of steps[step].querySelectorAll('input, select')) {
        if (!input.checkValidity()) {
          const details = input.closest('details');
          if (details) details.open = true;
          input.reportValidity();
          return false;
        }
      }
      return true;
    };
    const show = (index, focus = true) => {
      current = index;
      steps.forEach((step, i) => { step.hidden = i !== index; });
      links.forEach((link, i) => {
        if (i === index) link.setAttribute('aria-current', 'step');
        else link.removeAttribute('aria-current');
      });
      back.hidden = index === 0;
      next.hidden = index === steps.length - 1;
      const text = `Step ${index + 1} of ${steps.length}`;
      status.textContent = `${text} · ${links[index].textContent}`;
      form.querySelector('[data-step-count]').textContent = text;
      root.querySelectorAll('[data-review-field]').forEach((item) => {
        item.textContent = form.elements[item.dataset.reviewField].value;
      });
      if (focus) steps[index].focus();
    };
    form.querySelector('[data-step-actions]').hidden = false;
    form.querySelector('[data-review]').hidden = false;
    status.hidden = false;
    back.addEventListener('click', () => show(current - 1));
    next.addEventListener('click', () => { if (valid(current)) show(current + 1); });
    links.forEach((link, index) => link.addEventListener('click', (event) => {
      event.preventDefault();
      if (index > current) {
        for (let i = current; i < index; i++) {
          show(i, false);
          if (!valid(i)) return;
        }
      }
      show(index);
    }));
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      if (current < steps.length - 1) {
        if (!next.disabled && valid(current)) show(current + 1);
        return;
      }
      for (let i = 0; i < steps.length; i++) {
        show(i, false);
        if (!valid(i)) return;
      }
      show(steps.length - 1, false);
      const submit = form.querySelector('[type="submit"]');
      if (submit.disabled) return;
      submit.disabled = true;
      submit.textContent = 'Creating your workspace…';
      form.querySelector('[data-install-state]').textContent = 'Keep this page open. This can take a minute.';
      form.submit();
    });
    const errorStep = steps.findIndex((step) => step.querySelector('.has-error, [aria-invalid="true"]'));
    show(errorStep >= 0 ? errorStep : 0, false);
  }
  document.querySelectorAll('[data-copy]').forEach((button) => {
    if (!navigator.clipboard) return;
    button.hidden = false;
    button.addEventListener('click', async () => {
      const source = document.getElementById(button.dataset.copy);
      try {
        await navigator.clipboard.writeText(source.value || source.textContent);
        button.textContent = 'Copied';
      } catch {
        button.textContent = 'Select and copy the text';
        if (source.select) source.select();
      }
    });
  });
})();
