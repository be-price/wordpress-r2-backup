(() => {
    const panel = document.querySelector('[data-wrb-schedule-rules]');
    if (!panel || panel.closest('fieldset').disabled) return;
    const list = panel.querySelector('[data-wrb-rule-list]');
    const status = panel.querySelector('[data-wrb-rule-status]');
    const addButtons = [...panel.querySelectorAll('[data-wrb-add]')];
    let source = null;
    let target = null;
    let after = false;

    function refresh() {
        const rows = [...list.children];
        rows.forEach((row, index) => {
            row.querySelectorAll('[name^="scheduleRules["]').forEach(field => {
                field.name = field.name.replace(/^scheduleRules\[[^\]]+\]/, 'scheduleRules[' + index + ']');
            });
            row.querySelectorAll('[data-wrb-move]').forEach(button => {
                const direction = Number(button.dataset.wrbMove);
                button.disabled = !rows[index + direction];
                button.setAttribute('aria-label', '優先順位 ' + (index + 1) + ' のルールを' + button.textContent);
            });
        });
        addButtons.forEach(button => { button.disabled = rows.length >= Number(panel.dataset.limit); });
    }

    function move(row, reference, focus) {
        if (typeof list.moveBefore === 'function') list.moveBefore(row, reference);
        else list.insertBefore(row, reference);
        refresh();
        const position = [...list.children].indexOf(row) + 1;
        if (focus && focus.disabled) focus = row.querySelector('[data-wrb-move]:not(:disabled)');
        if (focus) focus.focus();
        status.textContent = row.querySelector('strong').textContent + 'を優先順位 ' + position + ' に移動しました。';
    }

    panel.addEventListener('click', event => {
        const button = event.target.closest('button');
        if (!button) return;
        if (button.dataset.wrbAdd) {
            const template = panel.querySelector('[data-wrb-rule-template="' + button.dataset.wrbAdd + '"]');
            list.append(template.content.cloneNode(true));
            refresh();
            list.lastElementChild.querySelector('input:not([type="hidden"]), select').focus();
            return;
        }
        const row = button.closest('li');
        if (!row || row.parentElement !== list) return;
        if (button.hasAttribute('data-wrb-remove-rule')) {
            row.remove();
            refresh();
            addButtons[0].focus();
        } else if (button.dataset.wrbMove === '-1' && row.previousElementSibling) {
            move(row, row.previousElementSibling, button);
        } else if (button.dataset.wrbMove === '1' && row.nextElementSibling) {
            move(row, row.nextElementSibling.nextElementSibling, button);
        }
    });

    function clearDrag() {
        if (target) delete target.dataset.wrbDrop;
        if (source) delete source.dataset.wrbDragging;
        target = source = null;
    }

    list.addEventListener('dragstart', event => {
        if (!event.target.matches('.wrb-rule-grip')) return;
        source = event.target.closest('li');
        source.dataset.wrbDragging = '';
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', 'wrb-schedule-rule');
    });

    list.addEventListener('dragover', event => {
        if (!source) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        if (target) delete target.dataset.wrbDrop;
        const rows = [...list.children].filter(row => row !== source);
        target = rows.find(row => {
            const rect = row.getBoundingClientRect();
            return event.clientY < rect.top + rect.height / 2;
        });
        after = !target;
        if (after) target = rows[rows.length - 1];
        if (target) target.dataset.wrbDrop = after ? 'after' : 'before';
    });

    list.addEventListener('drop', event => {
        if (!source) return;
        event.preventDefault();
        const row = source;
        const reference = target ? (after ? target.nextElementSibling : target) : row;
        clearDrag();
        if (reference !== row && reference !== row.nextElementSibling) move(row, reference, null);
    });

    list.addEventListener('dragend', clearDrag);
    refresh();
})();
