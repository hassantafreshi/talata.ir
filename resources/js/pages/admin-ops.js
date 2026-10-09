import { wireAdminActions } from '../lib/admin-forms.js';

// Admin pages built only from action forms/buttons (payments, SMS, quotes, tax rules, staff, system…).
export default function () {
  wireAdminActions();
}
