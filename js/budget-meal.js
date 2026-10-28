/**
 * Budget meal builder (Rice -> Drink -> Add-ons -> Review).
 *  - On budget_meal.php the form is already on the page.
 *  - On menu.php the "+" button on a budget meal opens it in a modal.
 * Without JavaScript the form still works: every section is shown at once.
 */
(function () {
    'use strict';

    function money(n) { return '\u20B1' + n.toFixed(2); }

    function initBuilder(form, closeFn) {
        var steps = Array.prototype.slice.call(form.querySelectorAll('.meal-step'));
        var labels = Array.prototype.slice.call(form.querySelectorAll('.meal-steps li'));
        var backBtn = form.querySelector('#mealBack');
        var nextBtn = form.querySelector('#mealNext');
        var submitBtn = form.querySelector('#mealSubmit');
        var cancelBtn = form.querySelector('#mealCancel');
        var qtyInput = form.querySelector('#mealQty');
        var base = parseFloat(form.getAttribute('data-base')) || 0;
        var inModal = !!form.closest('dialog');
        var current = 0;

        function checkedIn(step) {
            return Array.prototype.slice.call(step.querySelectorAll('input:checked'));
        }

        function unitPrice() {
            var total = base;
            form.querySelectorAll('.meal-step input:checked').forEach(function (i) {
                total += parseFloat(i.getAttribute('data-price')) || 0;
            });
            return total;
        }

        function addRow(list, left, right) {
            var li = document.createElement('li');
            var a = document.createElement('span');
            var b = document.createElement('span');
            a.textContent = left;   // textContent: option names are never treated as HTML
            b.textContent = right;
            li.appendChild(a);
            li.appendChild(b);
            list.appendChild(li);
        }

        function renderReview() {
            var list = form.querySelector('#mealReview');
            var qty = Math.max(1, parseInt(qtyInput.value, 10) || 1);
            list.innerHTML = '';
            addRow(list, 'Budget meal', money(base));
            steps.forEach(function (step) {
                checkedIn(step).forEach(function (i) {
                    var p = parseFloat(i.getAttribute('data-price')) || 0;
                    addRow(list, i.getAttribute('data-label'), i.value === '0' ? '\u2014' : (p > 0 ? '+' + money(p) : 'Included'));
                });
            });
            form.querySelector('#mealTotal').textContent = money(unitPrice() * qty);
        }

        function stepIsValid(step) {
            if (step.getAttribute('data-required') !== '1') { return true; }
            return checkedIn(step).length > 0;
        }

        function refreshNext() {
            var step = steps[current];
            nextBtn.disabled = !stepIsValid(step);
            var optional = step.getAttribute('data-step') === 'addon';
            nextBtn.textContent = (optional && checkedIn(step).length === 0) ? 'Skip' : 'Next';
        }

        function show(n, initial) {
            current = n;
            var last = steps.length - 1;
            steps.forEach(function (s, k) { s.hidden = (k !== n); });
            labels.forEach(function (l, k) {
                l.classList.toggle('is-active', k === n);
                l.classList.toggle('is-done', k < n);
            });
            backBtn.hidden = (n === 0);
            cancelBtn.hidden = (n !== 0);
            nextBtn.hidden = (n === last);
            submitBtn.hidden = (n !== last);
            if (n === last) { renderReview(); }
            refreshNext();
            if (!initial && !inModal) {
                form.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        form.classList.add('is-js');
        nextBtn.addEventListener('click', function () {
            if (stepIsValid(steps[current]) && current < steps.length - 1) { show(current + 1); }
        });
        backBtn.addEventListener('click', function () {
            if (current > 0) { show(current - 1); }
        });
        if (closeFn) {
            cancelBtn.addEventListener('click', function (e) { e.preventDefault(); closeFn(); });
        }
        form.addEventListener('change', function () {
            refreshNext();
            if (current === steps.length - 1) { renderReview(); }
        });
        qtyInput.addEventListener('input', renderReview);
        // Enter inside a step should advance, not submit early.
        form.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && e.target.tagName !== 'BUTTON' && current < steps.length - 1) {
                e.preventDefault();
                if (!nextBtn.disabled) { nextBtn.click(); }
            }
        });

        show(0, true);
    }

    // ---- Standalone page (budget_meal.php) ----
    var standalone = document.getElementById('mealForm');
    if (standalone && !standalone.closest('dialog')) {
        initBuilder(standalone, null);
    }

    // ---- Modal on the menu page ----
    var dialog = document.getElementById('mealModal');
    if (!dialog || typeof dialog.showModal !== 'function') { return; }
    var content = dialog.querySelector('.meal-modal__content');

    function closeModal() { if (dialog.open) { dialog.close(); } }

    dialog.addEventListener('close', function () {
        document.body.style.overflow = '';
        content.innerHTML = '';
    });
    // Click on the dimmed backdrop (the dialog element itself) closes it.
    dialog.addEventListener('click', function (e) {
        if (e.target === dialog) { closeModal(); }
    });

    document.addEventListener('click', function (e) {
        var link = e.target.closest ? e.target.closest('.js-meal-open') : null;
        if (!link) { return; }
        e.preventDefault();

        content.innerHTML = '<p class="meal-modal__loading">Loading\u2026</p>';
        document.body.style.overflow = 'hidden';
        dialog.showModal();

        fetch(link.href + '&partial=1', { credentials: 'same-origin' })
            .then(function (res) {
                // A redirect means the session expired or the meal is gone: use the full page.
                if (!res.ok || res.redirected) { throw new Error('unavailable'); }
                return res.text();
            })
            .then(function (html) {
                content.innerHTML = html;
                var form = content.querySelector('#mealForm');
                if (!form) { throw new Error('no form'); }
                initBuilder(form, closeModal);
                var x = content.querySelector('.meal-modal__close');
                if (x) { x.addEventListener('click', closeModal); }
            })
            .catch(function () {
                closeModal();
                window.location.href = link.href;
            });
    });
})();
