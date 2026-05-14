/* exported aiAssistantToolsMixin */
var aiAssistantToolsMixin = (function() {
    'use strict';

    return {

        getAbilityToolDescription: function() {
            var domains = (typeof aiAssistantConfig !== 'undefined' && aiAssistantConfig.abilityDomains) || {};
            var keys = Object.keys(domains);
            if (keys.length === 0) {
                return 'Plugin abilities: list, get, or execute. Use for plugin-specific data and actions.';
            }
            var domainParts = keys.map(function(slug) { return slug + ' (' + domains[slug] + ')'; });
            return 'Plugin abilities: list, get, or execute. ALWAYS use this for: ' + domainParts.join('; ') + '. Do not use db_query or find for these topics.';
        },

        getAllToolDefinitions: function() {
            return [
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
                    description: 'Read a file in wp-content',
                    input_schema: {
                        type: 'object',
                        properties: {
                            path: { type: 'string', description: 'Relative path from wp-content' }
                        },
                        required: ['path']
                    }
                },
                {
                    name: 'write_file',
                    description: 'Create a new file in wp-content. Use edit_file for existing files.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            path: { type: 'string', description: 'Relative path from wp-content' },
                            content: { type: 'string' },
                            reason: { type: 'string' }
                        },
                        required: ['path', 'content', 'reason']
                    }
                },
                {
                    name: 'edit_file',
                    description: 'Edit a file via search/replace operations. Each search string must be unique in the file.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            path: { type: 'string', description: 'Relative path from wp-content' },
                            edits: {
                                type: 'array',
                                items: {
                                    type: 'object',
                                    properties: {
                                        search: { type: 'string' },
                                        replace: { type: 'string' }
                                    },
                                    required: ['search', 'replace']
                                }
                            },
                            reason: { type: 'string' }
                        },
                        required: ['path', 'edits', 'reason']
                    }
                },
                {
                    name: 'find',
                    description: 'Find files or content in wp-content. Omit all params to list root. Use path to list a directory, glob to match filenames, text to search file contents.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            path: { type: 'string', description: 'Directory (relative to wp-content)' },
                            glob: { type: 'string', description: 'Filename glob pattern' },
                            text: { type: 'string', description: 'Search string' },
                            file_pattern: { type: 'string', description: 'File filter for text search' }
                        }
                    }
                },
                {
                    name: 'run_php',
                    description: 'Execute PHP in WordPress. Prefer rest_api for post/page drafts. No <?php tag. Return a value.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            code: { type: 'string' }
                        },
                        required: ['code']
                    }
                },
                {
                    name: 'environment_info',
                    description: 'Get active plugins, theme, WP/PHP versions.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            include_inactive: { type: 'boolean' }
                        }
                    }
                },
                {
                    name: 'delete_file',
                    description: 'Delete a file in wp-content',
                    input_schema: {
                        type: 'object',
                        properties: {
                            path: { type: 'string', description: 'Relative path from wp-content' },
                            reason: { type: 'string' }
                        },
                        required: ['path', 'reason']
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
                    name: 'install_plugin',
                    description: 'Install a plugin from wordpress.org by slug.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            slug: { type: 'string' },
                            activate: { type: 'boolean' }
                        },
                        required: ['slug']
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
                    description: 'Get HTML of elements on the current page.',
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
                    description: 'Ask the user to choose or upload an image and return the selected image details.',
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
                    description: 'Summarize a conversation and store it.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            conversation_id: { type: 'number', description: 'Omit for current conversation' }
                        }
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
