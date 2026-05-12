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
    const states = [];

    Object.assign(assistant, {
        yoloMode: false,
        pendingActions: [],
        pendingToolResults: [],
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
        executeSingleTool() {
            throw new Error('Tool should not execute before confirmation');
        },
        _states: states
    }, overrides);

    delete assistant.config;

    return assistant;
}

describe('processToolCallImmediate', function() {
    it('requires confirmation before executing abilities', function() {
        const assistant = createAssistant();

        assistant.processToolCallImmediate(
            'tool-1',
            'ability',
            { action: 'execute', ability: 'demo/read' },
            'anthropic'
        );

        assert.deepStrictEqual(assistant._states, [
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

    it('requires confirmation before executing legacy ability calls', function() {
        const assistant = createAssistant();

        assistant.processToolCallImmediate(
            'tool-4',
            'execute_ability',
            { ability: 'demo/write' },
            'anthropic'
        );

        assert.deepStrictEqual(assistant._states, [
            { id: 'tool-4', state: 'pending' }
        ]);
        assert.strictEqual(assistant.pendingActions.length, 1);
        assert.strictEqual(assistant.pendingActions[0].tool, 'execute_ability');
    });

    it('does not auto-execute readonly abilities when execute_ability is disabled', function() {
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

        assert.deepStrictEqual(assistant._states, [
            { id: 'tool-5', state: 'pending' }
        ]);
        assert.strictEqual(assistant.pendingActions.length, 1);
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
