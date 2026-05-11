const { describe, it } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function loadExecutionMixin() {
    const aiAssistant = {};
    const context = {
        window: { aiAssistant },
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
    const assistant = loadExecutionMixin();
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
        isAbilityAutoApproved() {
            return false;
        },
        isRestApiAutoApproved() {
            return false;
        },
        executeSingleTool() {
            throw new Error('Tool should not execute before confirmation');
        },
        _states: states
    }, overrides || {});

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
});
