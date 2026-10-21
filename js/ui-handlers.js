/**
 * Replaces inline event handlers (onchange="...", onclick="...") so the
 * Content-Security-Policy can forbid inline scripts.
 *   data-autosubmit  -> submit the parent form when the control changes
 *   data-confirm     -> ask before following/submitting (value = the question)
 */
document.addEventListener('change', function (e) {
  var el = e.target;
  if (el && el.hasAttribute && el.hasAttribute('data-autosubmit') && el.form) {
    el.form.submit();
  }
});

document.addEventListener('click', function (e) {
  var el = e.target.closest ? e.target.closest('[data-confirm]') : null;
  if (el && !window.confirm(el.getAttribute('data-confirm'))) {
    e.preventDefault();
  }
});
