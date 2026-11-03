/**
 * Pulse Mark - Unified Client Application Script
 */

document.addEventListener('DOMContentLoaded', () => {
    // Initialize Theme
    const savedTheme = localStorage.getItem('pulse_theme') || 'dark';
    document.documentElement.setAttribute('data-theme', savedTheme);
});
