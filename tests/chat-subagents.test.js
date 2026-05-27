const { describe, it } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function jQueryExtend() {
    const args = Array.from(arguments);
    let deep = false;
    if (args[0] === true) {
        deep = true;
        args.shift();
    }
    const target = args.shift() || {};
    args.forEach(source => {
        Object.keys(source || {}).forEach(key => {
            const value = source[key];
            if (deep && value && typeof value === 'object' && !Array.isArray(value)) {
                target[key] = jQueryExtend(true, target[key] || {}, value);
            } else if (deep && Array.isArray(value)) {
                target[key] = value.slice();
            } else {
                target[key] = value;
            }
        });
    });
    return target;
}

function loadSubagentsMixin(configOverrides) {
    const aiAssistant = {};
    const config = Object.assign({
        ajaxUrl: '/admin-ajax.php',
        nonce: 'nonce',
        fileEndpointTools: ['read_file', 'find', 'list_directory', 'search_files', 'search_content']
    }, configOverrides || {});
    const context = {
        window: {
            aiAssistant,
            location: {
                pathname: '/wp-admin/edit.php',
                search: '?post_type=page'
            }
        },
        aiAssistantProviders: { available: {} },
        aiAssistantConfig: config,
        jQuery: {
            extend: jQueryExtend,
            ajax() {}
        },
        console,
        fetch() {
            throw new Error('fetch not expected');
        }
    };

    vm.createContext(context);
    [
        '../assets/js/chat-tools.js',
        '../assets/js/chat-subagents.js'
    ].forEach(file => {
        vm.runInContext(fs.readFileSync(path.join(__dirname, file), 'utf8'), context);
    });
    return aiAssistant;
}

describe('delegate subagents', function() {
    it('exposes only read-only codebase tools', function() {
        const assistant = loadSubagentsMixin();
        const names = assistant.getDelegateToolNames('codebase_investigation');

        assert.ok(names.includes('find'));
        assert.ok(names.includes('read_file'));
        assert.ok(names.includes('environment_info'));
        assert.ok(names.includes('ability'));
        assert.ok(!names.includes('delegate'));
        assert.ok(!names.includes('rest_api'));
        assert.ok(!names.includes('write_file'));
    });

    it('rejects mutating tools and ability execution in delegate mode', function() {
        const assistant = loadSubagentsMixin();

        assert.equal(assistant.isDelegateToolAllowed('codebase_investigation', 'read_file', { path: 'x.php' }), true);
        assert.equal(assistant.isDelegateToolAllowed('codebase_investigation', 'write_file', { path: 'x.php' }), false);
        assert.equal(assistant.isDelegateToolAllowed('codebase_investigation', 'rest_api', { method: 'POST' }), false);
        assert.equal(assistant.isDelegateToolAllowed('codebase_investigation', 'ability', { action: 'get' }), true);
        assert.equal(assistant.isDelegateToolAllowed('codebase_investigation', 'ability', { action: 'execute' }), false);
    });

    it('normalizes Anthropic delegate tool-result turns', function() {
        const assistant = loadSubagentsMixin();
        const messages = assistant.convertDelegateMessagesForAnthropic([
            { role: 'user', content: 'Inspect files.' },
            {
                role: 'assistant',
                content: null,
                tool_calls: [{
                    id: 'tool-1',
                    type: 'function',
                    function: { name: 'find', arguments: '{"text":"needle"}' }
                }]
            },
            { role: 'tool', tool_call_id: 'tool-1', content: '{"matches":[]}' }
        ]);

        assert.equal(messages.length, 3);
        assert.equal(messages[1].content[0].type, 'tool_use');
        assert.equal(messages[2].content[0].type, 'tool_result');
        assert.equal(messages[2].content[0].tool_use_id, 'tool-1');
    });

    it('preserves full delegate summaries without mutation', function() {
        const assistant = loadSubagentsMixin();
        const summary = '# Report\n\n' + 'x'.repeat(7000);
        const result = assistant.buildDelegateResult(
            {
                task_type: 'codebase_investigation',
                max_results: 5
            },
            summary,
            [],
            false,
            {}
        );

        assert.equal(result.summary, summary);
    });

    it('feeds full hidden tool results back to the delegate subagent', async function() {
        const assistant = loadSubagentsMixin();
        const calls = [];
        const largeContent = 'x'.repeat(25000);

        assistant.conversationProvider = 'openai';
        assistant.getSubagentModel = function() {
            return 'test-model';
        };
        assistant.callDelegateProvider = async function(provider, providerConfig, model, systemPrompt, messages, tools) {
            calls.push({ messages, tools });

            if (calls.length === 1) {
                return {
                    content: '',
                    toolCalls: [{
                        id: 'tool-1',
                        name: 'read_file',
                        arguments: { path: 'plugins/example/big.php' }
                    }]
                };
            }

            return {
                content: 'Summary: inspected the full file.',
                toolCalls: []
            };
        };
        assistant.executeDelegateSubagentTool = async function(taskType, toolCall) {
            return {
                id: toolCall.id,
                name: toolCall.name,
                input: toolCall.arguments,
                result: {
                    path: toolCall.arguments.path,
                    content: largeContent
                },
                success: true
            };
        };

        const result = await assistant.runDelegateSubagent({
            task_type: 'codebase_investigation',
            request: 'Inspect the file.',
            target: '',
            max_results: 5
        });

        const toolMessage = calls[1].messages.find(function(message) {
            return message.role === 'tool';
        });

        assert.equal(result.summary, 'Summary: inspected the full file.');
        assert.ok(toolMessage.content.includes(largeContent));
        assert.doesNotMatch(toolMessage.content, /delegate_truncation|delegate omitted/);
    });

    it('attaches delegate provider token usage to the parent assistant tool call', async function() {
        const assistant = loadSubagentsMixin();
        assistant.messages = [{
            role: 'assistant',
            content: null,
            tool_calls: [{
                id: 'delegate-parent',
                type: 'function',
                function: {
                    name: 'delegate',
                    arguments: '{}'
                }
            }]
        }];
        assistant.conversationProvider = 'openai';
        assistant.getSubagentModel = function() {
            return 'test-model';
        };
        assistant.callDelegateProvider = async function() {
            return {
                content: 'Summary: done.',
                toolCalls: [],
                usage: {
                    prompt_tokens: 17,
                    completion_tokens: 5,
                    total_tokens: 22
                }
            };
        };

        const result = await assistant.executeDelegate({
            id: 'delegate-parent',
            name: 'delegate',
            arguments: {
                task_type: 'codebase_investigation',
                request: 'Inspect code.'
            }
        });

        assert.equal(result.success, true);
        assert.equal(result.subagent_usage.total_tokens, 22);
        assert.equal(assistant.messages[0]._subagent_usage.input_tokens, 17);
        assert.equal(assistant.messages[0]._subagent_usage.output_tokens, 5);
        assert.equal(assistant.messages[0]._subagent_usage.total_tokens, 22);
        assert.equal(assistant.messages[0]._subagent_usage.source, 'provider');
        assert.equal(result.result._subagent_usage, undefined);
    });

    it('relays ability domains to codebase delegate prompts without copying the main prompt', function() {
        const assistant = loadSubagentsMixin({
            siteUrl: 'https://example.test',
            currentPath: '/wp-admin/admin.php?page=orders',
            abilityDomains: {
                commerce: 'orders, payments, subscriptions'
            }
        });

        const prompt = assistant.getDelegateSystemPrompt('codebase_investigation');

        assert.match(prompt, /Shared context:/);
        assert.match(prompt, /Site URL: https:\/\/example\.test/);
        assert.match(prompt, /Current page: \/wp-admin\/admin\.php\?page=orders/);
        assert.match(prompt, /Ability domains: commerce: orders, payments, subscriptions/);
        assert.match(prompt, /ability action=list\/get before file search/);
        assert.match(prompt, /Treat target scope as a boundary/);
        assert.doesNotMatch(prompt, /POST\/PAGE DRAFTS/);
        assert.doesNotMatch(prompt, /NAVIGATION SUGGESTIONS/);
    });

    it('relays current page and selector hints to page inspection prompts', function() {
        const assistant = loadSubagentsMixin({
            siteUrl: 'https://example.test',
            currentPath: '/wp-admin/plugins.php',
            pageSelectorHints: '- .wp-list-table.plugins: Table of installed plugins\n- tr.active: Currently active plugins'
        });

        const prompt = assistant.getDelegateSystemPrompt('page_inspection');

        assert.match(prompt, /Shared context:/);
        assert.match(prompt, /Current page: \/wp-admin\/plugins\.php/);
        assert.match(prompt, /Useful selectors:/);
        assert.match(prompt, /\.wp-list-table\.plugins/);
        assert.doesNotMatch(prompt, /Ability domains:/);
    });

    it('describes target scope and tool budget in delegate user prompts', function() {
        const assistant = loadSubagentsMixin();
        const prompt = assistant.getDelegateUserPrompt({
            request: 'Inspect code.',
            target: 'plugins/cookbook/src/App.php',
            max_results: 5
        });

        assert.match(prompt, /Target scope: plugins\/cookbook\/src\/App\.php/);
        assert.match(prompt, /Stay inside target scope/);
        assert.match(prompt, /Tool budget: up to 6 tool-call rounds/);
    });

    it('constrains delegate file tools to the target scope', async function() {
        const assistant = loadSubagentsMixin();
        let executed = null;
        assistant.executeSingleTool = async function(toolCall) {
            executed = toolCall;
            return {
                id: toolCall.id,
                name: toolCall.name,
                input: toolCall.arguments,
                result: { items: [], count: 0 },
                success: true
            };
        };

        const scopedFind = await assistant.executeDelegateSubagentTool(
            'codebase_investigation',
            { id: 'tool-1', name: 'find', arguments: {} },
            { target: 'plugins/cookbook/templates' }
        );

        assert.equal(scopedFind.success, true);
        assert.equal(executed.arguments.path, 'plugins/cookbook/templates');

        const scopedGlob = await assistant.executeDelegateSubagentTool(
            'codebase_investigation',
            { id: 'tool-glob', name: 'find', arguments: { path: 'plugins/cookbook/templates', glob: '**/*' } },
            { target: 'plugins/cookbook/templates' }
        );

        assert.equal(scopedGlob.success, true);
        assert.equal(executed.arguments.path, 'plugins/cookbook/templates');
        assert.equal(executed.arguments.glob, '**/*');

        const combinedScopedGlob = await assistant.executeDelegateSubagentTool(
            'codebase_investigation',
            { id: 'tool-combined-glob', name: 'find', arguments: { path: 'plugins/cookbook', glob: 'templates/**/*' } },
            { target: 'plugins/cookbook/templates' }
        );

        assert.equal(combinedScopedGlob.success, true);
        assert.equal(executed.arguments.path, 'plugins/cookbook');
        assert.equal(executed.arguments.glob, 'templates/**/*');

        const scopedFileSearch = await assistant.executeDelegateSubagentTool(
            'codebase_investigation',
            { id: 'tool-file', name: 'find', arguments: { text: 'register_post_type' } },
            { target: 'plugins/cookbook/src/App.php' }
        );

        assert.equal(scopedFileSearch.success, true);
        assert.equal(executed.arguments.path, 'plugins/cookbook/src/App.php');

        const rejectedRead = await assistant.executeDelegateSubagentTool(
            'codebase_investigation',
            { id: 'tool-2', name: 'read_file', arguments: { path: 'advanced-cache.php' } },
            { target: 'plugins/cookbook/templates' }
        );

        assert.equal(rejectedRead.success, false);
        assert.match(rejectedRead.result.error, /outside delegate target scope/);
    });

    it('uses a final no-tool synthesis pass after delegate tool rounds are exhausted', async function() {
        const assistant = loadSubagentsMixin();
        const calls = [];
        const maxRounds = assistant.getDelegateMaxRounds();

        assistant.conversationProvider = 'openai';
        assistant.getSubagentModel = function() {
            return 'test-model';
        };
        assistant.callDelegateProvider = async function(provider, providerConfig, model, systemPrompt, messages, tools) {
            calls.push({ messages, tools });

            if (!tools || tools.length === 0) {
                assert.match(messages[messages.length - 1].content, /Tool budget reached/);
                return {
                    content: 'Summary: inspected gathered results.\n- Found the relevant file.',
                    toolCalls: []
                };
            }

            return {
                content: '',
                toolCalls: [{
                    id: 'tool-' + calls.length,
                    name: 'find',
                    arguments: { text: 'needle' }
                }]
            };
        };
        assistant.executeDelegateSubagentTool = async function(taskType, toolCall) {
            return {
                id: toolCall.id,
                name: toolCall.name,
                input: toolCall.arguments,
                result: {
                    matches: [{ path: 'plugins/example/file-' + toolCall.id + '.php' }],
                    count: 1
                },
                success: true
            };
        };

        const result = await assistant.runDelegateSubagent({
            task_type: 'codebase_investigation',
            request: 'Find the relevant code.',
            target: '',
            max_results: 5
        });

        assert.equal(calls.length, maxRounds + 1);
        assert.equal(calls[maxRounds].tools.length, 0);
        assert.match(result.summary, /inspected gathered results/);
        assert.deepStrictEqual(Array.from(result.available_tools), ['find', 'read_file', 'environment_info', 'ability', 'skill']);
        assert.equal(result.max_rounds, maxRounds);
        assert.equal(result.tool_calls.length, maxRounds);
        assert.equal(result.tool_calls[0].name, 'find');
        assert.deepStrictEqual(result.tool_calls[0].input, { text: 'needle' });
        assert.equal(result.tool_calls[0].success, true);
        assert.equal(result.omitted.round_limit_reached, true);
        assert.equal(result.omitted.final_synthesis, true);
        assert.equal(result.omitted.tool_result_count, maxRounds);
    });
});
