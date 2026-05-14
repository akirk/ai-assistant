const { describe, it } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function loadExecutionMixin(config) {
    const aiAssistant = {};
    const context = {
        window: {
            aiAssistant,
            aiAssistantConfig: config || {}
        },
        aiAssistantConfig: config || {},
        URLSearchParams,
        jQuery: {
            extend(target, source) {
                return Object.assign(target, source);
            }
        },
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

describe('activation health verification', function() {
    it('passes through activated plugin results when WordPress health succeeds', async function() {
        const assistant = createAssistant({
            verifyWordPressHealth() {
                return Promise.resolve(true);
            },
            emergencyDeactivateActivatedPlugin() {
                throw new Error('Should not deactivate healthy activation');
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
            verifyWordPressHealth() {
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
                    ability: 'create-wp-app/scaffold'
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
                ability: 'create-wp-app/scaffold'
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

    it('clears deferred candidates when WordPress health succeeds at a boundary', async function() {
        const assistant = createAssistant({
            verifyWordPressHealth() {
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

    it('emergency-disables deferred candidates when WordPress health fails at a boundary', async function() {
        const assistant = createAssistant({
            verifyWordPressHealth() {
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
            verifyWordPressHealth() {
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
                this._handledResults = results;
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

    it('runs the boundary tool when later edits have restored WordPress health', async function() {
        let executed = false;
        const assistant = createAssistant({
            verifyWordPressHealth() {
                return Promise.resolve(true);
            },
            emergencyDeactivateActivatedPlugin() {
                throw new Error('Should not emergency-disable a healthy plugin');
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
                this._handledResults = results;
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
