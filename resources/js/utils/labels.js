export function cardTypeLabel(type) {
    return {
        single: 'Single',
        double: 'Double',
    }[type] || type;
}

export function effectiveCardStatus(status, expiresAt) {
    if (status === 'active' && expiresAt && new Date(expiresAt) <= new Date()) return 'expired';
    return status;
}

export function cardStatusLabel(status, expiresAt) {
    const effective = effectiveCardStatus(status, expiresAt);
    return {
        active: 'Active',
        used: 'Used',
        cancelled: 'Cancelled',
        expired: 'Expired',
    }[effective] || effective;
}

export function statusBadge(status, expiresAt) {
    const effective = effectiveCardStatus(status, expiresAt);
    return {
        active: 'badge-success',
        used: 'badge-neutral',
        cancelled: 'badge-danger',
        expired: 'badge-warning',
        queued: 'badge-neutral',
        sent: 'badge-info',
        delivered: 'badge-success',
        failed: 'badge-danger',
    }[effective] || 'badge-neutral';
}

export function entryResultLabel(result) {
    return {
        allowed: 'Allowed',
        denied: 'Denied',
        already_used: 'Card already used',
        cancelled: 'Card cancelled',
        expired: 'Card expired',
        invalid: 'Invalid card',
    }[result] || result;
}

export function templateStatusLabel(status) {
    return {
        active: 'Active',
        inactive: 'Inactive',
    }[status] || status;
}

export function verificationMethodLabel(method) {
    return {
        qr: 'QR',
        card_number: 'Card Number',
        guest_name: 'Guest Name',
    }[method] || method || '-';
}
