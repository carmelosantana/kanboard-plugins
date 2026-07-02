/*!
 * ShadcnTheme - Theme Switcher
 * Modern theme switching with system preference detection
 * Author: Carmelo Santana <carmelo@carmelosantana.com>
 * License: MIT
 */

(function() {
    'use strict';
    
    /**
     * Theme Switcher Class
     * Handles theme switching functionality with system preference detection
     */
    class ShadcnThemeSwitcher {
        constructor() {
            this.currentTheme = 'dark';
            this.systemTheme = 'light';
            this.init();
        }
        
        /**
         * Initialize the theme switcher
         */
        init() {
            this.detectSystemTheme();
            this.loadStoredTheme();
            this.applyTheme();
            this.setupEventListeners();
            this.createThemeToggle();
        }
        
        /**
         * Detect system theme preference
         */
        detectSystemTheme() {
            if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                this.systemTheme = 'dark';
            } else {
                this.systemTheme = 'light';
            }
            
            // Listen for system theme changes
            if (window.matchMedia) {
                const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
                mediaQuery.addEventListener('change', (e) => {
                    this.systemTheme = e.matches ? 'dark' : 'light';
                    if (this.currentTheme === 'system') {
                        this.applyTheme();
                    }
                });
            }
        }
        
        /**
         * Load stored theme preference
         */
        loadStoredTheme() {
            // Try to get theme from server-side session data
            if (typeof window.shadcnThemeMode !== 'undefined') {
                this.currentTheme = window.shadcnThemeMode;
            } else {
                // Fallback to localStorage; if nothing stored, default to dark
                const stored = localStorage.getItem('shadcn-theme-mode');
                if (stored && ['light', 'dark', 'system'].includes(stored)) {
                    this.currentTheme = stored;
                } else {
                    this.currentTheme = 'dark';
                }
            }
        }
        
        /**
         * Apply the current theme
         */
        applyTheme() {
            const html = document.documentElement;
            const body = document.body;
            
            // Remove existing theme classes
            html.classList.remove('theme-light', 'theme-dark');
            body.classList.remove('theme-light', 'theme-dark');
            
            // Determine actual theme to apply
            let themeToApply = this.currentTheme;
            if (this.currentTheme === 'system') {
                themeToApply = this.systemTheme;
            }
            
            // Apply theme class
            const themeClass = `theme-${themeToApply}`;
            html.classList.add(themeClass);
            body.classList.add(themeClass);
            
            // Store in localStorage for persistence
            localStorage.setItem('shadcn-theme-mode', this.currentTheme);
            
            // Update any theme toggle UI
            this.updateThemeToggles();
            
            // Dispatch custom event
            window.dispatchEvent(new CustomEvent('themeChanged', {
                detail: {
                    theme: this.currentTheme,
                    actualTheme: themeToApply
                }
            }));
        }
        
        /**
         * Set theme preference
         * @param {string} theme - 'light', 'dark', or 'system'
         */
        setTheme(theme) {
            if (!['light', 'dark', 'system'].includes(theme)) {
                console.warn('Invalid theme:', theme);
                return;
            }
            
            this.currentTheme = theme;
            this.applyTheme();
            
            // Save to server if user is logged in
            this.saveThemeToServer(theme);
        }
        
        /**
         * Cycle through themes
         */
        cycleTheme() {
            const themes = ['light', 'dark', 'system'];
            const currentIndex = themes.indexOf(this.currentTheme);
            const nextIndex = (currentIndex + 1) % themes.length;
            this.setTheme(themes[nextIndex]);
        }
        
        /**
         * Save theme preference to server
         * @param {string} theme - Theme to save
         */
        saveThemeToServer(theme) {
            // Check if we have the necessary functions available
            if (typeof KB === 'undefined' || typeof KB.http === 'undefined') {
                return;
            }
            
            // Save to server via AJAX
            KB.http.postJson('theme/set/' + theme, {})
                .then(function(response) {
                    if (!response.success) {
                        console.warn('Failed to save theme preference:', response.error);
                    }
                })
                .catch(function(error) {
                    console.warn('Error saving theme preference:', error);
                });
        }
        
        /**
         * Create theme toggle button
         */
        createThemeToggle() {
            // Look for existing theme toggle containers
            const containers = document.querySelectorAll('.theme-toggle-container');
            
            containers.forEach(container => {
                if (container.dataset.initialized) return;
                
                const toggle = this.createThemeToggleElement();
                container.appendChild(toggle);
                container.dataset.initialized = 'true';
            });
            
            // Also add to user dropdown if it exists
            this.addToUserDropdown();
        }
        
        /**
         * Create theme toggle element
         * @returns {HTMLElement}
         */
        createThemeToggleElement() {
            const toggle = document.createElement('div');
            toggle.className = 'theme-toggle';
            toggle.innerHTML = `
                <button type="button" class="theme-toggle-btn btn btn-default" aria-label="Toggle theme">
                    <span class="theme-icon">
                        <svg class="theme-icon-light" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
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
                        <svg class="theme-icon-dark" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                        </svg>
                        <svg class="theme-icon-system" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                            <line x1="8" y1="21" x2="16" y2="21"/>
                            <line x1="12" y1="17" x2="12" y2="21"/>
                        </svg>
                    </span>
                    <span class="theme-text"></span>
                </button>
            `;
            
            const button = toggle.querySelector('.theme-toggle-btn');
            button.addEventListener('click', () => this.cycleTheme());
            
            return toggle;
        }
        
        /**
         * Add theme toggle to user dropdown menu
         */
        addToUserDropdown() {
            // Look for user dropdown menu
            const userDropdown = document.querySelector('.dropdown-submenu-open');
            if (!userDropdown || userDropdown.querySelector('.theme-toggle-dropdown')) {
                return;
            }
            
            const themeItem = document.createElement('li');
            themeItem.className = 'theme-toggle-dropdown';
            themeItem.innerHTML = `
                <a href="#" class="theme-toggle-link">
                    <i class="fa fa-adjust"></i>
                    <span class="theme-dropdown-text">Theme: <span class="current-theme-text"></span></span>
                </a>
            `;
            
            // Add to dropdown
            userDropdown.appendChild(themeItem);
            
            // Add click handler
            const link = themeItem.querySelector('.theme-toggle-link');
            link.addEventListener('click', (e) => {
                e.preventDefault();
                this.cycleTheme();
            });
        }
        
        /**
         * Update theme toggle UI elements
         */
        updateThemeToggles() {
            // Update button text and icons
            const themeTexts = document.querySelectorAll('.theme-text');
            const currentThemeTexts = document.querySelectorAll('.current-theme-text');
            const themeIcons = document.querySelectorAll('.theme-icon');
            
            const themeLabels = {
                light: 'Light',
                dark: 'Dark',
                system: 'System'
            };
            
            const text = themeLabels[this.currentTheme] || 'System';
            
            themeTexts.forEach(el => el.textContent = text);
            currentThemeTexts.forEach(el => el.textContent = text);
            
            // Update icons
            themeIcons.forEach(iconContainer => {
                const lightIcon = iconContainer.querySelector('.theme-icon-light');
                const darkIcon = iconContainer.querySelector('.theme-icon-dark');
                const systemIcon = iconContainer.querySelector('.theme-icon-system');
                
                // Hide all icons first
                [lightIcon, darkIcon, systemIcon].forEach(icon => {
                    if (icon) icon.style.display = 'none';
                });
                
                // Show appropriate icon
                const activeIcon = iconContainer.querySelector(`.theme-icon-${this.currentTheme}`);
                if (activeIcon) {
                    activeIcon.style.display = 'block';
                }
            });
        }
        
        /**
         * Setup event listeners
         */
        setupEventListeners() {
            // Listen for keyboard shortcuts
            document.addEventListener('keydown', (e) => {
                // Ctrl/Cmd + Shift + T for theme toggle
                if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'T') {
                    e.preventDefault();
                    this.cycleTheme();
                }
            });
            
            // Listen for storage changes (for multi-tab sync)
            window.addEventListener('storage', (e) => {
                if (e.key === 'shadcn-theme-mode' && e.newValue) {
                    this.currentTheme = e.newValue;
                    this.applyTheme();
                }
            });
        }
        
        /**
         * Get current theme info
         * @returns {Object}
         */
        getThemeInfo() {
            return {
                current: this.currentTheme,
                system: this.systemTheme,
                actual: this.currentTheme === 'system' ? this.systemTheme : this.currentTheme
            };
        }
    }
    
    // Initialize theme switcher when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            window.shadcnThemeSwitcher = new ShadcnThemeSwitcher();
        });
    } else {
        window.shadcnThemeSwitcher = new ShadcnThemeSwitcher();
    }
    
    // Export for global access
    window.ShadcnThemeSwitcher = ShadcnThemeSwitcher;
    
})();