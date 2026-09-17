const form = document.getElementById('calendar-form');
if (form) {
 const sync = () => {
  const type = form.elements.type.value;
  form.querySelectorAll('[data-kind]').forEach(section => { section.hidden = section.dataset.kind !== type; section.disabled = section.hidden; });
  const module = form.querySelector('[data-module]');
  const needsModule = type === 'school' && form.elements.school_stage.value === 'Modul';
  module.hidden = !needsModule; form.elements.module_code.disabled = !needsModule; form.elements.module_code.required = needsModule;
  form.elements.school_stage.required = type === 'school'; form.elements.title.required = type === 'course';
  form.elements.ends_on.min = form.elements.starts_on.value;
 };
 form.addEventListener('change', sync); sync();
}
