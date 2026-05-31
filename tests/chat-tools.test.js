const { describe, it } = require('node:test');
const assert = require('node:assert/strict');
const toolsMixin = require('../assets/js/chat-tools.js');

// All known granular tool permission names (as sent from PHP settings)
const ALL_ENABLED = [
    'read_file', 'write_file', 'edit_file', 'delete_file',
    'list_directory', 'search_files', 'search_content',
    'run_php', 'db_query', 'rest_api', 'environment_info',
    'get_plugins', 'get_themes', 'install_plugin',
    'list_abilities', 'get_ability', 'execute_ability',
    'get_page_html', 'pick_image', 'summarize_conversation',
    'list_skills', 'get_skill',
];

function createInstance(overrides) {
    const instance = Object.create(toolsMixin);
    instance.conversationProvider = 'local';
    instance.getProvider = function() { return 'local'; };
    instance._enabledTools = ALL_ENABLED;
    Object.assign(instance, overrides || {});
    return instance;
}

const DEV_TOOL_DEFINITIONS = [
    {
        name: 'run_php',
        description: 'Execute PHP in WordPress. Prefer rest_api for post/page drafts. No <?php tag. Return a value.',
        input_schema: {
            type: 'object',
            properties: {
                code: { type: 'string' },
            },
            required: ['code'],
        },
    },
];

function withToolDefinitions(definitions, callback) {
    const previous = global.aiAssistantConfig;
    global.aiAssistantConfig = Object.assign({}, previous || {}, { toolDefinitions: definitions });
    try {
        return callback();
    } finally {
        if (previous === undefined) {
            delete global.aiAssistantConfig;
        } else {
            global.aiAssistantConfig = previous;
        }
    }
}

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

    it('defines pick_image with compact prompts', function() {
        const def = toolsMixin.getAllToolDefinitions().find(d => d.name === 'pick_image');

        assert.ok(def);
        assert.ok(def.description.includes('choose or upload an image'));
        assert.ok(def.description.includes('selected image details'));
        assert.ok(def.description.includes('Do not call this tool multiple times'));
        assert.deepStrictEqual(def.input_schema.required, ['query']);
        assert.strictEqual(def.input_schema.properties.query.description, 'Initial search.');
        assert.strictEqual(def.input_schema.properties.purpose.description, 'Image use.');
        assert.ok(!Object.hasOwn(def.input_schema.properties, 'allow_external_fallback'));
    });

    it('defines read_file chunk controls for large files', function() {
        const def = toolsMixin.getAllToolDefinitions().find(d => d.name === 'read_file');

        assert.ok(def);
        assert.deepStrictEqual(def.input_schema.required, ['path']);
        assert.ok(Object.hasOwn(def.input_schema.properties, 'offset'));
        assert.ok(Object.hasOwn(def.input_schema.properties, 'max_length'));
    });

    it('guides native post drafts toward rest_api', function() {
        withToolDefinitions(DEV_TOOL_DEFINITIONS, function() {
            const defs = toolsMixin.getAllToolDefinitions();
            const restApi = defs.find(d => d.name === 'rest_api');
            const runPhp = defs.find(d => d.name === 'run_php');

            assert.ok(restApi.description.includes('Preferred for post/page drafts'));
            assert.ok(restApi.description.includes('POST /wp/v2/posts'));
            assert.ok(restApi.description.includes('status "draft"'));
            assert.ok(runPhp.description.includes('Prefer rest_api'));
        });
    });

    it('merges configured extension tool definitions', function() {
        withToolDefinitions(DEV_TOOL_DEFINITIONS, function() {
            const names = toolsMixin.getAllToolDefinitions().map(d => d.name);

            assert.ok(names.includes('read_file'));
            assert.ok(names.includes('run_php'));
        });
    });

    it('explains ability domains are not executable IDs', function() {
        global.aiAssistantConfig = {
            abilityDomains: {
                'create-wp-app': 'wp app, app plugin',
            },
        };

        const description = toolsMixin.getAbilityToolDescription();

        delete global.aiAssistantConfig;
        assert.ok(description.includes('Ability domain slugs are categories, not executable ability IDs'));
        assert.ok(description.includes('list by category'));
        assert.ok(description.includes('get the exact ability ID before execute'));
        assert.ok(description.includes('create-wp-app (wp app, app plugin)'));
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

// ===== getTools =====

describe('getTools', function() {
    it('returns all enabled tools', function() {
        const inst = createInstance();
        const names = inst.getTools().map(t => t.name);

        assert.ok(names.includes('read_file'));
        assert.ok(names.includes('db_query'));
        assert.ok(names.includes('get_page_html'));
        assert.ok(!names.includes('enable_tools'));
    });

    it('respects enabled tools filter', function() {
        const inst = createInstance({ _enabledTools: ['read_file', 'list_directory'] });
        const names = inst.getTools().map(t => t.name);

        assert.ok(names.includes('read_file'));
        assert.ok(names.includes('find'));
        assert.ok(!names.includes('run_php'));
        assert.ok(!names.includes('db_query'));
    });

    it('returns empty when nothing enabled', function() {
        const inst = createInstance({ _enabledTools: [] });
        assert.strictEqual(inst.getTools().length, 0);
    });

    it('same tools regardless of provider', function() {
        const local = createInstance({ conversationProvider: 'local' });
        const cloud = createInstance({ conversationProvider: 'anthropic' });
        assert.deepStrictEqual(
            local.getTools().map(t => t.name),
            cloud.getTools().map(t => t.name)
        );
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
            assert.strictEqual(tool.parameters, undefined,
                `${tool.name}: should not have "parameters" key (Anthropic uses input_schema)`);
        }
    });

    it('serializes to valid JSON', function() {
        const inst = createInstance({ conversationProvider: 'anthropic' });
        const tools = inst.getTools();
        const parsed = JSON.parse(JSON.stringify(tools));
        assert.strictEqual(parsed.length, tools.length);
    });
});

describe('OpenAI payload format', function() {
    it('each tool has type:"function" with function.name/description/parameters', function() {
        const inst = createInstance({ conversationProvider: 'openai' });
        const tools = inst.getToolsOpenAI();

        assert.ok(tools.length > 0);
        for (const tool of tools) {
            assert.strictEqual(tool.type, 'function');
            assert.ok(tool.function && typeof tool.function === 'object');
            assert.ok(typeof tool.function.name === 'string' && tool.function.name.length > 0);
            assert.ok(typeof tool.function.description === 'string' && tool.function.description.length > 0,
                `${tool.function.name}: missing function.description`);
            assert.ok(tool.function.parameters && typeof tool.function.parameters === 'object',
                `${tool.function.name}: missing function.parameters`);
            assert.strictEqual(tool.function.parameters.type, 'object',
                `${tool.function.name}: parameters.type must be "object"`);
            assert.strictEqual(tool.function.input_schema, undefined,
                `${tool.function.name}: should not have "input_schema" (OpenAI uses parameters)`);
        }
    });

    it('serializes to valid JSON', function() {
        const inst = createInstance({ conversationProvider: 'openai' });
        const tools = inst.getToolsOpenAI();
        const parsed = JSON.parse(JSON.stringify(tools));
        assert.strictEqual(parsed.length, tools.length);
    });
});
