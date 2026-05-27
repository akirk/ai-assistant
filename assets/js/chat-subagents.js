(function($) {
    'use strict';

    function safeJsonParse(value, fallback) {
        if (value && typeof value === 'object') {
            return value;
        }
        try {
            return JSON.parse(value || '{}');
        } catch (e) {
            return fallback || {};
        }
    }

    $.extend(window.aiAssistant, {
        normalizeDelegateArguments: function(args) {
            args = args || {};
            var taskType = String(args.task_type || '').trim();
            var request = String(args.request || '').trim();
            var target = String(args.target || '').trim();
            var maxResults = parseInt(args.max_results || 5, 10);

            if (['codebase_investigation', 'conversation_recall', 'page_inspection'].indexOf(taskType) < 0) {
                throw new Error('delegate requires task_type to be codebase_investigation, conversation_recall, or page_inspection');
            }

            if (!request) {
                throw new Error('delegate requires a request');
            }

            return {
                task_type: taskType,
                request: request,
                target: target,
                max_results: Math.max(1, Math.min(10, Number.isFinite(maxResults) ? maxResults : 5))
            };
        },

        executeDelegate: function(toolCall) {
            var self = this;
            var args;
            try {
                args = this.normalizeDelegateArguments(toolCall.arguments || {});
            } catch (error) {
                return Promise.resolve({
                    id: toolCall.id,
                    name: 'delegate',
                    input: toolCall.arguments || {},
                    result: { error: error.message },
                    success: false
                });
            }

            return this.runDelegateSubagent(args, toolCall.id).then(function(result) {
                var subagentUsage = result._subagent_usage || null;
                if (subagentUsage) {
                    delete result._subagent_usage;
                    if (typeof self.attachSubagentUsageToToolCall === 'function') {
                        self.attachSubagentUsageToToolCall(toolCall.id, subagentUsage);
                    }
                }
                return {
                    id: toolCall.id,
                    name: 'delegate',
                    input: args,
                    result: result,
                    success: true,
                    subagent_usage: subagentUsage
                };
            }).catch(function(error) {
                console.warn('[AI Assistant] Delegate failed:', error);
                return {
                    id: toolCall.id,
                    name: 'delegate',
                    input: args,
                    result: { error: error.message || 'Delegate failed' },
                    success: false
                };
            });
        },

        getDelegateTokenNumber: function(value) {
            if (typeof this.getTokenNumber === 'function') {
                return this.getTokenNumber(value);
            }
            var number = parseInt(value, 10);
            return Number.isFinite(number) && number > 0 ? number : 0;
        },

        estimateDelegateTokensFromChars: function(chars) {
            if (typeof this.estimateTokensFromChars === 'function') {
                return this.estimateTokensFromChars(chars);
            }
            return Math.ceil(Math.max(0, chars) / 4);
        },

        normalizeDelegateTokenUsage: function(usage, provider, model, source) {
            if (typeof this.normalizeTokenUsage === 'function') {
                return this.normalizeTokenUsage(usage, provider, model, source);
            }

            usage = usage || {};
            var inputTokens = this.getDelegateTokenNumber(usage.input_tokens || usage.prompt_tokens || usage.prompt_eval_count);
            var outputTokens = this.getDelegateTokenNumber(usage.output_tokens || usage.completion_tokens || usage.eval_count);
            var totalTokens = this.getDelegateTokenNumber(usage.total_tokens) || inputTokens + outputTokens;

            return {
                version: 1,
                source: source || 'provider',
                provider: provider || '',
                model: model || '',
                input_tokens: inputTokens,
                output_tokens: outputTokens,
                total_tokens: totalTokens,
                cached_input_tokens: this.getDelegateTokenNumber(usage.cached_input_tokens),
                reasoning_output_tokens: this.getDelegateTokenNumber(usage.reasoning_output_tokens)
            };
        },

        addNormalizedDelegateTokenUsage: function(current, next) {
            if (!next) {
                return current || null;
            }

            current = current || {
                version: 1,
                source: 'none',
                provider: next.provider || '',
                model: next.model || '',
                input_tokens: 0,
                output_tokens: 0,
                total_tokens: 0,
                cached_input_tokens: 0,
                reasoning_output_tokens: 0
            };

            current.input_tokens += this.getDelegateTokenNumber(next.input_tokens);
            current.output_tokens += this.getDelegateTokenNumber(next.output_tokens);
            current.total_tokens += this.getDelegateTokenNumber(next.total_tokens) || this.getDelegateTokenNumber(next.input_tokens) + this.getDelegateTokenNumber(next.output_tokens);
            current.cached_input_tokens += this.getDelegateTokenNumber(next.cached_input_tokens);
            current.reasoning_output_tokens += this.getDelegateTokenNumber(next.reasoning_output_tokens);
            current.provider = current.provider || next.provider || '';
            current.model = current.model || next.model || '';
            if (current.source === 'none') {
                current.source = next.source || 'none';
            } else if (next.source && next.source !== 'none' && current.source !== next.source) {
                current.source = 'mixed';
            }

            return current;
        },

        estimateDelegateTokenUsage: function(response, provider, model, systemPrompt, messages, tools) {
            var inputChars = String(systemPrompt || '').length;
            try {
                inputChars += JSON.stringify(messages || []).length;
                inputChars += JSON.stringify(tools || []).length;
            } catch (e) {}

            var outputChars = String((response && response.content) || '').length;
            try {
                outputChars += JSON.stringify((response && response.toolCalls) || []).length;
            } catch (e2) {}

            return this.normalizeDelegateTokenUsage({
                input_tokens: this.estimateDelegateTokensFromChars(inputChars),
                output_tokens: this.estimateDelegateTokensFromChars(outputChars)
            }, provider, model, 'estimate');
        },

        addDelegateTokenUsage: function(current, response, provider, model, systemPrompt, messages, tools) {
            var usage = response && response.usage
                ? this.normalizeDelegateTokenUsage(response.usage, provider, model, 'provider')
                : this.estimateDelegateTokenUsage(response || {}, provider, model, systemPrompt, messages, tools);

            return this.addNormalizedDelegateTokenUsage(current, usage);
        },

        attachSubagentUsageToToolCall: function(toolCallId, usage) {
            if (!toolCallId || !usage || !(usage.input_tokens || usage.output_tokens || usage.total_tokens)) {
                return false;
            }

            var messages = this.messages || [];
            for (var i = messages.length - 1; i >= 0; i--) {
                var message = messages[i];
                if (!message || message.role !== 'assistant') {
                    continue;
                }

                var found = false;
                if (Array.isArray(message.tool_calls)) {
                    found = message.tool_calls.some(function(toolCall) {
                        return toolCall && toolCall.id === toolCallId;
                    });
                }
                if (!found && Array.isArray(message.content)) {
                    found = message.content.some(function(block) {
                        return block && block.type === 'tool_use' && block.id === toolCallId;
                    });
                }

                if (found) {
                    message._subagent_usage = this.addNormalizedDelegateTokenUsage(message._subagent_usage || null, usage);
                    if (typeof this.updateTokenCount === 'function') {
                        this.updateTokenCount();
                    }
                    return true;
                }
            }

            return false;
        },

        getDelegateToolDefinitions: function(taskType) {
            var allTools = this.getAllToolDefinitions ? this.getAllToolDefinitions() : [];

            function byName(name) {
                for (var i = 0; i < allTools.length; i++) {
                    if (allTools[i].name === name) {
                        return $.extend(true, {}, allTools[i]);
                    }
                }
                return null;
            }

            function compactAbilityTool() {
                var ability = byName('ability');
                if (!ability) {
                    return null;
                }
                ability.description = 'Read-only ability discovery. Use action list or get only. Do not execute abilities.';
                ability.input_schema.properties.action.enum = ['list', 'get'];
                return ability;
            }

            if (taskType === 'conversation_recall') {
                return [
                    {
                        name: 'recall_conversations',
                        description: 'Search saved AI Assistant conversations. Prefer summaries when available and use excerpts only as supporting context.',
                        input_schema: {
                            type: 'object',
                            properties: {
                                query: { type: 'string', description: 'Search text or topic hint.' },
                                limit: { type: 'number', description: 'Maximum conversations to return.' }
                            }
                        }
                    }
                ];
            }

            if (taskType === 'page_inspection') {
                var pageTool = byName('get_page_html');
                return pageTool ? [pageTool] : [];
            }

            return [
                byName('find'),
                byName('read_file'),
                byName('environment_info'),
                compactAbilityTool(),
                byName('skill')
            ].filter(Boolean);
        },

        getDelegateToolNames: function(taskType) {
            return this.getDelegateToolDefinitions(taskType).map(function(tool) {
                return tool.name;
            });
        },

        truncateDelegatePromptContext: function(value, maxLength) {
            value = String(value || '').trim();
            maxLength = maxLength || 1200;
            if (value.length <= maxLength) {
                return value;
            }
            return value.substring(0, maxLength - 3) + '...';
        },

        getDelegateCurrentPath: function(config) {
            var currentPath = String(config.currentPath || '').trim();
            if (!currentPath && typeof window !== 'undefined' && window.location) {
                currentPath = (window.location.pathname || '/') + (window.location.search || '');
            }
            return currentPath;
        },

        formatDelegateAbilityDomains: function(domains) {
            var keys = Object.keys(domains || {}).filter(function(key) {
                return key && domains[key];
            });
            if (keys.length === 0) {
                return '';
            }

            var visible = keys.slice(0, 8).map(function(key) {
                return key + ': ' + String(domains[key]).trim();
            });
            if (keys.length > visible.length) {
                visible.push('+' + (keys.length - visible.length) + ' more');
            }
            return visible.join('; ');
        },

        getDelegateSharedPromptContext: function(taskType) {
            var config = this.getRuntimeConfig ? this.getRuntimeConfig() : {};
            var lines = [];

            if (taskType === 'codebase_investigation' || taskType === 'page_inspection') {
                if (config.siteUrl || config.homeUrl) {
                    lines.push('- Site URL: ' + (config.siteUrl || config.homeUrl));
                }

                var currentPath = this.getDelegateCurrentPath(config);
                if (currentPath) {
                    lines.push('- Current page: ' + currentPath);
                }
            }

            if (taskType === 'codebase_investigation') {
                var abilityDomains = this.formatDelegateAbilityDomains(config.abilityDomains || {});
                if (abilityDomains) {
                    lines.push('- Ability domains: ' + abilityDomains);
                    lines.push('- If the request matches an ability domain, use ability action=list/get before file search.');
                }
            }

            if (taskType === 'page_inspection' && config.pageSelectorHints) {
                lines.push('Useful selectors:\n' + this.truncateDelegatePromptContext(config.pageSelectorHints, 1200));
            }

            if (lines.length === 0) {
                return '';
            }

            return '\n\nShared context:\n' + lines.join('\n');
        },

        getDelegateSystemPrompt: function(taskType) {
            var base = 'You are a hidden read-only helper subagent inside AI Assistant for WordPress. ' +
                'Do focused context gathering and return only concise findings for the main assistant. ' +
                'Never modify files, database records, REST resources, settings, or site state. ' +
                'Do not ask the user questions. If information is unavailable, say what is missing.';
            var sharedContext = this.getDelegateSharedPromptContext(taskType);
            var specificPrompt = '\n\nTask: inspect the current page DOM. Use conservative selectors and summarize visible structure, controls, labels, and likely user-visible state.';

            if (taskType === 'codebase_investigation') {
                specificPrompt = '\n\nTask: inspect codebase context. Treat target scope as a boundary. Prefer path-only search before file reads. Read only files likely to answer the request. Return relevant paths, short excerpts, and conclusions.';
            } else if (taskType === 'conversation_recall') {
                specificPrompt = '\n\nTask: recall prior conversation context. Prefer saved summaries. Use excerpts only to disambiguate. Return the relevant prior decisions, files, and unresolved tasks.';
            }

            return base + sharedContext + specificPrompt;
        },

        getDelegateUserPrompt: function(args) {
            var prompt = 'Request: ' + args.request;
            if (args.target) {
                prompt += '\nTarget scope: ' + args.target;
                prompt += '\nStay inside target scope unless the request explicitly asks broader.';
            }
            prompt += '\nTool budget: up to ' + this.getDelegateMaxRounds() + ' tool-call rounds. Batch related searches and synthesize once there is enough evidence.';
            prompt += '\nMaximum returned source items: ' + args.max_results;
            prompt += '\nReturn a concise report with: summary, findings, sources, and any caveats.';
            return prompt;
        },

        getDelegateFinalPrompt: function() {
            return 'Tool budget reached. Do not call tools. Using only the gathered tool results above, write the concise final report now with: summary, findings, sources, and caveats.';
        },

        getDelegateMaxRounds: function() {
            return 6;
        },

        logDelegateDebug: function(event, details, toolId) {
            if (toolId && typeof this.updateDelegateToolCardDetails === 'function') {
                this.updateDelegateToolCardDetails(toolId, event, details || {});
            }
        },

        getOpenAIToolsForDelegate: function(tools) {
            return (tools || []).map(function(tool) {
                return {
                    type: 'function',
                    function: {
                        name: tool.name,
                        description: tool.description,
                        parameters: tool.input_schema
                    }
                };
            });
        },

        buildOpenAIToolCalls: function(toolCalls) {
            return (toolCalls || []).map(function(toolCall, index) {
                return {
                    id: toolCall.id || ('delegate_tool_' + index),
                    type: 'function',
                    function: {
                        name: toolCall.name,
                        arguments: JSON.stringify(toolCall.arguments || {})
                    }
                };
            });
        },

        runDelegateSubagent: async function(args, parentToolId) {
            var provider = this.conversationProvider || (this.getProvider ? this.getProvider() : '');
            var providerConfig = this.isConnectorsMode && this.isConnectorsMode() && typeof aiAssistantProviders !== 'undefined'
                ? aiAssistantProviders.available[provider]
                : null;
            var model = this.getSubagentModel();
            if (!model) {
                throw new Error('No model is available for delegate.');
            }

            var tools = this.getDelegateToolDefinitions(args.task_type);
            var messages = [{ role: 'user', content: this.getDelegateUserPrompt(args) }];
            var toolResults = [];
            var maxRounds = this.getDelegateMaxRounds();
            var finalText = '';
            var hitLimit = false;
            var subagentUsage = null;
            var availableToolNames = tools.map(function(tool) {
                return tool.name;
            });

            this.logDelegateDebug('start', {
                task_type: args.task_type,
                request: args.request,
                target: args.target || '',
                max_results: args.max_results,
                max_rounds: maxRounds,
                provider: provider || 'openai',
                model: model,
                available_tools: availableToolNames
            }, parentToolId);

            for (var round = 0; round < maxRounds; round++) {
                var systemPrompt = this.getDelegateSystemPrompt(args.task_type);
                var response = await this.callDelegateProvider(provider, providerConfig, model, systemPrompt, messages, tools);
                subagentUsage = this.addDelegateTokenUsage(subagentUsage, response, provider, model, systemPrompt, messages, tools);
                finalText = response.content || '';
                this.logDelegateDebug('round_response', {
                    task_type: args.task_type,
                    round: round + 1,
                    text_length: finalText.length,
                    tool_calls: (response.toolCalls || []).map(function(toolCall) {
                        return {
                            name: toolCall.name,
                            arguments: toolCall.arguments || {}
                        };
                    })
                }, parentToolId);

                if (!response.toolCalls || response.toolCalls.length === 0) {
                    var completedResult = this.buildDelegateResult(args, finalText, toolResults, false, {
                        available_tools: availableToolNames,
                        final_synthesis: false,
                        max_rounds: maxRounds,
                        subagent_usage: subagentUsage
                    });
                    this.logDelegateDebug('complete', completedResult, parentToolId);
                    return completedResult;
                }

                messages.push({
                    role: 'assistant',
                    content: finalText || null,
                    tool_calls: this.buildOpenAIToolCalls(response.toolCalls)
                });

                for (var i = 0; i < response.toolCalls.length; i++) {
                    var toolResult = await this.executeDelegateSubagentTool(args.task_type, response.toolCalls[i], args);
                    toolResults.push(toolResult);
                    this.logDelegateDebug('tool_result', {
                        task_type: args.task_type,
                        round: round + 1,
                        tool: toolResult.name,
                        input: toolResult.input,
                        success: toolResult.success,
                        result: toolResult.result
                    }, parentToolId);
                    messages.push({
                        role: 'tool',
                        tool_call_id: response.toolCalls[i].id,
                        name: response.toolCalls[i].name,
                        content: JSON.stringify(toolResult.result)
                    });
                }
            }

            hitLimit = true;
            messages.push({ role: 'user', content: this.getDelegateFinalPrompt() });
            this.logDelegateDebug('final_synthesis_start', {
                task_type: args.task_type,
                tool_result_count: toolResults.length
            }, parentToolId);

            try {
                var finalSystemPrompt = this.getDelegateSystemPrompt(args.task_type);
                var finalResponse = await this.callDelegateProvider(provider, providerConfig, model, finalSystemPrompt, messages, []);
                subagentUsage = this.addDelegateTokenUsage(subagentUsage, finalResponse, provider, model, finalSystemPrompt, messages, []);
                if (finalResponse.content) {
                    finalText = finalResponse.content;
                }
                this.logDelegateDebug('final_synthesis_response', {
                    task_type: args.task_type,
                    text_length: (finalResponse.content || '').length,
                    ignored_tool_calls: (finalResponse.toolCalls || []).length
                }, parentToolId);
            } catch (error) {
                console.warn('[AI Assistant] Delegate final synthesis failed:', error);
            }

            var limitResult = this.buildDelegateResult(args, finalText || 'The delegate reached its tool round limit before producing a final answer.', toolResults, hitLimit, {
                available_tools: availableToolNames,
                final_synthesis: true,
                max_rounds: maxRounds,
                subagent_usage: subagentUsage
            });
            this.logDelegateDebug('complete', limitResult, parentToolId);
            return limitResult;
        },

        callDelegateProvider: function(provider, providerConfig, model, systemPrompt, messages, tools) {
            if (provider === 'anthropic') {
                return this.callDelegateAnthropic(provider, model, systemPrompt, messages, tools);
            }

            if (provider === 'local' || (providerConfig && providerConfig.type === 'server')) {
                return this.callDelegateLocal(providerConfig && providerConfig.endpoint, model, systemPrompt, messages, tools);
            }

            return this.callDelegateOpenAI(provider || 'openai', model, systemPrompt, messages, tools);
        },

        callDelegateAnthropic: async function(provider, model, systemPrompt, messages, tools) {
            var endpoint = this.getProviderEndpoint(provider) || 'https://api.anthropic.com/v1/messages';
            var payload = {
                model: model,
                max_tokens: 4096,
                system: systemPrompt,
                messages: this.convertDelegateMessagesForAnthropic(messages)
            };
            if (tools && tools.length > 0) {
                payload.tools = tools;
            }

            var response = await this.fetchLLMProvider(
                provider,
                endpoint,
                {
                    'Content-Type': 'application/json',
                    'x-api-key': this.getApiKey(provider),
                    'anthropic-version': '2023-06-01',
                    'anthropic-dangerous-direct-browser-access': 'true'
                },
                payload
            );

            if (!response.ok) {
                throw new Error(await this.getProviderErrorMessage(response, 'Delegate request failed'));
            }

            var data = await response.json();
            var text = [];
            var toolCalls = [];
            (data.content || []).forEach(function(block) {
                if (block.type === 'text' && block.text) {
                    text.push(block.text);
                } else if (block.type === 'tool_use') {
                    toolCalls.push({
                        id: block.id,
                        name: block.name,
                        arguments: block.input || {}
                    });
                }
            });

            return {
                content: text.join('\n').trim(),
                toolCalls: toolCalls,
                usage: data.usage || null
            };
        },

        callDelegateOpenAI: async function(provider, model, systemPrompt, messages, tools) {
            var endpoint = (this.getProviderEndpoint(provider) || this.getProviderEndpoint('openai') || 'https://api.openai.com/v1/chat/completions');
            var apiKey = this.getApiKey(provider) || this.getApiKey('openai');
            var payload = {
                model: model,
                messages: [{ role: 'system', content: systemPrompt }].concat(messages)
            };
            if (tools && tools.length > 0) {
                payload.tools = this.getOpenAIToolsForDelegate(tools);
            }

            var response = await this.fetchLLMProvider(
                provider,
                endpoint,
                {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + apiKey
                },
                payload
            );

            if (!response.ok) {
                throw new Error(await this.getProviderErrorMessage(response, 'Delegate request failed'));
            }

            var data = await response.json();
            var message = data.choices && data.choices[0] && data.choices[0].message || {};
            return this.normalizeDelegateOpenAIMessage(message, data.usage || null);
        },

        getDelegateUsageFromResponseData: function(data) {
            if (!data || typeof data !== 'object') {
                return null;
            }
            if (data.usage) {
                return data.usage;
            }
            if (data.input_tokens || data.output_tokens || data.prompt_tokens || data.completion_tokens || data.prompt_eval_count || data.eval_count) {
                return data;
            }
            return null;
        },

        callDelegateLocal: async function(endpointOverride, model, systemPrompt, messages, tools) {
            var endpoint = (endpointOverride || this.getLocalEndpoint()).replace(/\/$/, '');
            var payload = {
                model: model,
                stream: false,
                messages: [{ role: 'system', content: systemPrompt }].concat(messages)
            };
            if (tools && tools.length > 0) {
                payload.tools = this.getOpenAIToolsForDelegate(tools);
            }

            var response = await fetch(endpoint + '/v1/chat/completions', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            if (!response.ok && (response.status === 404 || response.status === 405)) {
                response = await fetch(endpoint + '/api/chat', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
            }

            if (!response.ok) {
                throw new Error(await this.getProviderErrorMessage(response, 'Delegate request failed'));
            }

            var data = await response.json();
            var message = data.message || (data.choices && data.choices[0] && data.choices[0].message) || {};
            return this.normalizeDelegateOpenAIMessage(message, this.getDelegateUsageFromResponseData(data));
        },

        normalizeDelegateOpenAIMessage: function(message, usage) {
            var toolCalls = (message.tool_calls || []).map(function(toolCall, index) {
                var fn = toolCall.function || {};
                return {
                    id: toolCall.id || ('delegate_tool_' + index),
                    name: fn.name || toolCall.name || '',
                    arguments: safeJsonParse(fn.arguments !== undefined ? fn.arguments : toolCall.arguments, {})
                };
            }).filter(function(toolCall) {
                return !!toolCall.name;
            });

            return {
                content: (message.content || '').trim(),
                toolCalls: toolCalls,
                usage: usage || null
            };
        },

        convertDelegateMessagesForAnthropic: function(messages) {
            var converted = [];
            var pendingToolResults = [];

            function flushToolResults() {
                if (pendingToolResults.length > 0) {
                    converted.push({
                        role: 'user',
                        content: pendingToolResults
                    });
                    pendingToolResults = [];
                }
            }

            (messages || []).forEach(function(message) {
                if (message.role === 'tool') {
                    pendingToolResults.push({
                        type: 'tool_result',
                        tool_use_id: message.tool_call_id,
                        content: message.content || ''
                    });
                    return;
                }

                flushToolResults();

                if (message.role === 'assistant') {
                    var content = [];
                    if (message.content) {
                        content.push({ type: 'text', text: message.content });
                    }
                    (message.tool_calls || []).forEach(function(toolCall) {
                        var fn = toolCall.function || {};
                        content.push({
                            type: 'tool_use',
                            id: toolCall.id,
                            name: fn.name,
                            input: safeJsonParse(fn.arguments, {})
                        });
                    });
                    converted.push({ role: 'assistant', content: content });
                    return;
                }

                converted.push({
                    role: 'user',
                    content: message.content || ''
                });
            });

            flushToolResults();
            return converted;
        },

        isDelegateToolAllowed: function(taskType, toolName, args) {
            var allowed = this.getDelegateToolNames(taskType);
            if (allowed.indexOf(toolName) < 0) {
                return false;
            }

            if (toolName === 'ability' && args && args.action === 'execute') {
                return false;
            }

            return ['write_file', 'edit_file', 'delete_file', 'run_php', 'rest_api', 'execute_ability'].indexOf(toolName) < 0;
        },

        normalizeDelegateWpContentPath: function(path) {
            path = String(path || '').trim().replace(/\\/g, '/');
            path = path.replace(/^\/+/, '').replace(/^wp-content\//, '').replace(/\/+$/, '');
            return path;
        },

        getDelegatePathScope: function(taskType, delegateArgs) {
            if (taskType !== 'codebase_investigation') {
                return null;
            }

            var path = this.normalizeDelegateWpContentPath(delegateArgs && delegateArgs.target);
            if (!/^(plugins|themes|mu-plugins|uploads)\//.test(path)) {
                return null;
            }

            var basename = path.split('/').pop() || '';
            return {
                path: path,
                is_file: /\.[A-Za-z0-9_-]+$/.test(basename)
            };
        },

        isDelegatePathInScope: function(path, scope) {
            path = this.normalizeDelegateWpContentPath(path);
            if (!path || !scope) {
                return true;
            }

            if (scope.is_file) {
                return path === scope.path;
            }

            return path === scope.path || path.indexOf(scope.path + '/') === 0;
        },

        applyDelegatePathScope: function(toolName, args, delegateArgs, taskType) {
            var scope = this.getDelegatePathScope(taskType, delegateArgs);
            if (!scope || ['find', 'read_file'].indexOf(toolName) < 0) {
                return { allowed: true, args: args };
            }

            args = $.extend(true, {}, args || {});

            if (toolName === 'read_file') {
                if (!args.path && scope.is_file) {
                    args.path = scope.path;
                }
                if (!this.isDelegatePathInScope(args.path, scope)) {
                    return {
                        allowed: false,
                        args: args,
                        error: 'read_file path is outside delegate target scope: ' + scope.path
                    };
                }
                return { allowed: true, args: args };
            }

            if (scope.is_file) {
                if (args.path && !this.isDelegatePathInScope(args.path, scope)) {
                    return {
                        allowed: false,
                        args: args,
                        error: 'find path is outside delegate target scope: ' + scope.path
                    };
                }
                if (args.text && !args.path) {
                    args.path = scope.path;
                } else if (!args.text && !args.glob) {
                    args.glob = scope.path;
                    delete args.path;
                } else if (args.glob && !this.isDelegatePathInScope(args.glob, scope)) {
                    args.glob = scope.path;
                }
                return { allowed: true, args: args };
            }

            if (args.glob) {
                if (!args.path) {
                    args.path = scope.path;
                }
            } else {
                if (!args.path) {
                    args.path = scope.path;
                } else if (!this.isDelegatePathInScope(args.path, scope)) {
                    return {
                        allowed: false,
                        args: args,
                        error: 'find path is outside delegate target scope: ' + scope.path
                    };
                }
            }

            return { allowed: true, args: args };
        },

        executeDelegateSubagentTool: async function(taskType, toolCall, delegateArgs) {
            var toolName = toolCall.name;
            var args = toolCall.arguments || {};

            if (!this.isDelegateToolAllowed(taskType, toolName, args)) {
                return {
                    id: toolCall.id,
                    name: toolName,
                    input: args,
                    result: { error: 'Tool is not allowed in read-only delegate mode.' },
                    success: false
                };
            }

            var scoped = this.applyDelegatePathScope(toolName, args, delegateArgs, taskType);
            args = scoped.args;
            if (!scoped.allowed) {
                return {
                    id: toolCall.id,
                    name: toolName,
                    input: args,
                    result: { error: scoped.error },
                    success: false
                };
            }

            if (toolName === 'recall_conversations') {
                return this.executeDelegateConversationRecall(toolCall, delegateArgs);
            }

            if (toolName === 'get_page_html') {
                args.max_length = Math.min(parseInt(args.max_length || 2500, 10) || 2500, 4000);
                if (!args.selector && delegateArgs.target) {
                    args.selector = delegateArgs.target;
                }
            }

            return this.executeSingleTool({
                id: toolCall.id,
                name: toolName,
                arguments: args
            });
        },

        executeDelegateConversationRecall: function(toolCall, delegateArgs) {
            var args = toolCall.arguments || {};
            var query = args.query || delegateArgs.target || delegateArgs.request;
            var limit = Math.max(1, Math.min(10, parseInt(args.limit || delegateArgs.max_results || 5, 10)));

            return new Promise(function(resolve) {
                $.ajax({
                    url: aiAssistantConfig.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ai_assistant_recall_conversations',
                        _wpnonce: aiAssistantConfig.nonce,
                        query: query,
                        limit: limit
                    },
                    success: function(response) {
                        resolve({
                            id: toolCall.id,
                            name: 'recall_conversations',
                            input: { query: query, limit: limit },
                            result: response.success ? response.data : { error: response.data?.message || 'Failed to recall conversations' },
                            success: !!response.success
                        });
                    },
                    error: function(xhr, status, error) {
                        resolve({
                            id: toolCall.id,
                            name: 'recall_conversations',
                            input: { query: query, limit: limit },
                            result: { error: error || 'Conversation recall failed' },
                            success: false
                        });
                    }
                });
            });
        },

        collectDelegateSources: function(toolResults, maxResults) {
            var sources = [];
            var seen = {};

            function add(source) {
                if (!source || !source.label) {
                    return;
                }
                var key = source.type + ':' + source.label;
                if (seen[key]) {
                    return;
                }
                seen[key] = true;
                sources.push(source);
            }

            (toolResults || []).forEach(function(result) {
                var output = result.result || {};
                if (output.path) {
                    add({ type: 'file', label: output.path });
                }
                if (output.matches) {
                    output.matches.forEach(function(match) {
                        add({ type: 'file', label: match.path || match });
                    });
                }
                if (output.conversations) {
                    output.conversations.forEach(function(conversation) {
                        add({ type: 'conversation', label: conversation.title || ('Conversation ' + conversation.id), id: conversation.id });
                    });
                }
                if (output.selector) {
                    add({ type: 'page', label: output.selector, url: output.url || '' });
                }
            });

            return sources.slice(0, maxResults || 5);
        },

        extractDelegateFindings: function(summary) {
            return String(summary || '')
                .split(/\n+/)
                .map(function(line) {
                    return line.replace(/^\s*[-*]\s*/, '').trim();
                })
                .filter(function(line) {
                    return line.length > 0;
                })
                .slice(0, 8);
        },

        buildDelegateResult: function(args, finalText, toolResults, hitLimit, options) {
            options = options || {};
            var summary = this.stripReasoningTokens
                ? this.stripReasoningTokens(finalText || '')
                : String(finalText || '');
            summary = summary.trim() || 'The delegate completed but did not return text.';
            var toolCalls = (toolResults || []).map(function(result) {
                return {
                    name: result.name,
                    input: result.input,
                    success: !!result.success
                };
            });

            return {
                task_type: args.task_type,
                summary: summary,
                findings: this.extractDelegateFindings(summary),
                sources: this.collectDelegateSources(toolResults, args.max_results),
                available_tools: options.available_tools || [],
                max_rounds: options.max_rounds || this.getDelegateMaxRounds(),
                tool_calls: toolCalls,
                omitted: {
                    hidden_tool_results: true,
                    tool_result_count: toolResults.length,
                    round_limit_reached: !!hitLimit,
                    final_synthesis: !!options.final_synthesis
                },
                _subagent_usage: options.subagent_usage || null
            };
        }
    });
})(jQuery);
