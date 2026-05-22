(function($) {
    'use strict';

    var STORAGE_PREFIX = 'aiAssistant_';
    var DEFAULT_MODELS = {
        anthropic: 'claude-sonnet-4-6',
        openai: 'gpt-5.5'
    };
    var PREFERRED_MODELS = {
        anthropic: [
            'claude-sonnet-4-6',
            'claude-sonnet-4-5-20250929',
            'claude-sonnet-4-20250514'
        ],
        openai: [
            'gpt-5.5',
            'gpt-5.4',
            'gpt-5.4-mini',
            'gpt-5.1',
            'gpt-5',
            'gpt-4.1',
            'gpt-4o'
        ]
    };
    var MODEL_REPLACEMENTS = {
        anthropic: {
            'claude-sonnet-4-20250514': {
                replacement: 'claude-sonnet-4-6',
                status: 'deprecated',
                retirement: 'June 15, 2026'
            },
            'claude-opus-4-20250514': {
                replacement: 'claude-opus-4-7',
                status: 'deprecated',
                retirement: 'June 15, 2026'
            },
            'claude-3-7-sonnet-20250219': {
                replacement: 'claude-sonnet-4-6',
                status: 'retired'
            },
            'claude-3-5-sonnet-20241022': {
                replacement: 'claude-sonnet-4-6',
                status: 'retired'
            },
            'claude-3-5-sonnet-20240620': {
                replacement: 'claude-sonnet-4-6',
                status: 'retired'
            },
            'claude-3-sonnet-20240229': {
                replacement: 'claude-sonnet-4-6',
                status: 'retired'
            },
            'claude-3-5-haiku-20241022': {
                replacement: 'claude-haiku-4-5-20251001',
                status: 'retired'
            },
            'claude-3-haiku-20240307': {
                replacement: 'claude-haiku-4-5-20251001',
                status: 'retired'
            },
            'claude-3-opus-20240229': {
                replacement: 'claude-opus-4-7',
                status: 'retired'
            }
        }
    };

    function normalizeModelId(model) {
        return String(model || '').trim();
    }

    function getModelSettingKey(provider) {
        provider = normalizeModelId(provider);
        return provider ? 'model_' + provider : 'model';
    }

    function isOpenAIModelId(model) {
        model = normalizeModelId(model);
        return /^gpt-\d/.test(model) ||
            /^o\d/.test(model) ||
            model.indexOf('chatgpt-') === 0 ||
            model.indexOf('computer-use-preview') === 0 ||
            /^ft:(gpt-\d|o\d)/.test(model);
    }

    function isAnthropicModelId(model) {
        return normalizeModelId(model).indexOf('claude-') === 0;
    }

    function isModelCompatibleWithProvider(provider, model) {
        provider = normalizeModelId(provider);
        model = normalizeModelId(model);
        if (!model) return false;

        if (provider === 'anthropic') {
            return isAnthropicModelId(model);
        }

        if (provider === 'openai') {
            return isOpenAIModelId(model);
        }

        if (provider === 'local') {
            return !isAnthropicModelId(model) && !isOpenAIModelId(model);
        }

        return true;
    }

    function hasModel(models, id) {
        id = normalizeModelId(id);
        if (!id || !Array.isArray(models)) return false;

        return models.some(function(model) {
            return normalizeModelId(model && model.id) === id;
        });
    }

    function getRecommendedModel(provider, models) {
        models = Array.isArray(models) ? models : [];
        var preferred = PREFERRED_MODELS[provider] || [];

        for (var i = 0; i < preferred.length; i++) {
            if (hasModel(models, preferred[i])) {
                return preferred[i];
            }
        }

        if (DEFAULT_MODELS[provider] && hasModel(models, DEFAULT_MODELS[provider])) {
            return DEFAULT_MODELS[provider];
        }

        return models.length > 0 ? normalizeModelId(models[0].id) : (DEFAULT_MODELS[provider] || '');
    }

    function parseClaudeModelId(model) {
        var id = normalizeModelId(model);
        var familyMatch = id.match(/^claude-(opus|sonnet|haiku)-(.+)$/);
        var versionParts;
        var date = 0;

        if (familyMatch) {
            versionParts = familyMatch[2].split('-');
            if (/^\d{8}$/.test(versionParts[versionParts.length - 1])) {
                date = Number(versionParts.pop());
            }
            return {
                family: familyMatch[1],
                version: versionParts.map(function(part) {
                    return /^\d+$/.test(part) ? Number(part) : 0;
                }),
                date: date
            };
        }

        var legacyMatch = id.match(/^claude-(\d+)(?:-(\d+))?-(opus|sonnet|haiku)(?:-(\d{8}))?$/);
        if (!legacyMatch) return null;

        versionParts = [Number(legacyMatch[1])];
        if (legacyMatch[2]) {
            versionParts.push(Number(legacyMatch[2]));
        }

        return {
            family: legacyMatch[3],
            version: versionParts,
            date: legacyMatch[4] ? Number(legacyMatch[4]) : 0
        };
    }

    function compareClaudeModels(a, b) {
        var maxLength = Math.max(a.version.length, b.version.length);
        for (var i = 0; i < maxLength; i++) {
            var aPart = a.version[i] || 0;
            var bPart = b.version[i] || 0;
            if (aPart !== bPart) {
                return aPart > bPart ? 1 : -1;
            }
        }
        if (a.date !== b.date) {
            return a.date > b.date ? 1 : -1;
        }
        return 0;
    }

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
            // Must have at least one model to be usable
            if (!config.models || config.models.length === 0) return false;
            if (config.type === 'server') return true;
            return !!(config.serverSideAuth || config.apiKey);
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
            if (this.isConnectorsMode()) {
                // In Connectors mode, ignore localStorage overrides — use priority list
                return this._resolveProvider();
            }

            // Legacy mode: direct provider override takes precedence
            var override = this.getSetting('provider');
            if (override) {
                return override;
            }

            return this._resolveProvider();
        },

        setProvider: function(provider) {
            return this.setSetting('provider', provider);
        },

        getDefaultModel: function(provider) {
            provider = provider || this.getProvider();
            return DEFAULT_MODELS[provider] || '';
        },

        getModelSettingKey: function(provider) {
            return getModelSettingKey(provider || this.getProvider());
        },

        isModelCompatibleWithProvider: function(provider, model) {
            return isModelCompatibleWithProvider(provider || this.getProvider(), model);
        },

        getModel: function() {
            var provider = this.getProvider();

            if (this.isConnectorsMode()) {
                var providerConfig = aiAssistantProviders.available[provider];
                if (providerConfig && providerConfig.models && providerConfig.models.length > 0) {
                    var connectorModels = providerConfig.models;
                    var connectorOverride = this.getSetting(getModelSettingKey(provider));
                    if (hasModel(connectorModels, connectorOverride)) {
                        return connectorOverride;
                    }

                    if (provider === 'local') {
                        var connectorLocalModel = this.getLocalModel();
                        if (hasModel(connectorModels, connectorLocalModel)) {
                            return connectorLocalModel;
                        }
                    }

                    var connectorLegacyOverride = this.getSetting('model');
                    if (
                        isModelCompatibleWithProvider(provider, connectorLegacyOverride) &&
                        hasModel(connectorModels, connectorLegacyOverride)
                    ) {
                        return connectorLegacyOverride;
                    }

                    return getRecommendedModel(provider, connectorModels);
                }
            }

            var providerOverride = this.getSetting(getModelSettingKey(provider));
            if (providerOverride) return providerOverride;

            if (provider === 'local') {
                var localModel = this.getLocalModel();
                if (localModel) return localModel;
            }

            var legacyOverride = this.getSetting('model');
            if (isModelCompatibleWithProvider(provider, legacyOverride)) {
                return legacyOverride;
            }

            return this.getDefaultModel(provider);
        },

        setModel: function(model, provider) {
            provider = provider || this.getProvider();
            this.setSetting(getModelSettingKey(provider), model);
            if (provider === 'local') {
                this.setLocalModel(model);
            }
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
                // Connector-backed cloud keys stay server-side and are used via the LLM proxy.
                if (providerConfig && providerConfig.serverSideAuth) {
                    return '';
                }
                // Fall through to localStorage for local or unconfigured providers.
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

        getModelDisplayName: function(provider, model) {
            var id = normalizeModelId(model);
            var models = this.getAvailableModels(provider);
            for (var i = 0; i < models.length; i++) {
                if (normalizeModelId(models[i].id) === id) {
                    return models[i].name || models[i].id;
                }
            }
            return id;
        },

        getNewerAvailableModels: function(provider, model) {
            provider = provider || this.getProvider();
            model = normalizeModelId(model);
            if (provider !== 'anthropic' || !model) return [];

            var current = parseClaudeModelId(model);
            if (!current) return [];

            var models = this.getAvailableModels(provider);
            var newer = [];

            for (var i = 0; i < models.length; i++) {
                var candidateId = normalizeModelId(models[i].id);
                if (!candidateId || candidateId === model) continue;

                var candidate = parseClaudeModelId(candidateId);
                if (!candidate || candidate.family !== current.family) continue;
                if (compareClaudeModels(candidate, current) <= 0) continue;

                newer.push({
                    id: candidateId,
                    name: models[i].name || candidateId,
                    parsed: candidate
                });
            }

            newer.sort(function(a, b) {
                return compareClaudeModels(b.parsed, a.parsed);
            });

            return newer.map(function(candidate) {
                return {
                    id: candidate.id,
                    name: candidate.name
                };
            });
        },

        getNewestAvailableModel: function(provider, model) {
            var newer = this.getNewerAvailableModels(provider, model);
            return newer.length > 0 ? newer[0] : null;
        },

        getModelUpgradeInfo: function(provider, model) {
            provider = provider || this.getProvider();
            model = normalizeModelId(model);
            if (!provider || !model) return null;

            var replacements = MODEL_REPLACEMENTS[provider] || {};
            var replacement = replacements[model];
            if (replacement) {
                return {
                    provider: provider,
                    model: model,
                    severity: 'warning',
                    status: replacement.status,
                    replacement: replacement.replacement,
                    replacementName: this.getModelDisplayName(provider, replacement.replacement),
                    retirement: replacement.retirement || ''
                };
            }

            var newerModels = this.getNewerAvailableModels(provider, model);
            if (newerModels.length === 0) return null;

            var newer = newerModels[0];
            var isOutdated = newerModels.length > 2;

            return {
                provider: provider,
                model: model,
                severity: isOutdated ? 'warning' : 'note',
                status: isOutdated ? 'outdated' : 'newer_available',
                replacement: newer.id,
                replacementName: newer.name,
                retirement: ''
            };
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
            var providerConfig = this.isConnectorsMode() && typeof aiAssistantProviders !== 'undefined'
                ? aiAssistantProviders.available[settings.provider]
                : null;
            settings.apiKey = apiKey ? '***' + apiKey.slice(-4) : (providerConfig && providerConfig.serverSideAuth ? 'server-side' : '');

            return settings;
        },

        clearAllSettings: function() {
            var keys = [
                'provider',
                'providerPriority',
                'model',
                'model_anthropic',
                'model_openai',
                'model_local',
                'summarizationModel',
                'anthropicApiKey',
                'openaiApiKey',
                'localEndpoint',
                'localModel'
            ];
            var self = this;
            keys.forEach(function(key) {
                self.removeSetting(key);
            });

            if (this.isConnectorsMode()) {
                Object.keys(aiAssistantProviders.available || {}).forEach(function(provider) {
                    self.removeSetting(getModelSettingKey(provider));
                });
            }
        }
    });

})(jQuery);
