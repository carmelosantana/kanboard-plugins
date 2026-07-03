<li>
    <a href="#" id="theme-toggle-dropdown" class="theme-toggle-link">
        <span class="theme-icon-container">
            <svg class="theme-icon theme-icon-light" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="5"/>
                <line x1="12" y1="1" x2="12" y2="3"/>
                <line x1="12" y1="21" x2="12" y2="23"/>
                <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
                <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                <line x1="1" y1="12" x2="3" y2="12"/>
                <line x1="21" y1="12" x2="23" y2="12"/>
                <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
                <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
            </svg>
            <svg class="theme-icon theme-icon-dark" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
            </svg>
            <svg class="theme-icon theme-icon-system active" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                <line x1="8" y1="21" x2="16" y2="21"/>
                <line x1="12" y1="17" x2="12" y2="21"/>
            </svg>
        </span>
        <span class="theme-text">
            Theme: <span class="current-theme-text">System</span>
        </span>
    </a>
</li>

<style>
.theme-toggle-link {
    display: flex;
    align-items: center;
    gap: 0.25em;
    /* No extra padding — the icon must line up with the fa-fw icons of the
       sibling dropdown items, which start at the <li> padding edge. */
    padding: 0;
    transition: color 150ms;
    text-decoration: none;
    color: inherit;
}

.theme-toggle-link:hover {
    text-decoration: none;
}

.theme-icon-container {
    position: relative;
    /* Match Font Awesome fa-fw fixed width so the icon column aligns with the
       other dropdown entries' icons. */
    width: 1.28571em;
    height: 1.28571em;
    flex-shrink: 0;
}

.theme-icon {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    opacity: 0;
    transition: opacity 150ms;
}

.theme-icon.active {
    opacity: 1;
}

.theme-text {
    font-size: 0.875rem;
    line-height: 1.25rem;
}

.current-theme-text {
    font-weight: 500;
    color: var(--primary, #0066cc);
}
</style>
<?php /* Icon activation + click handling live in Assets/js/theme-switcher.js
         (external, CSP-safe). Inline <script> is blocked by Kanboard's
         default-src 'self' CSP, so it must not be used here. */ ?>