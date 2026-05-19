const { describe, it } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function loadExecutionMixin(config) {
    const aiAssistant = {};
    function jQueryStub() {
        return {
            remove() {},
            hide() {},
            show() {},
            length: 0
        };
    }
    jQueryStub.extend = function(target, source) {
        return Object.assign(target, source);
    };

    const context = {
        window: {
            aiAssistant,
            aiAssistantConfig: config || {}
        },
        aiAssistantConfig: config || {},
        URLSearchParams,
        jQuery: jQueryStub,
        console
    };

    vm.createContext(context);
    const source = fs.readFileSync(
        path.join(__dirname, '../assets/js/chat-execution.js'),
        'utf8'
    );
    vm.runInContext(source, context);
    return aiAssistant;
}

function createAssistant(overrides) {
    overrides = overrides || {};
    const assistant = loadExecutionMixin(overrides.config || {});
    const realCheckAllToolsResolved = assistant.checkAllToolsResolved.bind(assistant);
    const states = [];
    const useRealCheckAllToolsResolved = !!overrides.useRealCheckAllToolsResolved;
    delete overrides.useRealCheckAllToolsResolved;

    Object.assign(assistant, {
        yoloMode: false,
        pendingActions: [],
        pendingToolResults: [],
        pendingToolChecks: 0,
        processedToolIds: {},
        executingToolCount: 0,
        updateToolCardDescription() {},
        getActionDescription(toolName) {
            return toolName;
        },
        setToolCardState(id, state) {
            states.push({ id, state });
        },
        setLoading() {},
        showPendingActionsHeader() {},
        showToolApprovalModal() {},
        checkAllToolsResolved: useRealCheckAllToolsResolved ? realCheckAllToolsResolved : function() {},
        fetchAbilityDetailsForApproval() {
            return Promise.resolve({
                success: true,
                details: {
                    id: 'demo/read',
                    label: 'Demo Read',
                    description: 'Read demo data',
                    readonly: false,
                    destructive: false,
                    approved: false,
                    has_schema: false,
                    parameters: [],
                    raw_schema: ''
                }
            });
        },
        executeSingleTool() {
            throw new Error('Tool should not execute before confirmation');
        },
        _states: states
    }, overrides);

    delete assistant.config;

    return assistant;
}

function flushPromises() {
    return new Promise(resolve => setTimeout(resolve, 0));
}

describe('processToolCallImmediate', function() {
    it('requires confirmation before executing abilities', async function() {
        const assistant = createAssistant();

        assistant.processToolCallImmediate(
            'tool-1',
            'ability',
            { action: 'execute', ability: 'demo/read' },
            'anthropic'
        );

        await flushPromises();

        assert.deepStrictEqual(assistant._states, [
            { id: 'tool-1', state: 'checking' },
            { id: 'tool-1', state: 'pending' }
        ]);
        assert.strictEqual(assistant.pendingActions.length, 1);
        assert.strictEqual(assistant.pendingActions[0].tool, 'ability');
        assert.deepStrictEqual(assistant.pendingActions[0].arguments, {
            action: 'execute',
            ability: 'demo/read'
        });
    });

    it('still executes read-only ability discovery immediately', function() {
        let executed = false;
        const assistant = createAssistant({
            executeSingleTool() {
                executed = true;
                return Promise.resolve({
                    id: 'tool-2',
                    name: 'ability',
                    input: { action: 'list' },
                    result: { abilities: [] },
                    success: true
                });
            },
            checkAllToolsResolved() {}
        });

        assistant.processToolCallImmediate('tool-2', 'ability', { action: 'list' }, 'anthropic');

        assert.strictEqual(executed, true);
        assert.deepStrictEqual(assistant._states[0], { id: 'tool-2', state: 'executing' });
        assert.strictEqual(assistant.pendingActions.length, 0);
    });

    it('executes readonly abilities without confirmation', function() {
        let executed = false;
        const assistant = createAssistant({
            config: {
                enabledTools: ['execute_ability'],
                readonlyAbilities: ['demo/read']
            },
            executeSingleTool() {
                executed = true;
                return Promise.resolve({
                    id: 'tool-3',
                    name: 'ability',
                    input: { action: 'execute', ability: 'demo/read' },
                    result: { ok: true },
                    success: true
                });
            },
            checkAllToolsResolved() {}
        });

        assistant.processToolCallImmediate(
            'tool-3',
            'ability',
            { action: 'execute', ability: 'demo/read' },
            'anthropic'
        );

        assert.strictEqual(executed, true);
        assert.deepStrictEqual(assistant._states[0], { id: 'tool-3', state: 'executing' });
        assert.strictEqual(assistant.pendingActions.length, 0);
    });

    it('executes abilities immediately when all abilities are auto-approved', function() {
        let executed = false;
        const assistant = createAssistant({
            config: {
                autoApprovedAbilities: '*'
            },
            executeSingleTool(toolCall) {
                executed = true;
                return Promise.resolve({
                    id: toolCall.id,
                    name: toolCall.name,
                    input: toolCall.arguments,
                    result: { ok: true },
                    success: true
                });
            },
            checkAllToolsResolved() {}
        });

        assistant.processToolCallImmediate(
            'tool-auto-all',
            'ability',
            { action: 'execute', ability: 'demo/new-runtime-ability' },
            'anthropic'
        );

        assert.strictEqual(executed, true);
        assert.deepStrictEqual(assistant._states[0], { id: 'tool-auto-all', state: 'executing' });
        assert.strictEqual(assistant.pendingActions.length, 0);
    });

    it('requires confirmation before executing legacy ability calls', async function() {
        const assistant = createAssistant();

        assistant.processToolCallImmediate(
            'tool-4',
            'execute_ability',
            { ability: 'demo/write' },
            'anthropic'
        );

        await flushPromises();

        assert.deepStrictEqual(assistant._states, [
            { id: 'tool-4', state: 'checking' },
            { id: 'tool-4', state: 'pending' }
        ]);
        assert.strictEqual(assistant.pendingActions.length, 1);
        assert.strictEqual(assistant.pendingActions[0].tool, 'execute_ability');
    });

    it('does not auto-execute readonly abilities when execute_ability is disabled', async function() {
        const assistant = createAssistant({
            config: {
                enabledTools: ['list_abilities', 'get_ability'],
                readonlyAbilities: ['demo/read']
            }
        });

        assistant.processToolCallImmediate(
            'tool-5',
            'ability',
            { action: 'execute', ability: 'demo/read' },
            'anthropic'
        );

        await flushPromises();

        assert.deepStrictEqual(assistant._states, [
            { id: 'tool-5', state: 'checking' },
            { id: 'tool-5', state: 'pending' }
        ]);
        assert.strictEqual(assistant.pendingActions.length, 1);
    });

    it('denies missing abilities without asking for approval', async function() {
        const assistant = createAssistant({
            fetchAbilityDetailsForApproval() {
                return Promise.resolve({
                    success: false,
                    code: 'ability_not_found',
                    message: 'Ability not found: demo/missing'
                });
            }
        });

        assistant.processToolCallImmediate(
            'tool-6',
            'ability',
            { action: 'execute', ability: 'demo/missing' },
            'anthropic'
        );

        await flushPromises();

        assert.deepStrictEqual(assistant._states, [
            { id: 'tool-6', state: 'checking' },
            { id: 'tool-6', state: 'error' }
        ]);
        assert.strictEqual(assistant.pendingActions.length, 0);
        assert.strictEqual(assistant.pendingToolResults.length, 1);
        assert.strictEqual(assistant.pendingToolResults[0].result.code, 'ability_not_found');
    });

    it('does not resume the model while ability approval preflight is still pending', async function() {
        let resolvePreflight;
        let handled = false;
        const assistant = createAssistant({
            useRealCheckAllToolsResolved: true,
            streamComplete: true,
            fetchAbilityDetailsForApproval() {
                return new Promise(resolve => {
                    resolvePreflight = resolve;
                });
            },
            handleToolResults() {
                handled = true;
            }
        });

        assistant.processToolCallImmediate(
            'tool-7',
            'ability',
            { action: 'execute', ability: 'demo/write' },
            'anthropic'
        );

        assert.strictEqual(assistant.pendingToolChecks, 1);

        assistant.checkAllToolsResolved();

        assert.strictEqual(handled, false);

        resolvePreflight({
            success: true,
            details: {
                id: 'demo/write',
                label: 'Demo Write',
                description: 'Write demo data',
                readonly: false,
                destructive: true,
                approved: false,
                has_schema: false,
                parameters: []
            }
        });
        await flushPromises();

        assert.strictEqual(assistant.pendingToolChecks, 0);
        assert.strictEqual(assistant.pendingActions.length, 1);
        assert.strictEqual(handled, false);
    });

    it('rejects multiple immediate pick_image calls in one response', async function() {
        const executed = [];
        const assistant = createAssistant({
            executeSingleTool(toolCall) {
                executed.push(toolCall.id);
                return Promise.resolve({
                    id: toolCall.id,
                    name: toolCall.name,
                    input: toolCall.arguments,
                    result: { ok: true },
                    success: true
                });
            }
        });

        assistant.processToolCallImmediate('pick-1', 'pick_image', { query: 'mountains' }, 'openai');
        assistant.processToolCallImmediate('pick-2', 'pick_image', { query: 'forest' }, 'openai');

        await flushPromises();

        assert.deepStrictEqual(executed, ['pick-1']);
        assert.strictEqual(assistant.pendingToolResults.length, 2);
        assert.strictEqual(assistant.pendingToolResults[0].success, false);
        assert.strictEqual(assistant.pendingToolResults[0].result.code, 'multiple_pick_image_calls');
    });
});

describe('processToolCalls', function() {
    it('executes only the first pick_image call in a tool batch', function() {
        let executed = null;
        const assistant = createAssistant({
            executeTools(toolCalls) {
                executed = toolCalls;
            }
        });

        assistant.processToolCalls([
            { id: 'pick-1', name: 'pick_image', arguments: { query: 'mountains' } },
            { id: 'pick-2', name: 'pick_image', arguments: { query: 'forest' } }
        ], 'openai');

        assert.strictEqual(executed.length, 1);
        assert.strictEqual(executed[0].id, 'pick-1');
        assert.strictEqual(assistant.pendingToolResults.length, 1);
        assert.strictEqual(assistant.pendingToolResults[0].id, 'pick-2');
        assert.strictEqual(assistant.pendingToolResults[0].result.code, 'multiple_pick_image_calls');
    });
});

describe('auto-approved pending actions', function() {
    it('approves all pending actions with the same ability when Always approve is clicked', async function() {
        const executed = [];
        const handled = [];
        const assistant = createAssistant({
            pendingActions: [
                {
                    id: 'tool-1',
                    tool: 'ability',
                    arguments: {
                        action: 'execute',
                        ability: 'demo/write',
                        arguments: { slug: 'one' }
                    },
                    provider: 'anthropic'
                },
                {
                    id: 'tool-2',
                    tool: 'ability',
                    arguments: {
                        action: 'execute',
                        ability: 'demo/write',
                        arguments: { slug: 'two' }
                    },
                    provider: 'anthropic'
                },
                {
                    id: 'tool-3',
                    tool: 'ability',
                    arguments: {
                        action: 'execute',
                        ability: 'demo/other',
                        arguments: { slug: 'three' }
                    },
                    provider: 'anthropic'
                }
            ],
            executeSingleTool(action) {
                executed.push(action.id);
                return Promise.resolve({
                    id: action.id,
                    name: action.tool,
                    input: action.arguments,
                    result: { ok: true },
                    success: true
                });
            },
            handleToolResults(results, provider) {
                handled.push({ results, provider });
            },
            notifyToolCallCallbacks() {}
        });

        assistant.confirmMatchingAutoApprovedActions('tool-1');

        await flushPromises();
        await flushPromises();

        assert.deepStrictEqual(executed.sort(), ['tool-1', 'tool-2']);
        assert.deepStrictEqual(assistant.pendingActions.map(action => action.id), ['tool-3']);
        assert.strictEqual(assistant.executingToolCount, 0);
        assert.strictEqual(handled.length, 1);
        assert.strictEqual(handled[0].provider, 'anthropic');
        assert.deepStrictEqual(Array.from(handled[0].results, result => result.id).sort(), ['tool-1', 'tool-2']);
    });

    it('executes an ability after preflight if it became auto-approved while the check was pending', async function() {
        const config = { autoApprovedAbilities: [] };
        let resolvePreflight;
        const executed = [];
        const assistant = createAssistant({
            config,
            streamComplete: true,
            fetchAbilityDetailsForApproval() {
                return new Promise(resolve => {
                    resolvePreflight = resolve;
                });
            },
            executeSingleTool(action) {
                executed.push(action.id);
                return Promise.resolve({
                    id: action.id,
                    name: action.tool,
                    input: action.arguments,
                    result: { ok: true },
                    success: true
                });
            },
            handleToolResults(results) {
                this._handledResults = results;
            },
            notifyToolCallCallbacks() {}
        });

        assistant.processToolCallImmediate(
            'tool-4',
            'ability',
            { action: 'execute', ability: 'demo/write' },
            'anthropic'
        );

        config.autoApprovedAbilities.push('demo/write');
        resolvePreflight({
            success: true,
            details: {
                id: 'demo/write',
                label: 'Demo Write',
                description: 'Write demo data',
                readonly: false,
                destructive: true,
                approved: true,
                has_schema: false,
                parameters: []
            }
        });

        await flushPromises();
        await flushPromises();

        assert.deepStrictEqual(executed, ['tool-4']);
        assert.strictEqual(assistant.pendingActions.length, 0);
        assert.strictEqual(assistant.executingToolCount, 0);
        assert.strictEqual(assistant._handledResults.length, 1);
        assert.strictEqual(assistant._handledResults[0].id, 'tool-4');
    });
});

describe('tool call callbacks', function() {
    it('notifies subscribers after executing tools', async function() {
        const notifications = [];
        let handled = false;
        const assistant = createAssistant({
            executeSingleTool(toolCall) {
                return Promise.resolve({
                    id: toolCall.id,
                    name: toolCall.name,
                    input: toolCall.arguments,
                    result: { ok: true },
                    success: true
                });
            },
            notifyToolCallCallbacks(result, provider) {
                notifications.push({ result, provider });
            },
            handleToolResults() {
                handled = true;
            }
        });

        assistant.executeTools([
            {
                id: 'tool-1',
                name: 'ability',
                arguments: {
                    action: 'execute',
                    ability: 'my-apps/set-background-color'
                }
            }
        ], 'anthropic');

        await new Promise(resolve => setTimeout(resolve, 0));

        assert.strictEqual(notifications.length, 1);
        assert.strictEqual(notifications[0].result.name, 'ability');
        assert.strictEqual(notifications[0].provider, 'anthropic');
        assert.strictEqual(handled, true);
    });
});

describe('queued message handoff', function() {
    it('flushes queued messages after tool results before resuming the model', function() {
        let flushed = false;
        let called = false;
        const assistant = createAssistant({
            streamComplete: true,
            pendingToolResults: [],
            messages: [],
            toolCallRounds: 4,
            deduplicateFileReads() {},
            flushQueuedMessages(provider, options) {
                flushed = true;
                assert.strictEqual(provider, 'anthropic');
                assert.strictEqual(options.appendToLastToolResultMessage, true);
                return true;
            },
            updateTokenCount() {},
            autoSaveConversation() {},
            callLLM() {
                called = true;
            }
        });

        assistant.handleToolResults([
            {
                id: 'toolu_1',
                name: 'read_file',
                input: { path: 'demo.txt' },
                result: { content: 'Demo' },
                success: true
            }
        ], 'anthropic');

        assert.strictEqual(flushed, true);
        assert.strictEqual(called, true);
        assert.strictEqual(assistant.toolCallRounds, 0);
        assert.strictEqual(assistant.messages[0].role, 'user');
        assert.strictEqual(assistant.messages[0].content[0].tool_use_id, 'toolu_1');
    });
});

describe('tool call round limits', function() {
    function createRoundLimitAssistant(overrides) {
        overrides = overrides || {};
        let calls = 0;
        const assistant = createAssistant(Object.assign({
            streamComplete: true,
            pendingToolResults: [],
            messages: [],
            deduplicateFileReads() {},
            flushQueuedMessages() {
                return false;
            },
            updateTokenCount() {},
            autoSaveConversation() {},
            callLLM() {
                calls++;
            }
        }, overrides));
        assistant._getCallCount = function() {
            return calls;
        };
        return assistant;
    }

    it('does not stop at the old 10-round limit', function() {
        const assistant = createRoundLimitAssistant({
            toolCallRounds: 9
        });

        assistant.handleToolResults([
            {
                id: 'toolu_1',
                name: 'read_file',
                input: { path: 'demo.txt' },
                result: { content: 'Demo' },
                success: true
            }
        ], 'anthropic');

        assert.strictEqual(assistant.toolCallRounds, 10);
        assert.strictEqual(assistant.usesCodingToolWorkflow, true);
        assert.strictEqual(assistant.messages.length, 1);
        assert.strictEqual(assistant._getCallCount(), 1);
    });

    it('stops at the configured default round limit', function() {
        const assistant = createRoundLimitAssistant({
            config: {
                toolRoundLimits: {
                    default: 2,
                    coding: 10,
                    consecutiveFailures: 3
                }
            },
            toolCallRounds: 1
        });

        assistant.handleToolResults([
            {
                id: 'toolu_1',
                name: 'ability',
                input: { action: 'list' },
                result: { abilities: [] },
                success: true
            }
        ], 'anthropic');

        assert.strictEqual(assistant.toolCallRounds, 0);
        assert.strictEqual(assistant.messages.length, 2);
        assert.match(assistant.messages[1].content, /current tool call round limit of 2 rounds/);
        assert.strictEqual(assistant._getCallCount(), 1);
    });

    it('uses the larger coding limit after file or app-building tool rounds', function() {
        const assistant = createRoundLimitAssistant({
            config: {
                toolRoundLimits: {
                    default: 2,
                    coding: 4,
                    consecutiveFailures: 3
                }
            },
            toolCallRounds: 1
        });

        assistant.handleToolResults([
            {
                id: 'toolu_1',
                name: 'ability',
                input: { action: 'execute', ability: 'ai/create-wp-app' },
                result: { plugin_slug: 'weekly-schedule' },
                success: true
            }
        ], 'anthropic');

        assert.strictEqual(assistant.toolCallRounds, 2);
        assert.strictEqual(assistant.usesCodingToolWorkflow, true);
        assert.strictEqual(assistant.messages.length, 1);

        assistant.handleToolResults([
            {
                id: 'toolu_2',
                name: 'ability',
                input: { action: 'get', ability: 'ai/create-wp-app' },
                result: { id: 'ai/create-wp-app' },
                success: true
            }
        ], 'anthropic');

        assert.strictEqual(assistant.toolCallRounds, 3);
        assert.strictEqual(assistant.messages.length, 2);

        assistant.handleToolResults([
            {
                id: 'toolu_3',
                name: 'ability',
                input: { action: 'list' },
                result: { abilities: [] },
                success: true
            }
        ], 'anthropic');

        assert.strictEqual(assistant.toolCallRounds, 0);
        assert.strictEqual(assistant.messages.length, 4);
        assert.match(assistant.messages[3].content, /current tool call round limit of 4 rounds/);
    });

    it('stops quickly after consecutive failed tool rounds', function() {
        const assistant = createRoundLimitAssistant({
            config: {
                toolRoundLimits: {
                    default: 25,
                    coding: 50,
                    consecutiveFailures: 2
                }
            },
            consecutiveFailedToolRounds: 1
        });

        assistant.handleToolResults([
            {
                id: 'toolu_1',
                name: 'edit_file',
                input: { path: 'plugins/demo/demo.php' },
                result: { error: 'Malformed edits' },
                success: false
            }
        ], 'anthropic');

        assert.strictEqual(assistant.toolCallRounds, 0);
        assert.strictEqual(assistant.consecutiveFailedToolRounds, 0);
        assert.strictEqual(assistant.messages.length, 2);
        assert.match(assistant.messages[1].content, /failed for 2 consecutive rounds/);
    });
});

describe('activation wpok verification', function() {
    it('passes through activated plugin results when wpok succeeds', async function() {
        const assistant = createAssistant({
            verifyWpok() {
                return Promise.resolve(true);
            },
            emergencyDeactivateActivatedPlugin() {
                throw new Error('Should not deactivate when wpok succeeds');
            }
        });

        const results = await assistant.verifyActivatedPluginResults([
            {
                id: 'tool-1',
                name: 'install_plugin',
                input: { slug: 'akismet', activate: true },
                result: {
                    status: 'installed_and_activated',
                    plugin_file: 'akismet/akismet.php',
                    active: true
                },
                success: true
            }
        ]);

        assert.strictEqual(results[0].success, true);
        assert.strictEqual(results[0].result.status, 'installed_and_activated');
    });

    it('returns a failed recovered result when activation breaks WordPress', async function() {
        const assistant = createAssistant({
            verifyWpok() {
                return Promise.resolve(false);
            },
            emergencyDeactivateActivatedPlugin(candidate) {
                assert.strictEqual(candidate.plugin_slug, 'school-timetable');
                return Promise.resolve({
                    action: 'emergency_guarded',
                    plugin_file: 'school-timetable/school-timetable.php',
                    guarded_path: 'plugins/school-timetable/school-timetable.php'
                });
            }
        });

        const results = await assistant.verifyActivatedPluginResults([
            {
                id: 'tool-2',
                name: 'ability',
                input: {
                    action: 'execute',
                    ability: 'ai/create-wp-app'
                },
                result: {
                    result: {
                        plugin_slug: 'school-timetable',
                        activated: true
                    }
                },
                success: true
            }
        ]);

        assert.strictEqual(results[0].success, false);
        assert.match(results[0].result.error, /automatically emergency-disabled/);
        assert.strictEqual(results[0].result.recovery.action, 'emergency_guarded');
    });

    it('extracts activated plugin candidates from install_plugin and create-wp-app results', function() {
        const assistant = createAssistant();

        const installCandidate = assistant.getActivatedPluginCandidate({
            name: 'install_plugin',
            input: { slug: 'akismet', activate: true },
            result: {
                status: 'activated',
                plugin_file: 'akismet/akismet.php'
            },
            success: true
        });
        assert.strictEqual(installCandidate.plugin_slug, 'akismet');
        assert.strictEqual(installCandidate.plugin_file, 'akismet/akismet.php');

        const appCandidate = assistant.getActivatedPluginCandidate({
            name: 'ability',
            input: {
                action: 'execute',
                ability: 'ai/create-wp-app'
            },
            result: {
                result: {
                    plugin_slug: 'school-timetable',
                    activated: true
                }
            },
            success: true
        });
        assert.strictEqual(appCandidate.plugin_slug, 'school-timetable');
        assert.strictEqual(appCandidate.plugin_file, '');
    });
});

describe('plugin change recovery candidates', function() {
    it('normalizes a trailing invoke tag from edit_file edits before execution', async function() {
        let observedArgs = null;
        const assistant = createAssistant({
            yoloMode: true,
            executeSingleTool(toolCall) {
                observedArgs = toolCall.arguments;
                return Promise.resolve({
                    id: toolCall.id,
                    name: toolCall.name,
                    input: toolCall.arguments,
                    result: { path: 'plugins/test-plugin/test-plugin.php' },
                    success: true
                });
            },
            verifyPluginFileMutationResults(results) {
                return Promise.resolve(results);
            },
            checkAllToolsResolved() {}
        });

        assistant.processToolCallImmediate('tool-edit', 'edit_file', {
            path: 'plugins/test-plugin/test-plugin.php',
            edits: '[{"search":"Test Plugin","replace":"Modified Plugin"}]\n</invoke>',
            reason: 'Test edit'
        }, 'anthropic');

        await flushPromises();

        assert.deepStrictEqual(JSON.parse(JSON.stringify(observedArgs.edits)), [
            {
                search: 'Test Plugin',
                replace: 'Modified Plugin'
            }
        ]);
    });

    it('leaves non-invoke malformed edit_file edits untouched', function() {
        const assistant = createAssistant();
        const edits = '[{"search":"Test Plugin","replace":"Modified Plugin"}]\n<parameter name="reason">bad';

        const normalized = assistant.normalizeToolArguments('edit_file', {
            path: 'plugins/test-plugin/test-plugin.php',
            edits: edits
        });

        assert.strictEqual(normalized.edits, edits);
    });

    it('records plugin file mutations as deferred recovery candidates', function() {
        const assistant = createAssistant();

        assistant.rememberPluginRecoveryCandidateFromFileResult({
            name: 'edit_file',
            success: true,
            result: {
                path: 'plugins/school-timetable/src/App.php'
            }
        });

        assert.strictEqual(assistant.pendingPluginRecoveryCandidate.plugin_slug, 'school-timetable');
        assert.strictEqual(assistant.pendingPluginRecoveryCandidate.changed_path, 'plugins/school-timetable/src/App.php');
    });

    it('does not record ai-assistant plugin mutations as recovery candidates', function() {
        const assistant = createAssistant();

        assistant.rememberPluginRecoveryCandidateFromFileResult({
            name: 'edit_file',
            success: true,
            result: {
                path: 'plugins/ai-assistant/includes/class-settings.php'
            }
        });

        assert.strictEqual(assistant.pendingPluginRecoveryCandidate, undefined);
    });

    it('clears deferred candidates when wpok succeeds at a boundary', async function() {
        const assistant = createAssistant({
            verifyWpok() {
                return Promise.resolve(true);
            }
        });

        assistant.pendingPluginRecoveryCandidate = {
            plugin_slug: 'school-timetable',
            plugin_file: '',
            changed_path: 'plugins/school-timetable/src/App.php'
        };

        const result = await assistant.verifyPendingPluginRecoveryCandidate();

        assert.strictEqual(result, null);
        assert.strictEqual(assistant.pendingPluginRecoveryCandidate, null);
    });

    it('emergency-disables deferred candidates when wpok fails at a boundary', async function() {
        const assistant = createAssistant({
            verifyWpok() {
                return Promise.resolve(false);
            },
            emergencyDeactivateActivatedPlugin(candidate) {
                assert.strictEqual(candidate.plugin_slug, 'school-timetable');
                return Promise.resolve({
                    action: 'emergency_guarded',
                    plugin_file: 'school-timetable/school-timetable.php',
                    guarded_path: 'plugins/school-timetable/school-timetable.php'
                });
            }
        });

        assistant.pendingPluginRecoveryCandidate = {
            plugin_slug: 'school-timetable',
            plugin_file: '',
            changed_path: 'plugins/school-timetable/src/App.php'
        };

        const recovery = await assistant.verifyPendingPluginRecoveryCandidate();

        assert.strictEqual(recovery.guarded_path, 'plugins/school-timetable/school-timetable.php');
        assert.strictEqual(recovery.changed_path, 'plugins/school-timetable/src/App.php');
        assert.strictEqual(assistant.pendingPluginRecoveryCandidate, null);
    });

    it('returns recovery as the boundary tool result instead of running that tool', async function() {
        let executed = false;
        const assistant = createAssistant({
            verifyWpok() {
                return Promise.resolve(false);
            },
            emergencyDeactivateActivatedPlugin(candidate) {
                assert.strictEqual(candidate.plugin_slug, 'school-timetable');
                return Promise.resolve({
                    action: 'emergency_guarded',
                    plugin_file: 'school-timetable/school-timetable.php',
                    guarded_path: 'plugins/school-timetable/school-timetable.php'
                });
            },
            executeSingleTool() {
                executed = true;
                return Promise.resolve({
                    id: 'tool-run',
                    name: 'run_php',
                    input: { code: 'return true;' },
                    result: { result: true },
                    success: true
                });
            },
            handleToolResults(results) {
                this._handledResults = (this.pendingToolResults || []).concat(results);
            },
            notifyToolCallCallbacks() {}
        });

        assistant.pendingPluginRecoveryCandidate = {
            plugin_slug: 'school-timetable',
            plugin_file: '',
            changed_path: 'plugins/school-timetable/src/App.php'
        };

        assistant.executeTools([
            {
                id: 'tool-run',
                name: 'run_php',
                arguments: { code: 'return true;' }
            }
        ], 'anthropic');

        await flushPromises();
        await flushPromises();

        assert.strictEqual(executed, false);
        assert.strictEqual(assistant._handledResults[0].success, false);
        assert.strictEqual(assistant._handledResults[0].name, 'run_php');
        assert.strictEqual(assistant._handledResults[0].result.recovery.action, 'emergency_guarded');
        assert.strictEqual(assistant._handledResults[0].result.skipped, true);
    });

    it('runs the boundary tool when later edits have restored wpok', async function() {
        let executed = false;
        const assistant = createAssistant({
            verifyWpok() {
                return Promise.resolve(true);
            },
            emergencyDeactivateActivatedPlugin() {
                throw new Error('Should not emergency-disable when wpok succeeds');
            },
            executeSingleTool() {
                executed = true;
                return Promise.resolve({
                    id: 'tool-run',
                    name: 'run_php',
                    input: { code: 'return true;' },
                    result: { result: true },
                    success: true
                });
            },
            handleToolResults(results) {
                this._handledResults = (this.pendingToolResults || []).concat(results);
            },
            notifyToolCallCallbacks() {}
        });

        assistant.pendingPluginRecoveryCandidate = {
            plugin_slug: 'school-timetable',
            plugin_file: '',
            changed_path: 'plugins/school-timetable/src/App.php'
        };

        assistant.executeTools([
            {
                id: 'tool-run',
                name: 'run_php',
                arguments: { code: 'return true;' }
            }
        ], 'anthropic');

        await flushPromises();
        await flushPromises();

        assert.strictEqual(executed, true);
        assert.strictEqual(assistant._handledResults[0].success, true);
        assert.strictEqual(assistant.pendingPluginRecoveryCandidate, null);
    });

    it('emergency-disables immediately when a streamed plugin file edit breaks WordPress', async function() {
        let resolveHandled;
        const handledPromise = new Promise(resolve => {
            resolveHandled = resolve;
        });
        const assistant = createAssistant({
            yoloMode: true,
            streamComplete: true,
            useRealCheckAllToolsResolved: true,
            verifyWpok() {
                return Promise.resolve(false);
            },
            emergencyDeactivateActivatedPlugin(candidate) {
                assert.strictEqual(candidate.plugin_slug, 'school-timetable');
                return Promise.resolve({
                    action: 'emergency_guarded',
                    plugin_file: 'school-timetable/school-timetable.php',
                    guarded_path: 'plugins/school-timetable/school-timetable.php'
                });
            },
            executeSingleTool(toolCall) {
                return Promise.resolve({
                    id: toolCall.id,
                    name: toolCall.name,
                    input: toolCall.arguments,
                    result: {
                        path: 'plugins/school-timetable/src/App.php',
                        edits_applied: 1
                    },
                    success: true
                });
            },
            handleToolResults(results) {
                this._handledResults = (this.pendingToolResults || []).concat(results);
                resolveHandled(this._handledResults);
            },
            notifyToolCallCallbacks() {}
        });

        assistant.processToolCallImmediate('tool-edit', 'edit_file', {
            path: 'plugins/school-timetable/src/App.php',
            edits: [
                {
                    search: 'old',
                    replace: 'new'
                }
            ],
            reason: 'Test edit'
        }, 'anthropic');

        const handledResults = await Promise.race([
            handledPromise,
            new Promise((resolve, reject) => setTimeout(function() {
                reject(new Error('handleToolResults was not called'));
            }, 100))
        ]);

        assert.strictEqual(handledResults[0].success, false);
        assert.match(handledResults[0].result.error, /automatically emergency-disabled/);
        assert.strictEqual(handledResults[0].result.recovery.action, 'emergency_guarded');
        assert.strictEqual(assistant.pendingPluginRecoveryCandidate, null);
    });

    it('treats WordPress-backed tools as recovery boundaries', function() {
        const assistant = createAssistant({
            config: {
                fileToolsUrl: '/file-tools.php',
                fileToolsToken: 'token'
            }
        });

        assert.strictEqual(assistant.isWordPressBackedToolCall({ name: 'run_php' }), true);
        assert.strictEqual(assistant.isWordPressBackedToolCall({ name: 'ability' }), true);
        assert.strictEqual(assistant.isWordPressBackedToolCall({ name: 'edit_file' }), false);
        assert.strictEqual(assistant.isWordPressBackedToolCall({ name: 'get_page_html' }), false);
    });
});

describe('executePickImage', function() {
    it('preserves picker failure status when upload cannot complete', async function() {
        const assistant = loadExecutionMixin();
        assistant.renderImagePicker = function(toolId, args, onSelect) {
            assert.strictEqual(toolId, 'tool-pick');
            assert.deepStrictEqual(args, { query: 'mountains' });
            onSelect({ error: 'Could not upload selected image' }, false);
        };

        const result = await assistant.executePickImage({
            id: 'tool-pick',
            name: 'pick_image',
            arguments: { query: 'mountains' }
        });

        assert.strictEqual(result.success, false);
        assert.strictEqual(result.result.error, 'Could not upload selected image');
    });
});

describe('REST API descriptions and results', function() {
    it('does not describe an empty rest_api call as GET /', function() {
        const assistant = loadExecutionMixin();

        assert.strictEqual(
            assistant.getActionDescription('rest_api', {}),
            'REST API request'
        );
    });

    it('describes populated rest_api calls with the requested method and path', function() {
        const assistant = loadExecutionMixin();

        assert.strictEqual(
            assistant.getActionDescription('rest_api', {
                method: 'POST',
                path: '/wp/v2/posts'
            }),
            'POST /wp/v2/posts'
        );
    });

    it('adds edit and view URLs to created post REST API results', function() {
        const assistant = loadExecutionMixin({
            adminUrl: 'http://example.test/wp-admin/'
        });

        const result = assistant.enrichRestApiResult(
            { id: 123, link: 'http://example.test/?p=123' },
            'POST',
            '/wp/v2/posts'
        );

        assert.strictEqual(
            result.edit_url,
            'http://example.test/wp-admin/post.php?post=123&action=edit'
        );
        assert.strictEqual(result.view_url, 'http://example.test/?p=123');
    });
});
