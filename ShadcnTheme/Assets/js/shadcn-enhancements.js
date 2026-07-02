/*!
 * ShadcnTheme - UI Enhancements
 * Modern UI improvements inspired by shadcn/ui
 * Author: Carmelo Santana <carmelo@carmelosantana.com>
 * License: MIT
 */

(function() {
    'use strict';
    
    /**
     * UI Enhancements Class
     * Adds modern UI interactions and improvements
     */
    class ShadcnUIEnhancements {
        constructor() {
            this.init();
        }
        
        /**
         * Initialize all enhancements
         */
        init() {
            this.setupRippleEffects();
            this.setupTooltipEnhancements();
            this.setupFormEnhancements();
            this.setupTableEnhancements();
            this.setupModalEnhancements();
            this.setupDropdownEnhancements();
            this.setupKeyboardNavigation();
            this.setupAnimations();
        }
        
        /**
         * Add ripple effects to buttons
         */
        setupRippleEffects() {
            const addRipple = (element, event) => {
                const rect = element.getBoundingClientRect();
                const ripple = document.createElement('span');
                const size = Math.max(rect.width, rect.height);
                const x = event.clientX - rect.left - size / 2;
                const y = event.clientY - rect.top - size / 2;
                
                ripple.style.cssText = `
                    position: absolute;
                    width: ${size}px;
                    height: ${size}px;
                    left: ${x}px;
                    top: ${y}px;
                    background: currentColor;
                    opacity: 0.3;
                    border-radius: 50%;
                    transform: scale(0);
                    animation: ripple 0.6s ease-out;
                    pointer-events: none;
                `;
                
                element.style.position = 'relative';
                element.style.overflow = 'hidden';
                element.appendChild(ripple);
                
                setTimeout(() => {
                    if (ripple.parentNode) {
                        ripple.parentNode.removeChild(ripple);
                    }
                }, 600);
            };
            
            // Add ripple CSS animation
            if (!document.querySelector('#shadcn-ripple-styles')) {
                const style = document.createElement('style');
                style.id = 'shadcn-ripple-styles';
                style.textContent = `
                    @keyframes ripple {
                        to {
                            transform: scale(2);
                            opacity: 0;
                        }
                    }
                `;
                document.head.appendChild(style);
            }
            
            // Add ripple to buttons
            document.addEventListener('click', (e) => {
                if (e.target.matches('.btn, button, .ripple-effect')) {
                    addRipple(e.target, e);
                }
            });
        }
        
        /**
         * Enhanced tooltips
         */
        setupTooltipEnhancements() {
            // Improve existing tooltips with better positioning
            const observer = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    mutation.addedNodes.forEach((node) => {
                        if (node.nodeType === 1 && node.id === 'tooltip-container') {
                            this.enhanceTooltip(node);
                        }
                    });
                });
            });
            
            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        }
        
        /**
         * Enhance tooltip positioning and appearance
         * @param {HTMLElement} tooltip 
         */
        enhanceTooltip(tooltip) {
            // Add enhanced styling
            tooltip.style.cssText += `
                border-radius: var(--radius, 0.5rem);
                box-shadow: var(--shadow-lg, 0 10px 15px -3px rgb(0 0 0 / 0.1));
                backdrop-filter: blur(8px);
                animation: tooltipFadeIn 0.2s ease-out;
            `;
            
            // Add animation styles if not already present
            if (!document.querySelector('#shadcn-tooltip-styles')) {
                const style = document.createElement('style');
                style.id = 'shadcn-tooltip-styles';
                style.textContent = `
                    @keyframes tooltipFadeIn {
                        from {
                            opacity: 0;
                            transform: translateY(4px);
                        }
                        to {
                            opacity: 1;
                            transform: translateY(0);
                        }
                    }
                `;
                document.head.appendChild(style);
            }
        }
        
        /**
         * Form enhancements
         */
        setupFormEnhancements() {
            // Add floating labels for inputs
            document.addEventListener('focus', (e) => {
                if (e.target.matches('input[type="text"], input[type="email"], input[type="password"], textarea')) {
                    e.target.classList.add('focused');
                }
            }, true);
            
            document.addEventListener('blur', (e) => {
                if (e.target.matches('input[type="text"], input[type="email"], input[type="password"], textarea')) {
                    e.target.classList.remove('focused');
                    if (e.target.value) {
                        e.target.classList.add('has-value');
                    } else {
                        e.target.classList.remove('has-value');
                    }
                }
            }, true);
            
            // Add form validation styling
            this.setupFormValidation();
        }
        
        /**
         * Setup form validation enhancements
         */
        setupFormValidation() {
            document.addEventListener('invalid', (e) => {
                e.target.classList.add('invalid');
            }, true);
            
            document.addEventListener('input', (e) => {
                if (e.target.classList.contains('invalid') && e.target.validity.valid) {
                    e.target.classList.remove('invalid');
                }
            });
        }
        
        /**
         * Table enhancements
         */
        setupTableEnhancements() {
            // Add hover effects and better selection
            document.addEventListener('mouseover', (e) => {
                if (e.target.matches('tr')) {
                    e.target.classList.add('hovered');
                }
            });
            
            document.addEventListener('mouseout', (e) => {
                if (e.target.matches('tr')) {
                    e.target.classList.remove('hovered');
                }
            });
            
            // Add table responsiveness
            this.makeTablesResponsive();
        }
        
        /**
         * Make tables responsive
         */
        makeTablesResponsive() {
            const tables = document.querySelectorAll('table:not(.responsive-enhanced)');
            tables.forEach(table => {
                if (table.scrollWidth > table.clientWidth) {
                    table.style.overflowX = 'auto';
                    table.style.display = 'block';
                    table.style.whiteSpace = 'nowrap';
                }
                table.classList.add('responsive-enhanced');
            });
        }
        
        /**
         * Modal enhancements
         */
        setupModalEnhancements() {
            // Enhanced modal backdrop and animations
            document.addEventListener('click', (e) => {
                if (e.target.id === 'modal-overlay') {
                    const modal = document.querySelector('#modal-box');
                    if (modal) {
                        modal.style.animation = 'modalSlideOut 0.3s ease-out forwards';
                        setTimeout(() => {
                            if (modal.parentNode) {
                                modal.parentNode.click();
                            }
                        }, 300);
                    }
                }
            });
            
            // Add modal animations
            const observer = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    mutation.addedNodes.forEach((node) => {
                        if (node.nodeType === 1) {
                            if (node.id === 'modal-overlay') {
                                this.enhanceModal(node);
                            }
                        }
                    });
                });
            });
            
            observer.observe(document.body, {
                childList: true
            });
        }
        
        /**
         * Enhance modal appearance and behavior
         * @param {HTMLElement} overlay 
         */
        enhanceModal(overlay) {
            const modal = overlay.querySelector('#modal-box');
            if (modal) {
                overlay.style.animation = 'backdropFadeIn 0.3s ease-out';
                modal.style.animation = 'modalSlideIn 0.3s ease-out';
                
                // Add modal animation styles
                if (!document.querySelector('#shadcn-modal-styles')) {
                    const style = document.createElement('style');
                    style.id = 'shadcn-modal-styles';
                    style.textContent = `
                        @keyframes backdropFadeIn {
                            from { opacity: 0; }
                            to { opacity: 1; }
                        }
                        @keyframes modalSlideIn {
                            from {
                                opacity: 0;
                                transform: translateX(-50%) translateY(-20px) scale(0.95);
                            }
                            to {
                                opacity: 1;
                                transform: translateX(-50%) translateY(0) scale(1);
                            }
                        }
                        @keyframes modalSlideOut {
                            from {
                                opacity: 1;
                                transform: translateX(-50%) translateY(0) scale(1);
                            }
                            to {
                                opacity: 0;
                                transform: translateX(-50%) translateY(-20px) scale(0.95);
                            }
                        }
                    `;
                    document.head.appendChild(style);
                }
            }
        }
        
        /**
         * Dropdown enhancements
         */
        setupDropdownEnhancements() {
            // Add smooth animations to dropdowns
            const observer = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    mutation.addedNodes.forEach((node) => {
                        if (node.nodeType === 1 && 
                            (node.classList.contains('dropdown-submenu-open') || 
                             node.id === 'select-dropdown-menu')) {
                            this.enhanceDropdown(node);
                        }
                    });
                });
            });
            
            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        }
        
        /**
         * Enhance dropdown appearance
         * @param {HTMLElement} dropdown 
         */
        enhanceDropdown(dropdown) {
            dropdown.style.animation = 'dropdownSlideIn 0.2s ease-out';
            
            // Add dropdown animation styles
            if (!document.querySelector('#shadcn-dropdown-styles')) {
                const style = document.createElement('style');
                style.id = 'shadcn-dropdown-styles';
                style.textContent = `
                    @keyframes dropdownSlideIn {
                        from {
                            opacity: 0;
                            transform: translateY(-8px);
                        }
                        to {
                            opacity: 1;
                            transform: translateY(0);
                        }
                    }
                `;
                document.head.appendChild(style);
            }
        }
        
        /**
         * Enhanced keyboard navigation
         */
        setupKeyboardNavigation() {
            // Better focus management
            document.addEventListener('keydown', (e) => {
                // Escape key handling
                if (e.key === 'Escape') {
                    // Close dropdowns
                    const openDropdowns = document.querySelectorAll('.dropdown-submenu-open, #select-dropdown-menu');
                    openDropdowns.forEach(dropdown => {
                        if (dropdown.parentNode) {
                            dropdown.parentNode.removeChild(dropdown);
                        }
                    });
                    
                    // Close modals
                    const modal = document.querySelector('#modal-overlay');
                    if (modal) {
                        const closeBtn = modal.querySelector('#modal-close-button');
                        if (closeBtn) {
                            closeBtn.click();
                        }
                    }
                }
                
                // Tab navigation improvements
                if (e.key === 'Tab') {
                    this.handleTabNavigation(e);
                }
            });
        }
        
        /**
         * Handle tab navigation
         * @param {KeyboardEvent} e 
         */
        handleTabNavigation(e) {
            // Add visible focus indicators for keyboard navigation
            document.body.classList.add('keyboard-navigation');
            
            // Remove keyboard navigation class on mouse interaction
            document.addEventListener('mousedown', () => {
                document.body.classList.remove('keyboard-navigation');
            }, { once: true });
        }
        
        /**
         * Setup smooth animations
         */
        setupAnimations() {
            // Add intersection observer for scroll animations
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('animate-in');
                    }
                });
            }, {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            });
            
            // Observe elements that should animate in
            document.querySelectorAll('.task-board, .panel, .table-list-row').forEach(el => {
                observer.observe(el);
            });
            
            // Add animation styles
            if (!document.querySelector('#shadcn-animation-styles')) {
                const style = document.createElement('style');
                style.id = 'shadcn-animation-styles';
                style.textContent = `
                    .animate-in {
                        animation: slideInUp 0.3s ease-out;
                    }
                    
                    @keyframes slideInUp {
                        from {
                            opacity: 0;
                            transform: translateY(20px);
                        }
                        to {
                            opacity: 1;
                            transform: translateY(0);
                        }
                    }
                    
                    .keyboard-navigation *:focus {
                        outline: 2px solid var(--ring, #0066cc) !important;
                        outline-offset: 2px !important;
                    }
                `;
                document.head.appendChild(style);
            }
        }
        
        /**
         * Add loading states to buttons
         * @param {HTMLElement} button 
         */
        addLoadingState(button) {
            if (button.classList.contains('loading')) return;
            
            button.classList.add('loading');
            button.disabled = true;
            
            const originalText = button.textContent;
            button.textContent = 'Loading...';
            
            // Return function to remove loading state
            return () => {
                button.classList.remove('loading');
                button.disabled = false;
                button.textContent = originalText;
            };
        }
        
        /**
         * Show toast notification
         * @param {string} message 
         * @param {string} type 
         */
        showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.textContent = message;
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 12px 24px;
                background: var(--card, #fff);
                border: 1px solid var(--border, #e2e2e2);
                border-radius: var(--radius, 0.5rem);
                box-shadow: var(--shadow-lg, 0 10px 15px -3px rgb(0 0 0 / 0.1));
                z-index: 10000;
                animation: toastSlideIn 0.3s ease-out;
            `;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'toastSlideOut 0.3s ease-out';
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.parentNode.removeChild(toast);
                    }
                }, 300);
            }, 3000);
            
            // Add toast animation styles
            if (!document.querySelector('#shadcn-toast-styles')) {
                const style = document.createElement('style');
                style.id = 'shadcn-toast-styles';
                style.textContent = `
                    @keyframes toastSlideIn {
                        from {
                            opacity: 0;
                            transform: translateX(100%);
                        }
                        to {
                            opacity: 1;
                            transform: translateX(0);
                        }
                    }
                    @keyframes toastSlideOut {
                        from {
                            opacity: 1;
                            transform: translateX(0);
                        }
                        to {
                            opacity: 0;
                            transform: translateX(100%);
                        }
                    }
                `;
                document.head.appendChild(style);
            }
        }
    }
    
    // Initialize enhancements when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            window.shadcnUIEnhancements = new ShadcnUIEnhancements();
        });
    } else {
        window.shadcnUIEnhancements = new ShadcnUIEnhancements();
    }
    
    // Export for global access
    window.ShadcnUIEnhancements = ShadcnUIEnhancements;
    
})();