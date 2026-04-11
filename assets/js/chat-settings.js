(function($) {
    'use strict';

    var STORAGE_PREFIX = 'aiAssistant_';

    $.extend(window.aiAssistant, {
        getSetting: function(key) {
            try {
                return localStorage.getItem(STORAGE_PREFIX + key);
            } catch (e) {
                console.warn('[AI Assistant] localStorage not available:', e);
                return null;
            }
        },

        setSetting: function(key, value) {
            try {
                if (value === null || value === undefined) {
                    localStorage.removeItem(STORAGE_PREFIX + key);
                } else {
                    localStorage.setItem(STORAGE_PREFIX + key, value);
                }
                return true;
            } catch (e) {
                console.warn('[AI Assistant] Failed to save setting:', e);
                return false;
            }
        },

        removeSetting: function(key) {
            try {
                localStorage.removeItem(STORAGE_PREFIX + key);
                return true;
            } catch (e) {
                return false;
            }
        },

        /**
         * Check if provider config comes from WordPress 7.0 Connectors.
         */
        isConnectorsMode: function() {
            return typeof aiAssistantProviders !== 'undefined'
                && aiAssistantProviders.source === 'connectors'
                && aiAssistantProviders.available
                && Object.keys(aiAssistantProviders.available).length > 0;
        },

        /**
         * Get the provider priority list.
         * Returns an ordered array of provider IDs, highest priority first.
         */
        getProviderPriority: function() {
            var stored = this.getSetting('providerPriority');
            if (stored) {
                try {
                    var priority = JSON.parse(stored);
                    if (Array.isArray(priority) && priority.length > 0) {
                        return priority;
                    }
                } catch (e) {}
            }
            return null;
        },

        /**
         * Set the provider priority list.
         */
        setProviderPriority: function(priorityArray) {
            return this.setSetting('providerPriority', JSON.stringify(priorityArray));
        },

        /**
         * Check if a provider is available for use right now.
         * Cloud providers need an API key, server/local providers are always "available"
         * (actual reachability is checked at call time).
         */
        _isProviderAvailable: function(id) {
            if (id === 'local') return true;
            if (!this.isConnectorsMode()) {
                // Legacy mode: check localStorage keys
                if (id === 'anthropic') return !!(this.getSetting('anthropicApiKey'));
                if (id === 'openai') return !!(this.getSetting('openaiApiKey'));
                return false;
            }
            var config = aiAssistantProviders.available[id];
            if (!config) return false;
            if (config.type === 'server') return true;
            return !!(config.apiKey);
        },

        /**
         * Resolve the active provider using the priority list.
         * Walks the priority list and returns the first available provider.
         */
        _resolveProvider: function() {
            var priority = this.getProviderPriority();
            if (priority) {
                for (var i = 0; i < priority.length; i++) {
                    if (this._isProviderAvailable(priority[i])) {
                        return priority[i];
                    }
                }
            }

            // No priority set or no available provider in list — use defaults
            if (this.isConnectorsMode()) {
                var available = aiAssistantProviders.available;
                var ids = Object.keys(available);
                for (var i = 0; i < ids.length; i++) {
                    if (this._isProviderAvailable(ids[i])) {
                        return ids[i];
                    }
                }
                return ids[0] || 'anthropic';
            }

            return 'anthropic';
        },

        getProvider: function() {
            // Direct provider override (set by switching mid-session) takes precedence
            var override = this.getSetting('provider');
            if (override) {
                if (this.isConnectorsMode()) {
                    if (aiAssistantProviders.available[override] || override === 'local') {
                        return override;
                    }
                    this.removeSetting('provider');
                } else {
                    return override;
                }
            }

            return this._resolveProvider();
        },

        setProvider: function(provider) {
            return this.setSetting('provider', provider);
        },

        getModel: function() {
            var provider = this.getProvider();

            if (this.isConnectorsMode()) {
                var providerConfig = aiAssistantProviders.available[provider];
                if (providerConfig && providerConfig.models && providerConfig.models.length > 0) {
                    // Check if the stored model belongs to this provider
                    var override = this.getSetting('model');
                    if (override) {
                        var validForProvider = providerConfig.models.some(function(m) { return m.id === override; });
                        if (validForProvider) return override;
                    }
                    return providerConfig.models[0].id;
                }
            }

            var override = this.getSetting('model');
            if (override) return override;

            return 'claude-sonnet-4-20250514';
        },

        setModel: function(model) {
            return this.setSetting('model', model);
        },

        getSummarizationModel: function() {
            return this.getSetting('summarizationModel') || '';
        },

        setSummarizationModel: function(model) {
            return this.setSetting('summarizationModel', model);
        },

        getApiKey: function(provider) {
            provider = provider || this.getProvider();

            // In connectors mode, get API key from server-provided config
            if (this.isConnectorsMode()) {
                var providerConfig = aiAssistantProviders.available[provider];
                if (providerConfig && providerConfig.apiKey) {
                    return providerConfig.apiKey;
                }
                // Fall through to localStorage for local or unconfigured providers
            }

            if (provider === 'anthropic') {
                return this.getSetting('anthropicApiKey') || '';
            } else if (provider === 'openai') {
                return this.getSetting('openaiApiKey') || '';
            }
            return '';
        },

        setApiKey: function(provider, key) {
            if (provider === 'anthropic') {
                return this.setSetting('anthropicApiKey', key);
            } else if (provider === 'openai') {
                return this.setSetting('openaiApiKey', key);
            }
            return false;
        },

        getLocalEndpoint: function() {
            return this.getSetting('localEndpoint') || 'http://localhost:11434';
        },

        setLocalEndpoint: function(endpoint) {
            return this.setSetting('localEndpoint', endpoint);
        },

        getLocalModel: function() {
            return this.getSetting('localModel') || '';
        },

        setLocalModel: function(model) {
            return this.setSetting('localModel', model);
        },

        /**
         * Get the API endpoint URL for a provider.
         * In connectors mode, uses the server-resolved URL.
         */
        getProviderEndpoint: function(provider) {
            if (this.isConnectorsMode()) {
                var providerConfig = aiAssistantProviders.available[provider];
                if (providerConfig && providerConfig.endpoint) {
                    return providerConfig.endpoint;
                }
            }
            return null;
        },

        /**
         * Get all available models for a provider.
         * In connectors mode, returns models from the registry.
         */
        getAvailableModels: function(provider) {
            provider = provider || this.getProvider();
            if (this.isConnectorsMode()) {
                var providerConfig = aiAssistantProviders.available[provider];
                if (providerConfig && providerConfig.models) {
                    return providerConfig.models;
                }
            }
            return [];
        },

        /**
         * Get all available providers.
         * In connectors mode, returns providers from the registry.
         */
        getAvailableProviders: function() {
            if (this.isConnectorsMode()) {
                var providers = [];
                var available = aiAssistantProviders.available;
                Object.keys(available).forEach(function(id) {
                    providers.push({
                        id: id,
                        name: available[id].name,
                        type: available[id].type,
                        browserSupported: available[id].browserSupported
                    });
                });
                // Always include local option if a server-type provider exists
                if (aiAssistantProviders.hasLocal) {
                    var hasLocal = providers.some(function(p) { return p.type === 'server'; });
                    if (!hasLocal) {
                        providers.push({ id: 'local', name: 'Local LLM', type: 'server', browserSupported: true });
                    }
                }
                return providers;
            }
            return [
                { id: 'anthropic', name: 'Anthropic (Claude)', type: 'cloud', browserSupported: true },
                { id: 'openai', name: 'OpenAI (ChatGPT)', type: 'cloud', browserSupported: true },
                { id: 'local', name: 'Local LLM (Ollama/LM Studio)', type: 'server', browserSupported: true }
            ];
        },

        isConfigured: function() {
            var provider = this.getProvider();
            return this._isProviderAvailable(provider);
        },

        getAllSettings: function() {
            var settings = {
                provider: this.getProvider(),
                model: this.getModel(),
                summarizationModel: this.getSummarizationModel(),
                localEndpoint: this.getLocalEndpoint(),
                localModel: this.getLocalModel(),
                source: this.isConnectorsMode() ? 'connectors' : 'legacy'
            };

            // Mask API keys
            var apiKey = this.getApiKey();
            settings.apiKey = apiKey ? '***' + apiKey.slice(-4) : '';

            return settings;
        },

        clearAllSettings: function() {
            var keys = ['provider', 'model', 'summarizationModel', 'anthropicApiKey', 'openaiApiKey', 'localEndpoint', 'localModel'];
            var self = this;
            keys.forEach(function(key) {
                self.removeSetting(key);
            });
        }
    });

})(jQuery);
