export function ipStatusLabel(allowed) {
    if (allowed === true) return 'Allowed';
    if (allowed === false) return 'Blocked';
    return '—';
}

export function ipStatusDotClass(allowed) {
    if (allowed === true) return 'bg-[var(--color-success)] shadow-[0_0_6px_var(--color-success)]';
    if (allowed === false) return 'bg-[var(--color-danger)] shadow-[0_0_6px_var(--color-danger)]';
    return 'bg-[var(--color-text-muted)]';
}

export function ipStatusTextClass(allowed) {
    if (allowed === true) return 'text-[var(--color-success)]';
    if (allowed === false) return 'text-[var(--color-danger)]';
    return 'text-[var(--color-text-muted)]';
}
