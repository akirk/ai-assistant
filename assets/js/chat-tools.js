/* exported aiAssistantToolsMixin */
var aiAssistantToolsMixin = (function() {
    'use strict';

    // Tools enabled via enable_tools during this conversation (local LLM tiering)
    var activeExtendedTools = [];

    return {

        // Core tools - always available
        coreToolNames: ['run_php', 'read_file', 'edit_file', 'write_file', 'find', 'environment_info', 'ability', 'skill'],

        // Extended tools - loaded on demand for local LLMs, always available for cloud
        extendedToolNames: ['delete_file', 'db_query', 'rest_api', 'install_plugin', 'navigate', 'get_page_html', 'summarize_conversation'],

        getAllToolDefinitions: function() {
            return [
                {
                    name: 'read_file',
                    description: 'Read the contents of a file within wp-content directory',
                    input_schema: {
                        type: 'object',
                        properties: {
                            path: { type: 'string', description: 'Relative path from wp-content (e.g., "plugins/my-plugin/file.php")' }
                        },
                        required: ['path']
                    }
                },
                {
                    name: 'write_file',
                    description: 'Write or overwrite a file within wp-content directory. Use ONLY for creating NEW files.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            path: { type: 'string', description: 'Relative path from wp-content' },
                            content: { type: 'string', description: 'The content to write to the file' },
                            reason: { type: 'string', description: 'Brief explanation of why this file is being created (used for change tracking)' }
                        },
                        required: ['path', 'content', 'reason']
                    }
                },
                {
                    name: 'edit_file',
                    description: 'Edit an existing file by applying search and replace operations. Use this for modifying existing files instead of write_file. Each edit finds a unique string and replaces it.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            path: { type: 'string', description: 'Relative path from wp-content' },
                            edits: {
                                type: 'array',
                                description: 'Array of edit operations to apply in order',
                                items: {
                                    type: 'object',
                                    properties: {
                                        search: { type: 'string', description: 'The exact string to find (must be unique in the file)' },
                                        replace: { type: 'string', description: 'The string to replace it with' }
                                    },
                                    required: ['search', 'replace']
                                }
                            },
                            reason: { type: 'string', description: 'Brief explanation of why this file is being edited (used for change tracking)' }
                        },
                        required: ['path', 'edits', 'reason']
                    }
                },
                {
                    name: 'find',
                    description: 'Find files and content in wp-content. Provide path alone to list a directory, glob to search filenames, or text to search file contents.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            path: { type: 'string', description: 'Directory to list or search in (relative to wp-content)' },
                            glob: { type: 'string', description: 'Glob pattern to match filenames (e.g., "plugins/*/*.php")' },
                            text: { type: 'string', description: 'Text to search for in file contents' },
                            file_pattern: { type: 'string', description: 'File extension filter when searching text (e.g., "*.php")' }
                        }
                    }
                },
                {
                    name: 'run_php',
                    description: 'Execute PHP code in the WordPress environment. Use for standard WordPress functions like wp_insert_post(), get_option(), WP_Query, etc.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            code: { type: 'string', description: 'PHP code to execute. Do not include <?php tags. The code should return a value that will be sent back as the result.' }
                        },
                        required: ['code']
                    }
                },
                {
                    name: 'environment_info',
                    description: 'Get WordPress environment info: active plugins, themes, WP/PHP versions, and site URLs.',
                    input_schema: {
                        type: 'object',
                        properties: {}
                    }
                },
                {
                    name: 'delete_file',
                    description: 'Delete a file within wp-content directory',
                    input_schema: {
                        type: 'object',
                        properties: {
                            path: { type: 'string', description: 'Relative path from wp-content' },
                            reason: { type: 'string', description: 'Brief explanation of why this file is being deleted (used for change tracking)' }
                        },
                        required: ['path', 'reason']
                    }
                },
                {
                    name: 'db_query',
                    description: 'Execute a SELECT query on the WordPress database',
                    input_schema: {
                        type: 'object',
                        properties: {
                            sql: { type: 'string', description: 'The SELECT SQL query. Use {prefix} for table prefix.' }
                        },
                        required: ['sql']
                    }
                },
                {
                    name: 'rest_api',
                    description: 'Make WordPress REST API requests. Use GET to read data and POST/PUT/PATCH/DELETE to modify it. Standard namespaces: /wp/v2/posts, /wp/v2/pages, /wp/v2/users, /wp/v2/media, /wp/v2/taxonomies, /wp/v2/settings. Discover all available routes via GET /.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            method: { type: 'string', enum: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], description: 'HTTP method' },
                            path: { type: 'string', description: 'REST API path, e.g. /wp/v2/posts or /wp/v2/posts/123' },
                            params: { type: 'object', description: 'Query string parameters for GET requests, e.g. {"per_page": 10, "status": "draft"}' },
                            body: { type: 'object', description: 'Request body for POST/PUT/PATCH requests' }
                        },
                        required: ['method', 'path']
                    }
                },
                {
                    name: 'install_plugin',
                    description: 'Install a plugin from the WordPress.org plugin directory. The slug is typically the plugin URL path on wordpress.org (e.g., wordpress.org/plugins/contact-form-7 → slug is "contact-form-7").',
                    input_schema: {
                        type: 'object',
                        properties: {
                            slug: { type: 'string', description: 'The plugin slug from wordpress.org (e.g., "akismet", "contact-form-7", "woocommerce")' },
                            activate: { type: 'boolean', description: 'Whether to activate the plugin after installation (default: false)' }
                        },
                        required: ['slug']
                    }
                },
                {
                    name: 'ability',
                    description: 'WordPress abilities API. List available abilities, get details of one, or execute one.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            action: { type: 'string', enum: ['list', 'get', 'execute'], description: 'Action to perform' },
                            ability: { type: 'string', description: 'Ability identifier (for get/execute, e.g., "core/create-post")' },
                            category: { type: 'string', description: 'Category filter (for list, e.g., "content", "media", "users")' },
                            arguments: { type: 'object', description: 'Arguments to pass when executing' }
                        },
                        required: ['action']
                    }
                },
                {
                    name: 'navigate',
                    description: 'Navigate the user to a URL within the WordPress site. This will reload the page, so it should typically be the last action in a conversation turn.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            url: { type: 'string', description: 'The URL to navigate to. Can be a full URL or a relative path (e.g., "/wp-admin/edit.php").' }
                        },
                        required: ['url']
                    }
                },
                {
                    name: 'get_page_html',
                    description: 'Get the HTML content of elements on the current page the user is viewing.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            selector: { type: 'string', description: 'CSS selector to query (e.g., "#main-content", ".entry-title", "body").' },
                            max_length: { type: 'number', description: 'Maximum characters to return per element (default: 5000).' }
                        },
                        required: ['selector']
                    }
                },
                {
                    name: 'summarize_conversation',
                    description: 'Generate a compact summary of a conversation and store it for future reference.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            conversation_id: { type: 'number', description: 'The conversation ID to summarize. Use 0 or omit for the current conversation.' }
                        }
                    }
                },
                {
                    name: 'skill',
                    description: 'Load AI skill documents with specialized knowledge. List available skills or get a specific one.',
                    input_schema: {
                        type: 'object',
                        properties: {
                            action: { type: 'string', enum: ['list', 'get'], description: 'Action to perform' },
                            skill: { type: 'string', description: 'Skill identifier (for get)' },
                            category: { type: 'string', description: 'Category filter (for list)' }
                        },
                        required: ['action']
                    }
                }
            ];
        },

        getEnableToolsDef: function() {
            return {
                name: 'enable_tools',
                description: 'Enable additional tools for this conversation. Available: ' + this.extendedToolNames.join(', '),
                input_schema: {
                    type: 'object',
                    properties: {
                        tools: {
                            type: 'array',
                            items: { type: 'string' },
                            description: 'Tool names to enable'
                        }
                    },
                    required: ['tools']
                }
            };
        },

        getActiveExtendedTools: function() {
            return activeExtendedTools;
        },

        setActiveExtendedTools: function(tools) {
            activeExtendedTools = tools;
        },

        resetActiveExtendedTools: function() {
            activeExtendedTools = [];
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

            // Filter to enabled tools
            var available = allTools.filter(function(tool) {
                return self.isToolEnabled(tool.name, enabled);
            });

            var provider = this.conversationProvider || this.getProvider();

            // For local LLMs: only core tools + enable_tools + any dynamically enabled extended tools
            if (provider === 'local') {
                var coreNames = self.coreToolNames;
                var active = activeExtendedTools;
                var filtered = available.filter(function(tool) {
                    return coreNames.indexOf(tool.name) >= 0 || active.indexOf(tool.name) >= 0;
                });
                // Add enable_tools if there are still-disabled extended tools available
                var hasMoreExtended = self.extendedToolNames.some(function(name) {
                    return active.indexOf(name) < 0 && self.isToolEnabled(name, enabled);
                });
                if (hasMoreExtended) {
                    filtered.push(self.getEnableToolsDef());
                }
                return filtered;
            }

            return available;
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

        executeEnableTools: function(toolCall) {
            var self = this;
            var args = toolCall.arguments || {};
            var requested = args.tools || [];

            var enabled = this.getEnabledToolNames();
            var allTools = self.getAllToolDefinitions();
            var current = self.getActiveExtendedTools().slice();
            var added = [];
            var definitions = [];

            requested.forEach(function(name) {
                if (self.extendedToolNames.indexOf(name) < 0) {
                    return; // not a valid extended tool
                }
                if (!self.isToolEnabled(name, enabled)) {
                    return; // not permitted for this user
                }
                if (current.indexOf(name) < 0) {
                    current.push(name);
                    added.push(name);
                }
                // Always return the definition (even if already enabled)
                var def = allTools.find(function(t) { return t.name === name; });
                if (def) {
                    definitions.push(def);
                }
            });

            self.setActiveExtendedTools(current);

            return {
                id: toolCall.id,
                name: 'enable_tools',
                input: args,
                result: {
                    enabled: added,
                    tools: definitions
                },
                success: true
            };
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
