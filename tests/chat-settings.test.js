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
    const context = {
        window: { aiAssistant },
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
});
