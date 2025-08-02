import { defineStore } from 'pinia';

export const useSettingsStore = defineStore('settings', {
    state: () => ({
        // Protection & Overlay
        isProtectionEnabled: false,
        isProtectionSupported: false,
        isOverlayMode: false,
        isOverlaySupported: true,
        
        // UI State
        showCoachDropdown: false,
        showMobileMenu: false,
        templateSearchQuery: '',
        
        // Feature flags
        debugMode: false,
    }),
    
    getters: {
        protectionStatusText: (state) => {
            if (!state.isProtectionSupported) return 'N/A';
            return state.isProtectionEnabled ? 'Protected' : 'Protect';
        },
        
        overlayStatusText: (state) => {
            return state.isOverlayMode ? 'Normal' : 'Overlay';
        },
    },
    
    actions: {
        toggleProtection() {
            if (this.isProtectionSupported) {
                this.isProtectionEnabled = !this.isProtectionEnabled;
            }
        },
        
        toggleOverlayMode() {
            this.isOverlayMode = !this.isOverlayMode;
            // Add class to body element
            if (this.isOverlayMode) {
                document.body.classList.add('overlay-mode-active');
            } else {
                document.body.classList.remove('overlay-mode-active');
            }
        },
        
        setOverlayMode(enabled: boolean) {
            this.isOverlayMode = enabled;
            // Add class to body element
            if (this.isOverlayMode) {
                document.body.classList.add('overlay-mode-active');
            } else {
                document.body.classList.remove('overlay-mode-active');
            }
        },
        
        setProtectionSupported(supported: boolean) {
            this.isProtectionSupported = supported;
        },
        
        setOverlaySupported(supported: boolean) {
            this.isOverlaySupported = supported;
        },
        
        toggleCoachDropdown() {
            this.showCoachDropdown = !this.showCoachDropdown;
            // Close mobile menu if open
            if (this.showCoachDropdown) {
                this.showMobileMenu = false;
            }
        },
        
        toggleMobileMenu() {
            this.showMobileMenu = !this.showMobileMenu;
            // Close coach dropdown if open
            if (this.showMobileMenu) {
                this.showCoachDropdown = false;
            }
        },
        
        closeAllDropdowns() {
            this.showCoachDropdown = false;
            this.showMobileMenu = false;
        },
        
        setTemplateSearchQuery(query: string) {
            this.templateSearchQuery = query;
        },
        
        resetDropdownStates() {
            this.showCoachDropdown = false;
            this.showMobileMenu = false;
            this.templateSearchQuery = '';
        },
        
        setDebugMode(enabled: boolean) {
            this.debugMode = enabled;
        },
    },
});