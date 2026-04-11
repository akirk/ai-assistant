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
         * Get the first browser-supported provider from Connectors config.
         */
        _getConnectorsDefaultProvider: function() {
            if (!this.isConnectorsMode()) return null;
            var available = aiAssistantProviders.available;
            // Prefer providers we can call from the browser
            var ids = Object.keys(available);
            for (var i = 0; i < ids.length; i++) {
                if (available[ids[i]].browserSupported && available[ids[i]].apiKey) {
                    return ids[i];
                }
            }
            return ids[0] || null;
        },

        getProvider: function() {
            // localStorage override takes precedence (user's choice within this browser)
            var override = this.getSetting('provider');
            if (override) {
                // In connectors mode, validate the override is still available
                if (this.isConnectorsMode()) {
                    if (aiAssistantProviders.available[override] || override === 'local') {
                        return override;
                    }
                    // Override is stale (provider removed from Connectors), clear it
                    this.removeSetting('provider');
                } else {
                    return override;
                }
            }

            if (this.isConnectorsMode()) {
                return this._getConnectorsDefaultProvider() || 'anthropic';
            }

            return 'anthropic';
        },

        setProvider: function(provider) {
            return this.setSetting('provider', provider);
        },

        getModel: function() {
            var override = this.getSetting('model');
            if (override) return override;

            if (this.isConnectorsMode()) {
                var provider = this.getProvider();
                var providerConfig = aiAssistantProviders.available[provider];
                if (providerConfig && providerConfig.models && providerConfig.models.length > 0) {
                    return providerConfig.models[0].id;
                }
            }

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

            if (provider === 'local') {
                return true;
            }

            // In connectors mode, check if the provider has a key in the registry
            if (this.isConnectorsMode()) {
                var providerConfig = aiAssistantProviders.available[provider];
                if (providerConfig && providerConfig.apiKey) {
                    return true;
                }
            }

            var apiKey = this.getApiKey(provider);
            return apiKey && apiKey.length > 0;
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
