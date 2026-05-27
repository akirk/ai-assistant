/* exported aiAssistantToolsMixin */
var aiAssistantToolsMixin = (function() {
    'use strict';

    return {

        getAbilityToolDescription: function() {
            var config = this.getRuntimeConfig();
            var domains = config.abilityDomains || {};
            var keys = Object.keys(domains);
            var base = 'Plugin abilities: list, get, or execute. Ability domain slugs are categories, not executable ability IDs; list by category and get the exact ability ID before execute.';
            if (keys.length === 0) {
                return base + ' Use for plugin-specific data and actions.';
            }
            var domainParts = keys.map(function(slug) { return slug + ' (' + domains[slug] + ')'; });
            return base + ' ALWAYS use this for: ' + domainParts.join('; ') + '. Do not use db_query or find for these topics.';
        },

        getRuntimeConfig: function() {
            if (typeof aiAssistantConfig !== 'undefined') {
                return aiAssistantConfig;
            }
            if (typeof window !== 'undefined' && window.aiAssistantConfig) {
                return window.aiAssistantConfig;
            }
            if (typeof global !== 'undefined' && global.aiAssistantConfig) {
                return global.aiAssistantConfig;
            }
            return {};
        },

        getConfiguredToolDefinitions: function() {
            var config = this.getRuntimeConfig();
            var definitions = config.toolDefinitions || config.clientToolDefinitions || [];
            return Array.isArray(definitions) ? definitions : [];
        },

        mergeToolDefinitions: function(coreDefinitions, extensionDefinitions) {
            var byName = {};
            var ordered = [];

            function add(definition) {
                if (!definition || !definition.name) {
                    return;
                }
                if (!Object.prototype.hasOwnProperty.call(byName, definition.name)) {
                    ordered.push(definition.name);
                }
                byName[definition.name] = definition;
            }

            (coreDefinitions || []).forEach(add);
            (extensionDefinitions || []).forEach(add);

            return ordered.map(function(name) {
                return byName[name];
            });
        },

        getAllToolDefinitions: function() {
            var coreDefinitions = [
                {
                    name: 'ability',
                    description: this.getAbilityToolDescription(),
                    input_schema: {
                        type: 'object',
                        properties: {
                            action: { type: 'string', enum: ['list', 'get', 'execute'] },
                            ability: { type: 'string', description: 'Ability identifier' },
                            category: { type: 'string' },
                            arguments: { type: 'object' }
                        },
                        required: ['action']
                    }
                },
                {
                    name: 'read_file',
                    description: 'Read one file in wp-content. Use search with before_lines/after_lines for targeted snippets, or offset/max_length for byte chunks. For multi-file or large-file analysis, call delegate with task_type=codebase_investigation.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            path: { type: 'string', description: 'Relative path from wp-content' },
                            offset: { type: 'number', description: 'Byte offset to start reading from. Omit for the beginning.' },
                            max_length: { type: 'number', description: 'Maximum bytes to return. Use smaller chunks for large files.' },
                            search: { type: 'string', description: 'Exact text to locate before returning a line window, e.g. a function name.' },
                            before_lines: { type: 'number', description: 'Lines to include before the search match.' },
                            after_lines: { type: 'number', description: 'Lines to include after the search match.' },
                            occurrence: { type: 'number', description: '1-based match occurrence when search appears multiple times.' }
                        },
                        required: ['path']
                    }
                },
                {
                    name: 'find',
                    description: 'Find files/content in wp-content. Broad text search: mode=paths returns paths only. Broad analysis: call delegate with task_type=codebase_investigation. Narrow with path, glob, text, or file_pattern.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            path: { type: 'string', description: 'Directory or file (relative to wp-content)' },
                            glob: { type: 'string', description: 'Filename glob pattern; relative to path when path is supplied' },
                            text: { type: 'string', description: 'Search string' },
                            file_pattern: { type: 'string', description: 'File filter for text search' },
                            mode: { type: 'string', enum: ['snippets', 'paths'], description: 'Text search output: snippets returns matching lines; paths returns file paths only.' },
                            max_results: { type: 'number', description: 'Text search result cap. paths mode allows larger caps.' }
                        }
                    }
                },
                {
                    name: 'environment_info',
                    description: 'Get active plugins with titles/descriptions, theme, WP/PHP versions.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            include_inactive: { type: 'boolean' }
                        }
                    }
                },
                {
                    name: 'db_query',
                    description: 'Read-only database query (SELECT, DESCRIBE, SHOW). Use {prefix} for table prefix.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            sql: { type: 'string' }
                        },
                        required: ['sql']
                    }
                },
                {
                    name: 'rest_api',
                    description: 'WordPress REST API. Preferred for post/page drafts: POST /wp/v2/posts or /wp/v2/pages with status "draft". GET / discovers routes; writes require approval.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            method: { type: 'string', enum: ['GET', 'OPTIONS', 'POST', 'PUT', 'PATCH', 'DELETE'] },
                            path: { type: 'string', description: 'e.g. /wp/v2/posts or /wp/v2/posts/123' },
                            params: { type: 'object', description: 'Query params for GET' },
                            body: { type: 'object', description: 'Request body for write methods' }
                        },
                        required: ['method', 'path']
                    }
                },
                {
                    name: 'navigate',
                    description: 'Suggest that the user open an in-site URL. Renders a clickable link; does not open the page automatically.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            url: { type: 'string', description: 'The in-site URL to suggest.' },
                            link_text: { type: 'string', description: 'Short clickable link text to display to the user.' }
                        },
                        required: ['url', 'link_text']
                    }
                },
                {
                    name: 'get_page_html',
                    description: 'Get HTML of current page elements. For broad page checks, call delegate with task_type=page_inspection.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            selector: { type: 'string', description: 'CSS selector' },
                            max_length: { type: 'number' }
                        },
                        required: ['selector']
                    }
                },
                {
                    name: 'pick_image',
                    description: 'Ask the user to choose or upload an image and return the selected image details. Use one image per call. Do not call this tool multiple times in the same response; wait for the selection before requesting another image.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            query: { type: 'string', description: 'Initial search.' },
                            purpose: { type: 'string', description: 'Image use.' }
                        },
                        required: ['query']
                    }
                },
                {
                    name: 'summarize_conversation',
                    description: 'Summarize and store a conversation. To recall past work, call delegate with task_type=conversation_recall.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            conversation_id: { type: 'number', description: 'Omit for current conversation' }
                        }
                    }
                },
                {
                    name: 'inspect_tool_result',
                    description: 'Inspect a cached full result from a previous tool call when its provider context was compacted. Use path plus search/before_lines/after_lines or offset/max_length to retrieve a narrow piece without rerunning the original tool.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            tool_use_id: { type: 'string', description: 'The previous tool call/result ID to inspect.' },
                            path: { type: 'string', description: 'Dot path inside the cached result, e.g. result.article.content.' },
                            search: { type: 'string', description: 'Exact text to locate inside a string value.' },
                            before_lines: { type: 'number', description: 'Lines to include before the search match.' },
                            after_lines: { type: 'number', description: 'Lines to include after the search match.' },
                            occurrence: { type: 'number', description: '1-based match occurrence when search appears multiple times.' },
                            offset: { type: 'number', description: 'Character offset for string chunks.' },
                            max_length: { type: 'number', description: 'Maximum characters to return.' }
                        },
                        required: ['tool_use_id']
                    }
                },
                {
                    name: 'delegate',
                    description: 'Read-only subagent for broad context gathering: codebase_investigation, conversation_recall, page_inspection. Returns a concise report; no writes.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            task_type: {
                                type: 'string',
                                enum: ['codebase_investigation', 'conversation_recall', 'page_inspection'],
                                description: 'The kind of read-only investigation to run.'
                            },
                            request: {
                                type: 'string',
                                description: 'Specific question or investigation brief for the subagent.'
                            },
                            target: {
                                type: 'string',
                                description: 'Optional path, search phrase, conversation hint, or CSS selector to focus the task.'
                            },
                            max_results: {
                                type: 'number',
                                description: 'Optional result limit; the assistant may clamp this.'
                            }
                        },
                        required: ['task_type', 'request']
                    }
                },
                {
                    name: 'skill',
                    description: 'Load skill documents with specialized knowledge.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            action: { type: 'string', enum: ['list', 'get'] },
                            skill: { type: 'string' },
                            category: { type: 'string' }
                        },
                        required: ['action']
                    }
                }
            ];

            return this.mergeToolDefinitions(coreDefinitions, this.getConfiguredToolDefinitions());
        },

        getEnabledToolNames: function() {
            return (typeof window !== 'undefined' && window.aiAssistantConfig && window.aiAssistantConfig.enabledTools) ||
                   this._enabledTools ||
                   [];
        },

        getTools: function() {
            var enabled = this.getEnabledToolNames();
            var allTools = this.getAllToolDefinitions();
            var self = this;
            return allTools.filter(function(tool) {
                return self.isToolEnabled(tool.name, enabled);
            });
        },

        // Check if a consolidated tool name is enabled based on the user's permission settings.
        // Consolidated tools (find, ability, skill) are enabled if ANY of their component tools are enabled.
        isToolEnabled: function(toolName, enabled) {
            switch (toolName) {
                case 'find':
                    return enabled.indexOf('list_directory') >= 0 ||
                           enabled.indexOf('search_files') >= 0 ||
                           enabled.indexOf('search_content') >= 0;
                case 'ability':
                    return enabled.indexOf('list_abilities') >= 0 ||
                           enabled.indexOf('get_ability') >= 0 ||
                           enabled.indexOf('execute_ability') >= 0;
                case 'skill':
                    return enabled.indexOf('list_skills') >= 0 ||
                           enabled.indexOf('get_skill') >= 0;
                case 'environment_info':
                    return enabled.indexOf('environment_info') >= 0 ||
                           enabled.indexOf('get_plugins') >= 0 ||
                           enabled.indexOf('get_themes') >= 0;
                case 'inspect_tool_result':
                    return enabled.indexOf('inspect_tool_result') >= 0;
                default:
                    return enabled.indexOf(toolName) >= 0;
            }
        },

        getToolsOpenAI: function() {
            return this.getTools().map(function(tool) {
                return {
                    type: 'function',
                    function: {
                        name: tool.name,
                        description: tool.description,
                        parameters: tool.input_schema
                    }
                };
            });
        }
    };
})();

// Browser: merge into shared namespace
if (typeof window !== 'undefined' && typeof jQuery !== 'undefined') {
    jQuery.extend(window.aiAssistant, aiAssistantToolsMixin);
}

// Node: export for testing
if (typeof module !== 'undefined' && module.exports) {
    module.exports = aiAssistantToolsMixin;
}
