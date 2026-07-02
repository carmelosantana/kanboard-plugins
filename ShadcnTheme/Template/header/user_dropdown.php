<?php if ($this->user->hasAccess('UserViewController', 'show')): ?>
<li>
    <?= $this->modal->large('edit-user', t('Edit profile'), 'UserModificationController', 'show') ?>
</li>
<?php endif ?>

<?php if ($this->user->hasAccess('UserViewController', 'notifications')): ?>
<li>
    <?= $this->url->link(t('Notifications'), 'UserViewController', 'notifications') ?>
</li>
<?php endif ?>

<?php if ($this->user->hasAccess('UserViewController', 'integrations')): ?>
<li>
    <?= $this->url->link(t('Integrations'), 'UserViewController', 'integrations') ?>
</li>
<?php endif ?>

<?php if ($this->user->hasAccess('UserViewController', 'password')): ?>
<li>
    <?= $this->modal->medium('edit-password', t('Change password'), 'UserModificationController', 'password') ?>
</li>
<?php endif ?>

<?php if ($this->user->hasAccess('UserViewController', 'share')): ?>
<li>
    <?= $this->url->link(t('Public access'), 'UserViewController', 'share') ?>
</li>
<?php endif ?>

<?php if ($this->user->hasAccess('UserViewController', '2fa')): ?>
<li>
    <?= $this->url->link(t('Two factor authentication'), 'UserViewController', '2fa') ?>
</li>
<?php endif ?>

<?php if ($this->user->hasAccess('UserViewController', 'apiAccess')): ?>
<li>
    <?= $this->url->link(t('API access'), 'UserViewController', 'apiAccess') ?>
</li>
<?php endif ?>

<?php if ($this->user->hasAccess('UserViewController', 'passwordReset')): ?>
<li>
    <?= $this->url->link(t('Password reset history'), 'UserViewController', 'passwordReset') ?>
</li>
<?php endif ?>

<?php if ($this->user->hasAccess('UserViewController', 'sessions')): ?>
<li>
    <?= $this->url->link(t('Persistent connections'), 'UserViewController', 'sessions') ?>
</li>
<?php endif ?>

<!-- Theme Toggle Addition -->
<li>
    <a href="#" id="theme-toggle-dropdown" class="theme-toggle-link">
        <span class="theme-icon-container">
            <svg class="theme-icon theme-icon-light" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
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
            <svg class="theme-icon theme-icon-dark" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
            </svg>
            <svg class="theme-icon theme-icon-system" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                <line x1="8" y1="21" x2="16" y2="21"/>
                <line x1="12" y1="17" x2="12" y2="21"/>
            </svg>
        </span>
        <span class="theme-text">
            <?= t('Theme') ?>: <span class="current-theme-text">System</span>
        </span>
    </a>
</li>

<li role="separator" class="divider"></li>

<li>
    <?= $this->url->link(t('Logout'), 'AuthController', 'logout') ?>
</li>

<style>
/* Theme toggle styling */
.theme-toggle-link {
    display: flex;
    align-items: center;
    gap: var(--space-2, 0.5rem);
    padding: var(--space-2, 0.5rem) var(--space-3, 0.75rem);
    border-radius: calc(var(--radius, 0.5rem) - 2px);
    transition: all var(--transition-fast, 150ms);
    text-decoration: none;
    color: var(--popover-foreground, inherit);
}

.theme-toggle-link:hover {
    background-color: var(--accent, rgba(0, 0, 0, 0.05));
    color: var(--accent-foreground, inherit);
    text-decoration: none;
}

.theme-icon-container {
    position: relative;
    width: 16px;
    height: 16px;
    flex-shrink: 0;
}

.theme-icon {
    position: absolute;
    top: 0;
    left: 0;
    opacity: 0;
    transition: opacity var(--transition-fast, 150ms);
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

.divider {
    height: 1px;
    background-color: var(--border, rgba(0, 0, 0, 0.1));
    margin: var(--space-1, 0.25rem) 0;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .theme-text {
        font-size: 0.8rem;
    }
}
</style>

<script>
// Theme toggle functionality for dropdown
(function() {
    'use strict';
    
    const themeToggle = document.getElementById('theme-toggle-dropdown');
    if (!themeToggle) return;
    
    // Initialize theme display
    function updateThemeDisplay() {
        if (typeof window.shadcnThemeSwitcher !== 'undefined') {
            const themeInfo = window.shadcnThemeSwitcher.getThemeInfo();
            const currentThemeText = themeToggle.querySelector('.current-theme-text');
            const icons = themeToggle.querySelectorAll('.theme-icon');
            
            // Update text
            const themeLabels = {
                light: '<?= t('Light') ?>',
                dark: '<?= t('Dark') ?>',
                system: '<?= t('System') ?>'
            };
            
            if (currentThemeText) {
                currentThemeText.textContent = themeLabels[themeInfo.current] || themeLabels.system;
            }
            
            // Update icons
            icons.forEach(icon => {
                icon.classList.remove('active');
            });
            
            const activeIcon = themeToggle.querySelector(`.theme-icon-${themeInfo.current}`);
            if (activeIcon) {
                activeIcon.classList.add('active');
            }
        }
    }
    
    // Handle theme toggle click
    themeToggle.addEventListener('click', function(e) {
        e.preventDefault();
        if (typeof window.shadcnThemeSwitcher !== 'undefined') {
            window.shadcnThemeSwitcher.cycleTheme();
        }
    });
    
    // Listen for theme changes
    window.addEventListener('themeChanged', updateThemeDisplay);
    
    // Initial update
    updateThemeDisplay();
    
    // Retry initialization if theme switcher isn't ready yet
    if (typeof window.shadcnThemeSwitcher === 'undefined') {
        let retries = 0;
        const maxRetries = 10;
        const checkInterval = setInterval(() => {
            if (typeof window.shadcnThemeSwitcher !== 'undefined' || retries >= maxRetries) {
                clearInterval(checkInterval);
                if (typeof window.shadcnThemeSwitcher !== 'undefined') {
                    updateThemeDisplay();
                }
            }
            retries++;
        }, 100);
    }
})();
</script>