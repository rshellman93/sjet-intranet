document.querySelectorAll('[data-toggle]').forEach(button => button.addEventListener('click', () => {
 const target = document.getElementById(button.dataset.toggle);
 target.hidden = !target.hidden; button.setAttribute('aria-expanded', String(!target.hidden));
 if (!target.hidden) target.querySelector('input:not([type="hidden"])')?.focus();
}));
document.querySelectorAll('[data-certificate-form]').forEach(form => {
 const sync = () => {
  const existing = form.matches('[data-link-form]') && form.elements.certificate_id.value !== '';
  const block = form.querySelector('[data-new-certificate]');
  if (block) { block.hidden = existing; block.disabled = existing; }
  const check = form.elements.never_expires; const date = form.elements.expires_on;
  date.disabled = existing || check.checked; date.required = !existing && !check.checked;
  const skill = form.querySelector('[data-new-skill]');
  if (skill) { const creating = form.elements.skill_id.value === ''; skill.hidden = !creating; form.elements.new_skill.disabled = !creating; form.elements.new_skill.required = creating; }
 };
 form.addEventListener('change', sync); sync();
});
