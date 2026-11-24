<?php
/**
 * Login screen look (panel login + DJ portal login), set in Admin > Branding:
 *   - login_bg_color : solid colour of the login box (default dark blue, no gradient)
 *   - login_bg_image : optional picture behind the login box (whole page)
 * Printed at the end of the page's <style>, so it overrides the base card style.
 */

const CP_LOGIN_DEFAULT_COLOR = '#0b1f4a';

/** Solid login colour (#rrggbb). */
function cpLoginColor(array $brand): string {
    $c = trim((string)($brand['login_bg_color'] ?? ''));
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : CP_LOGIN_DEFAULT_COLOR;
}

/**
 * CSS overrides for the login page.
 * @param string $imageUrl  absolute or root-relative URL of the background picture ('' = none)
 */
function cpLoginThemeCss(array $brand, string $imageUrl = ''): string {
    $color = cpLoginColor($brand);
    $img = $imageUrl !== '' ? str_replace(["'", '"', '\\', "\n", "\r", ')'], '', $imageUrl) : '';
    $css = "
        /* ---- login theme (Admin > Branding) ---- */
        body { background-color: " . ($img ? '#0b1220' : '#eef2f8') . "; }
        " . ($img ? "
        body { background: #0b1220 url('{$img}') center / cover no-repeat fixed; }
        body::before { content: ''; position: fixed; inset: 0; background: rgba(5, 12, 30, .35); pointer-events: none; }
        " : "") . "
        .login-card {
            background: {$color} !important;
            -webkit-backdrop-filter: none; backdrop-filter: none;
            border: 1px solid rgba(255, 255, 255, .10);
            box-shadow: 0 30px 70px rgba(2, 8, 23, .45), 0 2px 6px rgba(2, 8, 23, .25);
        }
        .login-card::before, .login-card::after { display: none !important; }
        .btn-login { color: {$color} !important; }
    ";
    return $css;
}
