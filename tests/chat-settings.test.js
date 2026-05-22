const { describe, it } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function createStorage(initial) {
    const values = Object.assign({}, initial || {});
    return {
        getItem(key) {
            return Object.prototype.hasOwnProperty.call(values, key) ? values[key] : null;
        },
        setItem(key, value) {
            values[key] = String(value);
        },
        removeItem(key) {
            delete values[key];
        }
    };
}

function loadSettingsMixin(options) {
    options = options || {};

    const aiAssistant = {};
    const providers = options.providers || { source: 'legacy' };
    const windowObject = { aiAssistant };
    if (options.browserStatusRegistry) {
        windowObject.wpAiProviderBrowserStatus = options.browserStatusRegistry;
    }
    const context = {
        window: windowObject,
        aiAssistantProviders: providers,
        localStorage: createStorage(options.storage),
        jQuery: {
            extend(target, source) {
                return Object.assign(target, source);
            }
        },
        console
    };

    vm.createContext(context);
    const source = fs.readFileSync(
        path.join(__dirname, '../assets/js/chat-settings.js'),
        'utf8'
    );
    vm.runInContext(source, context);
    return aiAssistant;
}

describe('chat settings model lifecycle', function() {
    it('uses the current Sonnet model as the legacy fallback', function() {
        const assistant = loadSettingsMixin({
            storage: {
                aiAssistant_anthropicApiKey: 'test-key'
            }
        });

        assert.strictEqual(assistant.getModel(), 'claude-sonnet-4-6');
    });

    it('uses an OpenAI model as the OpenAI fallback', function() {
        const assistant = loadSettingsMixin({
            storage: {
                aiAssistant_provider: 'openai',
                aiAssistant_openaiApiKey: 'test-key'
            }
        });

        assert.strictEqual(assistant.getModel(), 'gpt-5.5');
    });

    it('does not reuse a legacy Claude model for OpenAI', function() {
        const assistant = loadSettingsMixin({
            storage: {
                aiAssistant_provider: 'openai',
                aiAssistant_openaiApiKey: 'test-key',
                aiAssistant_model: 'claude-sonnet-4-6'
            }
        });

        assert.strictEqual(assistant.getModel(), 'gpt-5.5');
    });

    it('does not reuse a legacy OpenAI model for Anthropic', function() {
        const assistant = loadSettingsMixin({
            storage: {
                aiAssistant_provider: 'anthropic',
                aiAssistant_anthropicApiKey: 'test-key',
                aiAssistant_model: 'gpt-5.5'
            }
        });

        assert.strictEqual(assistant.getModel(), 'claude-sonnet-4-6');
    });

    it('keeps selected models separate by provider', function() {
        const assistant = loadSettingsMixin({
            storage: {
                aiAssistant_provider: 'openai',
                aiAssistant_openaiApiKey: 'test-key',
                aiAssistant_model_openai: 'gpt-5.5',
                aiAssistant_model_anthropic: 'claude-sonnet-4-6'
            }
        });

        assert.strictEqual(assistant.getModel(), 'gpt-5.5');

        assistant.setProvider('anthropic');

        assert.strictEqual(assistant.getModel(), 'claude-sonnet-4-6');
    });

    it('does not return a Claude fallback for local providers', function() {
        const assistant = loadSettingsMixin({
            storage: {
                aiAssistant_provider: 'local'
            }
        });

        assert.strictEqual(assistant.getModel(), '');
    });

    it('uses the saved model for a Connector-backed provider', function() {
        const assistant = loadSettingsMixin({
            providers: {
                source: 'connectors',
                available: {
                    anthropic: {
                        name: 'Anthropic',
                        type: 'cloud',
                        serverSideAuth: true,
                        models: [
                            { id: 'claude-sonnet-4-6', name: 'Claude Sonnet 4.6' },
                            { id: 'claude-sonnet-4-5-20250929', name: 'Claude Sonnet 4.5' }
                        ]
                    }
                }
            },
            storage: {
                aiAssistant_model_anthropic: 'claude-sonnet-4-5-20250929'
            }
        });

        assert.strictEqual(assistant.getModel(), 'claude-sonnet-4-5-20250929');
    });

    it('uses the recommended Connector model instead of blindly taking the registry order', function() {
        const assistant = loadSettingsMixin({
            providers: {
                source: 'connectors',
                available: {
                    anthropic: {
                        name: 'Anthropic',
                        type: 'cloud',
                        serverSideAuth: true,
                        models: [
                            { id: 'claude-sonnet-4-20250514', name: 'Claude Sonnet 4' },
                            { id: 'claude-sonnet-4-6', name: 'Claude Sonnet 4.6' }
                        ]
                    }
                }
            }
        });

        assert.strictEqual(assistant.getModel(), 'claude-sonnet-4-6');
    });

    it('ignores stale Connector model selections that are no longer available', function() {
        const assistant = loadSettingsMixin({
            providers: {
                source: 'connectors',
                available: {
                    anthropic: {
                        name: 'Anthropic',
                        type: 'cloud',
                        serverSideAuth: true,
                        models: [
                            { id: 'claude-sonnet-4-6', name: 'Claude Sonnet 4.6' }
                        ]
                    }
                }
            },
            storage: {
                aiAssistant_model_anthropic: 'claude-sonnet-4-20250514'
            }
        });

        assert.strictEqual(assistant.getModel(), 'claude-sonnet-4-6');
    });

    it('provides fallback model options for the inline model selector', function() {
        const assistant = loadSettingsMixin();

        const options = assistant.getModelOptions('anthropic', 'claude-sonnet-4-6');

        assert.strictEqual(
            JSON.stringify(options.map(option => option.id)),
            JSON.stringify([
                'claude-sonnet-4-6',
                'claude-sonnet-4-5-20250929',
                'claude-sonnet-4-20250514'
            ])
        );
    });

    it('keeps a selected custom model visible in the model selector', function() {
        const assistant = loadSettingsMixin();

        const options = assistant.getModelOptions('anthropic', 'claude-experimental-test');

        assert.strictEqual(options[0].id, 'claude-experimental-test');
    });

    it('applies inline model selections to the pending chat and provider setting', function() {
        const assistant = loadSettingsMixin({
            storage: {
                aiAssistant_anthropicApiKey: 'test-key'
            }
        });
        assistant.pendingNewChat = true;

        const changed = assistant.selectModelForCurrentChat('anthropic', 'claude-sonnet-4-5-20250929');

        assert.strictEqual(changed, true);
        assert.strictEqual(assistant.pendingNewChatProvider, 'anthropic');
        assert.strictEqual(assistant.pendingNewChatModel, 'claude-sonnet-4-5-20250929');
        assert.strictEqual(assistant.getModel(), 'claude-sonnet-4-5-20250929');
    });

    it('warns about deprecated Anthropic models with a recommended replacement', function() {
        const assistant = loadSettingsMixin();

        const upgrade = assistant.getModelUpgradeInfo('anthropic', 'claude-sonnet-4-20250514');

        assert.strictEqual(upgrade.provider, 'anthropic');
        assert.strictEqual(upgrade.model, 'claude-sonnet-4-20250514');
        assert.strictEqual(upgrade.severity, 'warning');
        assert.strictEqual(upgrade.status, 'deprecated');
        assert.strictEqual(upgrade.replacement, 'claude-sonnet-4-6');
        assert.strictEqual(upgrade.replacementName, 'claude-sonnet-4-6');
        assert.strictEqual(upgrade.retirement, 'June 15, 2026');
    });

    it('returns a note when a Claude model is only one or two available versions behind', function() {
        const assistant = loadSettingsMixin({
            providers: {
                source: 'connectors',
                available: {
                    anthropic: {
                        name: 'Anthropic',
                        type: 'cloud',
                        apiKey: 'test-key',
                        models: [
                            { id: 'claude-opus-4-7', name: 'Claude Opus 4.7' },
                            { id: 'claude-sonnet-4-6', name: 'Claude Sonnet 4.6' },
                            { id: 'claude-sonnet-4-5-20250929', name: 'Claude Sonnet 4.5' },
                            { id: 'claude-sonnet-4-4-20250701', name: 'Claude Sonnet 4.4' }
                        ]
                    }
                }
            }
        });

        const upgrade = assistant.getModelUpgradeInfo('anthropic', 'claude-sonnet-4-4-20250701');

        assert.strictEqual(upgrade.severity, 'note');
        assert.strictEqual(upgrade.status, 'newer_available');
        assert.strictEqual(upgrade.replacement, 'claude-sonnet-4-6');
        assert.strictEqual(upgrade.replacementName, 'Claude Sonnet 4.6');
    });

    it('warns when a Claude model is more than two available versions behind', function() {
        const assistant = loadSettingsMixin({
            providers: {
                source: 'connectors',
                available: {
                    anthropic: {
                        name: 'Anthropic',
                        type: 'cloud',
                        apiKey: 'test-key',
                        models: [
                            { id: 'claude-sonnet-4-6', name: 'Claude Sonnet 4.6' },
                            { id: 'claude-sonnet-4-5-20250929', name: 'Claude Sonnet 4.5' },
                            { id: 'claude-sonnet-4-4-20250701', name: 'Claude Sonnet 4.4' },
                            { id: 'claude-sonnet-4-3-20250601', name: 'Claude Sonnet 4.3' }
                        ]
                    }
                }
            }
        });

        const upgrade = assistant.getModelUpgradeInfo('anthropic', 'claude-sonnet-4-3-20250601');

        assert.strictEqual(upgrade.severity, 'warning');
        assert.strictEqual(upgrade.status, 'outdated');
        assert.strictEqual(upgrade.replacement, 'claude-sonnet-4-6');
        assert.strictEqual(upgrade.replacementName, 'Claude Sonnet 4.6');
    });

    it('does not warn for the newest available Claude model in the same family', function() {
        const assistant = loadSettingsMixin({
            providers: {
                source: 'connectors',
                available: {
                    anthropic: {
                        name: 'Anthropic',
                        type: 'cloud',
                        apiKey: 'test-key',
                        models: [
                            { id: 'claude-sonnet-4-6', name: 'Claude Sonnet 4.6' },
                            { id: 'claude-sonnet-4-5-20250929', name: 'Claude Sonnet 4.5' }
                        ]
                    }
                }
            }
        });

        assert.strictEqual(assistant.getModelUpgradeInfo('anthropic', 'claude-sonnet-4-6'), null);
    });

    it('falls through from an unreachable browser-local Connector provider', async function() {
        const assistant = loadSettingsMixin({
            providers: {
                source: 'connectors',
                available: {
                    lmstudio: {
                        name: 'LM Studio',
                        type: 'server',
                        browserSupported: true,
                        models: []
                    },
                    anthropic: {
                        name: 'Anthropic',
                        type: 'cloud',
                        serverSideAuth: true,
                        models: [
                            { id: 'claude-sonnet-4-6', name: 'Claude Sonnet 4.6' }
                        ]
                    }
                }
            },
            storage: {
                aiAssistant_providerPriority: JSON.stringify(['lmstudio', 'anthropic'])
            },
            browserStatusRegistry: {
                check(provider) {
                    assert.strictEqual(provider.id, 'lmstudio');
                    return Promise.resolve({
                        providerId: 'lmstudio',
                        reachable: false,
                        status: 'unreachable',
                        models: []
                    });
                }
            }
        });

        assert.strictEqual(assistant.getProvider(), 'lmstudio');

        await assistant.ensureBrowserProviderStatuses();

        assert.strictEqual(assistant.getProvider(), 'anthropic');
        assert.strictEqual(assistant.getModel(), 'claude-sonnet-4-6');
    });

    it('uses browser-discovered models for a reachable local Connector provider', async function() {
        const assistant = loadSettingsMixin({
            providers: {
                source: 'connectors',
                available: {
                    lmstudio: {
                        name: 'LM Studio',
                        type: 'server',
                        browserSupported: true,
                        models: []
                    },
                    anthropic: {
                        name: 'Anthropic',
                        type: 'cloud',
                        serverSideAuth: true,
                        models: [
                            { id: 'claude-sonnet-4-6', name: 'Claude Sonnet 4.6' }
                        ]
                    }
                }
            },
            storage: {
                aiAssistant_providerPriority: JSON.stringify(['lmstudio', 'anthropic'])
            },
            browserStatusRegistry: {
                check() {
                    return Promise.resolve({
                        providerId: 'lmstudio',
                        reachable: true,
                        status: 'ready',
                        models: [
                            { id: 'google/gemma-4-26b-a4b', name: 'Gemma 4 26B A4B' }
                        ]
                    });
                }
            }
        });

        await assistant.ensureBrowserProviderStatuses();

        assert.strictEqual(assistant.getProvider(), 'lmstudio');
        assert.strictEqual(assistant.getModel(), 'google/gemma-4-26b-a4b');
    });
});
