const { describe, it } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function loadCore(options) {
    options = options || {};

    function jQueryStub() {
        return {
            ready() {}
        };
    }
    jQueryStub.extend = function(target, source) {
        return Object.assign(target, source);
    };

    const context = {
        window: {
            aiAssistantToolCallbacks: options.queuedCallbacks || []
        },
        document: {},
        jQuery: jQueryStub,
        console
    };

    vm.createContext(context);
    const source = fs.readFileSync(
        path.join(__dirname, '../assets/js/chat-core.js'),
        'utf8'
    );
    vm.runInContext(source, context);
    return context.window.aiAssistant;
}

describe('tool call callback API', function() {
    it('runs callbacks that match completed ability executions', function() {
        const assistant = loadCore();
        const calls = [];

        assistant.onToolCall(
            { ability: 'my-apps/set-background-color', success: true },
            function(context) {
                calls.push(context);
            }
        );

        assistant.notifyToolCallCallbacks({
            id: 'tool-1',
            name: 'ability',
            input: {
                action: 'execute',
                ability: 'my-apps/set-background-color',
                arguments: { color: '#112233' }
            },
            result: { ok: true },
            success: true
        }, 'anthropic');

        assert.strictEqual(calls.length, 1);
        assert.strictEqual(calls[0].tool, 'ability');
        assert.strictEqual(calls[0].arguments.arguments.color, '#112233');
        assert.deepStrictEqual(calls[0].result, { ok: true });
    });

    it('does not run ability shorthand callbacks for schema lookups', function() {
        const assistant = loadCore();
        let count = 0;

        assistant.onToolCall({ ability: 'my-apps/set-background-color' }, function() {
            count++;
        });

        assistant.notifyToolCallCallbacks({
            id: 'tool-1',
            name: 'ability',
            input: {
                action: 'get',
                ability: 'my-apps/set-background-color'
            },
            result: { name: 'my-apps/set-background-color' },
            success: true
        }, 'anthropic');

        assert.strictEqual(count, 0);
    });

    it('matches nested argument subsets', function() {
        const assistant = loadCore();
        let matched = false;

        assistant.onToolCall({
            tool: 'ability',
            arguments: {
                action: 'execute',
                arguments: { color: '#112233' }
            }
        }, function() {
            matched = true;
        });

        assistant.notifyToolCallCallbacks({
            id: 'tool-1',
            name: 'ability',
            input: {
                action: 'execute',
                ability: 'my-apps/set-background-color',
                arguments: {
                    color: '#112233',
                    source: 'assistant'
                }
            },
            result: { ok: true },
            success: true
        }, 'anthropic');

        assert.strictEqual(matched, true);
    });

    it('supports unregistering callbacks', function() {
        const assistant = loadCore();
        let count = 0;

        const unregister = assistant.onToolCall('read_file', function() {
            count++;
        });
        unregister();

        assistant.notifyToolCallCallbacks({
            id: 'tool-1',
            name: 'read_file',
            input: { path: 'plugins/demo/demo.php' },
            result: { content: '<?php' },
            success: true
        }, 'anthropic');

        assert.strictEqual(count, 0);
    });

    it('replays callbacks queued before chat-core loads', function() {
        const calls = [];
        const assistant = loadCore({
            queuedCallbacks: [
                {
                    criteria: { ability: 'my-apps/set-background-color' },
                    callback: function(context) {
                        calls.push(context.id);
                    }
                }
            ]
        });

        assistant.notifyToolCallCallbacks({
            id: 'tool-1',
            name: 'ability',
            input: {
                action: 'execute',
                ability: 'my-apps/set-background-color'
            },
            result: { ok: true },
            success: true
        }, 'anthropic');

        assert.deepStrictEqual(calls, ['tool-1']);
    });
});

describe('conversation preloading', function() {
    it('loads the most recent conversation once for non-full-page panels', function() {
        const assistant = loadCore();
        let loadCount = 0;

        assistant.isFullPage = false;
        assistant.loadMostRecentConversation = function() {
            loadCount++;
        };

        assistant.preloadMostRecentConversation();
        assistant.preloadMostRecentConversation();

        assert.strictEqual(loadCount, 1);
        assert.strictEqual(assistant.conversationPreloaded, true);
    });

    it('does not preload conversations on the full-page UI', function() {
        const assistant = loadCore();
        let loadCount = 0;

        assistant.isFullPage = true;
        assistant.loadMostRecentConversation = function() {
            loadCount++;
        };

        assistant.preloadMostRecentConversation();

        assert.strictEqual(loadCount, 0);
        assert.strictEqual(assistant.conversationPreloaded, false);
    });
});

describe('plugin activation recovery candidates', function() {
    it('records explicit activation requests before execution finishes', function() {
        const assistant = loadCore();

        assistant.recordPotentialPluginActivation({
            name: 'ability',
            arguments: {
                action: 'execute',
                ability: 'create-wp-app/scaffold',
                arguments: {
                    slug: 'school-timetable',
                    activate: true
                }
            }
        });

        const candidate = assistant.getRecentActivationRecoveryCandidate();
        assert.ok(candidate);
        assert.strictEqual(candidate.pluginSlug, 'school-timetable');
        assert.strictEqual(candidate.pending, true);
        assert.strictEqual(candidate.sourceTool, 'ability:create-wp-app/scaffold');
    });

    it('records create-wp-app activations for recovery', function() {
        const assistant = loadCore();

        assistant.recordRecentPluginActivation({
            name: 'ability',
            success: true,
            input: {
                action: 'execute',
                ability: 'create-wp-app/scaffold'
            },
            result: {
                result: {
                    plugin_slug: 'school-timetable',
                    activated: true
                }
            }
        });

        const candidate = assistant.getRecentActivationRecoveryCandidate();
        assert.ok(candidate);
        assert.strictEqual(candidate.pluginSlug, 'school-timetable');
        assert.strictEqual(candidate.sourceTool, 'ability:create-wp-app/scaffold');
    });

    it('records install_plugin activations for recovery', function() {
        const assistant = loadCore();

        assistant.recordRecentPluginActivation({
            name: 'install_plugin',
            success: true,
            input: {
                slug: 'akismet',
                activate: true
            },
            result: {
                status: 'installed_and_activated',
                plugin_file: 'akismet/akismet.php',
                active: true
            }
        });

        const candidate = assistant.getRecentActivationRecoveryCandidate();
        assert.ok(candidate);
        assert.strictEqual(candidate.pluginSlug, 'akismet');
        assert.strictEqual(candidate.pluginFile, 'akismet/akismet.php');
        assert.strictEqual(candidate.sourceTool, 'install_plugin');
    });

    it('clears a pending candidate when activation completes without activating', function() {
        const assistant = loadCore();

        assistant.recordPotentialPluginActivation({
            name: 'install_plugin',
            arguments: {
                slug: 'demo',
                activate: true
            }
        });
        assert.ok(assistant.getRecentActivationRecoveryCandidate());

        assistant.recordRecentPluginActivation({
            name: 'install_plugin',
            success: true,
            input: {
                slug: 'demo',
                activate: true
            },
            result: {
                status: 'installed',
                plugin_file: 'demo/demo.php',
                active: false
            }
        });

        assert.strictEqual(assistant.getRecentActivationRecoveryCandidate(), null);
    });

    it('clears activation recovery when a later file mutation starts', function() {
        const assistant = loadCore();

        assistant.recordPotentialPluginActivation({
            name: 'install_plugin',
            arguments: {
                slug: 'demo',
                activate: true
            }
        });
        assert.ok(assistant.getRecentActivationRecoveryCandidate());

        assistant.clearPluginActivationCandidateForToolCall({
            name: 'edit_file',
            arguments: {
                path: 'plugins/demo/demo.php'
            }
        });

        assert.strictEqual(assistant.getRecentActivationRecoveryCandidate(), null);
    });

    it('does not record inactive or ai-assistant activation results', function() {
        const assistant = loadCore();

        assistant.recordRecentPluginActivation({
            name: 'install_plugin',
            success: true,
            input: {
                slug: 'demo',
                activate: false
            },
            result: {
                status: 'installed',
                plugin_file: 'demo/demo.php',
                active: false
            }
        });
        assert.strictEqual(assistant.getRecentActivationRecoveryCandidate(), null);

        assistant.recordRecentPluginActivation({
            name: 'ability',
            success: true,
            input: {
                action: 'execute',
                ability: 'create-wp-app/scaffold'
            },
            result: {
                result: {
                    plugin_slug: 'ai-assistant',
                    activated: true
                }
            }
        });
        assert.strictEqual(assistant.getRecentActivationRecoveryCandidate(), null);
    });
});
