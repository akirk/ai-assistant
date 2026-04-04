const { describe, it, beforeEach } = require('node:test');
const assert = require('node:assert/strict');
const toolsMixin = require('../assets/js/chat-tools.js');

// All known granular tool permission names (as sent from PHP settings)
const ALL_ENABLED = [
    'read_file', 'write_file', 'edit_file', 'delete_file',
    'list_directory', 'search_files', 'search_content',
    'run_php', 'db_query', 'rest_api', 'environment_info',
    'get_plugins', 'get_themes', 'install_plugin',
    'list_abilities', 'get_ability', 'execute_ability',
    'navigate', 'get_page_html', 'summarize_conversation',
    'list_skills', 'get_skill',
];

/**
 * Create a fresh test instance with mocked dependencies.
 * Each call returns an independent object so tests don't leak state,
 * but the shared activeExtendedTools closure is reset via resetActiveExtendedTools().
 */
function createInstance(overrides) {
    const instance = Object.create(toolsMixin);
    // Defaults: local provider, all tools enabled
    instance.conversationProvider = 'local';
    instance.getProvider = function() { return 'local'; };
    instance._enabledTools = ALL_ENABLED;
    Object.assign(instance, overrides || {});
    return instance;
}

beforeEach(function() {
    toolsMixin.resetActiveExtendedTools();
});

// ===== Tool definitions =====

describe('getAllToolDefinitions', function() {
    it('returns all tool definitions', function() {
        const defs = toolsMixin.getAllToolDefinitions();
        assert.ok(Array.isArray(defs));
        assert.ok(defs.length > 0);
    });

    it('each definition has name, description, input_schema', function() {
        for (const def of toolsMixin.getAllToolDefinitions()) {
            assert.ok(def.name, 'missing name');
            assert.ok(def.description, `${def.name}: missing description`);
            assert.ok(def.input_schema, `${def.name}: missing input_schema`);
        }
    });

    it('has no duplicate names', function() {
        const names = toolsMixin.getAllToolDefinitions().map(d => d.name);
        assert.deepStrictEqual(names, [...new Set(names)]);
    });

    it('includes all core tools', function() {
        const names = toolsMixin.getAllToolDefinitions().map(d => d.name);
        for (const core of toolsMixin.coreToolNames) {
            assert.ok(names.includes(core), `missing core tool: ${core}`);
        }
    });

    it('includes all extended tools', function() {
        const names = toolsMixin.getAllToolDefinitions().map(d => d.name);
        for (const ext of toolsMixin.extendedToolNames) {
            assert.ok(names.includes(ext), `missing extended tool: ${ext}`);
        }
    });
});

// ===== isToolEnabled =====

describe('isToolEnabled', function() {
    it('returns true for directly enabled tool', function() {
        assert.ok(toolsMixin.isToolEnabled('read_file', ['read_file']));
    });

    it('returns false for disabled tool', function() {
        assert.ok(!toolsMixin.isToolEnabled('read_file', []));
    });

    it('maps find to list_directory/search_files/search_content', function() {
        assert.ok(toolsMixin.isToolEnabled('find', ['list_directory']));
        assert.ok(toolsMixin.isToolEnabled('find', ['search_files']));
        assert.ok(toolsMixin.isToolEnabled('find', ['search_content']));
        assert.ok(!toolsMixin.isToolEnabled('find', ['read_file']));
    });

    it('maps ability to list_abilities/get_ability/execute_ability', function() {
        assert.ok(toolsMixin.isToolEnabled('ability', ['list_abilities']));
        assert.ok(toolsMixin.isToolEnabled('ability', ['get_ability']));
        assert.ok(toolsMixin.isToolEnabled('ability', ['execute_ability']));
        assert.ok(!toolsMixin.isToolEnabled('ability', ['read_file']));
    });

    it('maps skill to list_skills/get_skill', function() {
        assert.ok(toolsMixin.isToolEnabled('skill', ['list_skills']));
        assert.ok(toolsMixin.isToolEnabled('skill', ['get_skill']));
        assert.ok(!toolsMixin.isToolEnabled('skill', ['read_file']));
    });

    it('maps environment_info to environment_info/get_plugins/get_themes', function() {
        assert.ok(toolsMixin.isToolEnabled('environment_info', ['environment_info']));
        assert.ok(toolsMixin.isToolEnabled('environment_info', ['get_plugins']));
        assert.ok(toolsMixin.isToolEnabled('environment_info', ['get_themes']));
        assert.ok(!toolsMixin.isToolEnabled('environment_info', ['read_file']));
    });
});

// ===== getTools — tiering =====

describe('getTools tiering', function() {
    it('local provider returns only core tools plus enable_tools', function() {
        const inst = createInstance();
        const tools = inst.getTools();
        const names = tools.map(t => t.name);

        for (const core of toolsMixin.coreToolNames) {
            assert.ok(names.includes(core), `missing core tool: ${core}`);
        }
        assert.ok(names.includes('enable_tools'), 'missing enable_tools');

        // Should NOT include extended tools
        for (const ext of toolsMixin.extendedToolNames) {
            assert.ok(!names.includes(ext), `should not include extended tool: ${ext}`);
        }
    });

    it('cloud provider returns all tools without enable_tools', function() {
        const inst = createInstance({ conversationProvider: 'anthropic' });
        const tools = inst.getTools();
        const names = tools.map(t => t.name);

        assert.ok(!names.includes('enable_tools'), 'cloud should not have enable_tools');

        // Should include both core and extended
        for (const core of toolsMixin.coreToolNames) {
            assert.ok(names.includes(core), `missing core tool: ${core}`);
        }
        for (const ext of toolsMixin.extendedToolNames) {
            assert.ok(names.includes(ext), `missing extended tool: ${ext}`);
        }
    });

    it('openai provider returns all tools', function() {
        const inst = createInstance({ conversationProvider: 'openai' });
        const names = inst.getTools().map(t => t.name);

        assert.ok(!names.includes('enable_tools'));
        assert.ok(names.includes('db_query'));
        assert.ok(names.includes('navigate'));
    });

    it('respects enabled tools filter', function() {
        const inst = createInstance({
            conversationProvider: 'anthropic',
            _enabledTools: ['read_file', 'list_directory'],
        });
        const names = inst.getTools().map(t => t.name);

        assert.ok(names.includes('read_file'));
        assert.ok(names.includes('find'));  // list_directory maps to find
        assert.ok(!names.includes('run_php'));
        assert.ok(!names.includes('db_query'));
    });

    it('local provider with nothing enabled returns empty', function() {
        const inst = createInstance({ _enabledTools: [] });
        const tools = inst.getTools();
        assert.strictEqual(tools.length, 0);
    });
});

// ===== enable_tools activation =====

describe('enable_tools activation', function() {
    it('activates requested extended tools', function() {
        const inst = createInstance();

        // Before: only core tools
        let names = inst.getTools().map(t => t.name);
        assert.ok(!names.includes('db_query'));

        // Enable db_query
        inst.setActiveExtendedTools(['db_query']);

        names = inst.getTools().map(t => t.name);
        assert.ok(names.includes('db_query'), 'db_query should be available after enabling');
    });

    it('enable_tools disappears when all extended tools are activated', function() {
        const inst = createInstance();

        // Activate all extended tools
        inst.setActiveExtendedTools(toolsMixin.extendedToolNames.slice());

        const names = inst.getTools().map(t => t.name);
        assert.ok(!names.includes('enable_tools'), 'enable_tools should disappear when all extended active');
    });

    it('enable_tools remains when some extended tools are still inactive', function() {
        const inst = createInstance();
        inst.setActiveExtendedTools(['db_query']);

        const names = inst.getTools().map(t => t.name);
        assert.ok(names.includes('enable_tools'), 'enable_tools should remain');
    });

    it('resetActiveExtendedTools clears all activated tools', function() {
        const inst = createInstance();
        inst.setActiveExtendedTools(['db_query', 'navigate']);

        let names = inst.getTools().map(t => t.name);
        assert.ok(names.includes('db_query'));

        toolsMixin.resetActiveExtendedTools();

        names = inst.getTools().map(t => t.name);
        assert.ok(!names.includes('db_query'), 'db_query should be gone after reset');
    });
});

// ===== executeEnableTools =====

describe('executeEnableTools', function() {
    it('enables requested tools and returns their definitions', function() {
        const inst = createInstance();

        const result = inst.executeEnableTools({
            id: 'tool_1',
            arguments: { tools: ['db_query', 'navigate'] },
        });

        assert.strictEqual(result.success, true);
        assert.deepStrictEqual(result.result.enabled, ['db_query', 'navigate']);
        assert.strictEqual(result.result.tools.length, 2);
        assert.strictEqual(result.result.tools[0].name, 'db_query');
        assert.strictEqual(result.result.tools[1].name, 'navigate');

        // They should now appear in getTools
        const names = inst.getTools().map(t => t.name);
        assert.ok(names.includes('db_query'));
        assert.ok(names.includes('navigate'));
    });

    it('ignores invalid tool names', function() {
        const inst = createInstance();

        const result = inst.executeEnableTools({
            id: 'tool_2',
            arguments: { tools: ['fake_tool', 'db_query'] },
        });

        assert.deepStrictEqual(result.result.enabled, ['db_query']);
        assert.strictEqual(result.result.tools.length, 1);
    });

    it('ignores tools not permitted for user', function() {
        const inst = createInstance({
            // Only file tools enabled — db_query requires 'db_query' in enabled list
            _enabledTools: ['read_file', 'list_directory'],
        });

        const result = inst.executeEnableTools({
            id: 'tool_3',
            arguments: { tools: ['db_query'] },
        });

        assert.deepStrictEqual(result.result.enabled, []);
        assert.strictEqual(result.result.tools.length, 0);
    });

    it('does not duplicate already-enabled tools', function() {
        const inst = createInstance();

        // Enable db_query twice
        inst.executeEnableTools({
            id: 'tool_4',
            arguments: { tools: ['db_query'] },
        });

        const result = inst.executeEnableTools({
            id: 'tool_5',
            arguments: { tools: ['db_query'] },
        });

        // Second call: not newly added, but definition still returned
        assert.deepStrictEqual(result.result.enabled, []);
        assert.strictEqual(result.result.tools.length, 1);
        assert.strictEqual(result.result.tools[0].name, 'db_query');

        // Only one copy in active list
        const active = inst.getActiveExtendedTools();
        assert.strictEqual(active.filter(t => t === 'db_query').length, 1);
    });

    it('ignores core tool names', function() {
        const inst = createInstance();

        const result = inst.executeEnableTools({
            id: 'tool_6',
            arguments: { tools: ['read_file', 'run_php'] },
        });

        // Core tools are not extended, so nothing enabled
        assert.deepStrictEqual(result.result.enabled, []);
        assert.strictEqual(result.result.tools.length, 0);
    });

    it('handles empty tools array', function() {
        const inst = createInstance();

        const result = inst.executeEnableTools({
            id: 'tool_7',
            arguments: { tools: [] },
        });

        assert.strictEqual(result.success, true);
        assert.deepStrictEqual(result.result.enabled, []);
    });

    it('handles missing arguments', function() {
        const inst = createInstance();

        const result = inst.executeEnableTools({
            id: 'tool_8',
            arguments: {},
        });

        assert.strictEqual(result.success, true);
        assert.deepStrictEqual(result.result.enabled, []);
    });
});

// ===== Provider payload format =====

describe('Anthropic payload format', function() {
    it('each tool has name, description, input_schema (not parameters)', function() {
        const inst = createInstance({ conversationProvider: 'anthropic' });
        const tools = inst.getTools();

        assert.ok(tools.length > 0);
        for (const tool of tools) {
            assert.ok(typeof tool.name === 'string' && tool.name.length > 0,
                `tool missing name`);
            assert.ok(typeof tool.description === 'string' && tool.description.length > 0,
                `${tool.name}: missing description`);
            assert.ok(tool.input_schema && typeof tool.input_schema === 'object',
                `${tool.name}: missing input_schema`);
            assert.strictEqual(tool.input_schema.type, 'object',
                `${tool.name}: input_schema.type must be "object"`);
            assert.ok(tool.input_schema.properties && typeof tool.input_schema.properties === 'object',
                `${tool.name}: input_schema.properties must be an object`);
            // Must NOT have OpenAI-style "parameters" at top level
            assert.strictEqual(tool.parameters, undefined,
                `${tool.name}: should not have "parameters" key (Anthropic uses input_schema)`);
        }
    });

    it('does not include enable_tools for cloud provider', function() {
        const inst = createInstance({ conversationProvider: 'anthropic' });
        const names = inst.getTools().map(t => t.name);
        assert.ok(!names.includes('enable_tools'));
    });

    it('serializes to valid JSON', function() {
        const inst = createInstance({ conversationProvider: 'anthropic' });
        const tools = inst.getTools();
        const json = JSON.stringify(tools);
        const parsed = JSON.parse(json);
        assert.strictEqual(parsed.length, tools.length);
    });
});

describe('OpenAI payload format', function() {
    it('each tool has type:"function" with function.name/description/parameters', function() {
        const inst = createInstance({ conversationProvider: 'openai' });
        const tools = inst.getToolsOpenAI();

        assert.ok(tools.length > 0);
        for (const tool of tools) {
            assert.strictEqual(tool.type, 'function',
                `tool.type must be "function"`);
            assert.ok(tool.function && typeof tool.function === 'object',
                `missing tool.function object`);
            assert.ok(typeof tool.function.name === 'string' && tool.function.name.length > 0,
                `missing function.name`);
            assert.ok(typeof tool.function.description === 'string' && tool.function.description.length > 0,
                `${tool.function.name}: missing function.description`);
            assert.ok(tool.function.parameters && typeof tool.function.parameters === 'object',
                `${tool.function.name}: missing function.parameters`);
            assert.strictEqual(tool.function.parameters.type, 'object',
                `${tool.function.name}: parameters.type must be "object"`);
            // Must NOT have Anthropic-style "input_schema" at function level
            assert.strictEqual(tool.function.input_schema, undefined,
                `${tool.function.name}: should not have "input_schema" (OpenAI uses parameters)`);
        }
    });

    it('serializes to valid JSON', function() {
        const inst = createInstance({ conversationProvider: 'openai' });
        const tools = inst.getToolsOpenAI();
        const json = JSON.stringify(tools);
        const parsed = JSON.parse(json);
        assert.strictEqual(parsed.length, tools.length);
    });
});

describe('Local LLM payload format', function() {
    it('uses OpenAI-compatible format', function() {
        const inst = createInstance(); // local by default
        const tools = inst.getToolsOpenAI();

        for (const tool of tools) {
            assert.strictEqual(tool.type, 'function');
            assert.ok(tool.function.name);
            assert.ok(tool.function.parameters);
        }
    });

    it('only includes core tools plus enable_tools', function() {
        const inst = createInstance();
        const tools = inst.getToolsOpenAI();
        const names = tools.map(t => t.function.name);

        for (const core of toolsMixin.coreToolNames) {
            assert.ok(names.includes(core), `missing core tool: ${core}`);
        }
        assert.ok(names.includes('enable_tools'));

        for (const ext of toolsMixin.extendedToolNames) {
            assert.ok(!names.includes(ext), `should not include extended: ${ext}`);
        }
    });

    it('includes extended tools after enable_tools activation', function() {
        const inst = createInstance();

        inst.executeEnableTools({
            id: 'test',
            arguments: { tools: ['db_query', 'navigate'] },
        });

        const tools = inst.getToolsOpenAI();
        const names = tools.map(t => t.function.name);

        assert.ok(names.includes('db_query'));
        assert.ok(names.includes('navigate'));
        // enable_tools should still be present (not all extended activated)
        assert.ok(names.includes('enable_tools'));
    });

    it('payload token count is smaller than cloud provider', function() {
        const localInst = createInstance();
        const cloudInst = createInstance({ conversationProvider: 'anthropic' });

        const localJson = JSON.stringify(localInst.getTools());
        const cloudJson = JSON.stringify(cloudInst.getTools());

        assert.ok(localJson.length < cloudJson.length,
            `local (${localJson.length} chars) should be smaller than cloud (${cloudJson.length} chars)`);
    });
});
