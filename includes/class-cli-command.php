<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WP-CLI commands for AI Assistant diagnostics.
 */
class CLI_Command {

    /**
     * Check how a plugin integrates with AI Assistant.
     *
     * ## OPTIONS
     *
     * <plugin>
     * : Plugin slug as shown by wp plugin list, for example memex.
     *
     * [--format=<format>]
     * : Output format. Use table, json, or yaml.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - yaml
     * ---
     *
     * [--url-component=<component>]
     * : URL path component to use when resolving contextual welcome tips.
     *
     * [--strict]
     * : Exit with an error when warnings are present.
     */
    public function integration_check(array $args, array $assoc_args): void {
        $plugin_slug = sanitize_key((string) ($args[0] ?? ''));
        if ($plugin_slug === '') {
            \WP_CLI::error('Missing plugin slug.');
        }

        $format = (string) ($assoc_args['format'] ?? 'table');
        $report = (new Integration_Inspector())->inspect($plugin_slug, [
            'url_component' => sanitize_key((string) ($assoc_args['url-component'] ?? $plugin_slug)),
        ]);

        if ($format !== 'table') {
            \WP_CLI\Utils\format_items($format, [$report], array_keys($report));
            $this->maybe_fail_strict($report, !empty($assoc_args['strict']));
            return;
        }

        $this->render_table_report($report);
        $this->maybe_fail_strict($report, !empty($assoc_args['strict']));
    }

    private function render_table_report(array $report): void {
        $plugin = $report['plugin'];
        \WP_CLI::line('AI Assistant integration check: ' . $plugin['slug']);
        \WP_CLI::line('');
        \WP_CLI::line('Plugin: ' . ($plugin['name'] ?: '(not found)'));
        \WP_CLI::line('File: ' . ($plugin['file'] ?: '(unknown)'));
        \WP_CLI::line('Active: ' . (!empty($plugin['active']) ? 'yes' : 'no'));

        \WP_CLI::line('');
        \WP_CLI::line('Abilities:');
        if (empty($report['abilities'])) {
            \WP_CLI::line('  none');
        } else {
            foreach ($report['abilities'] as $ability) {
                \WP_CLI::line('  ' . $ability['id']);
                \WP_CLI::line('    readonly: ' . ($ability['readonly'] ? 'yes' : 'no'));
                \WP_CLI::line('    destructive: ' . ($ability['destructive'] ? 'yes' : 'no'));
                \WP_CLI::line('    input_schema: ' . ($ability['has_input_schema'] ? 'ok' : 'missing'));
                \WP_CLI::line('    output_schema: ' . ($ability['has_output_schema'] ? 'ok' : 'missing'));
                \WP_CLI::line('    instructions: ' . ($ability['has_instructions'] ? 'present' : 'missing'));
            }
        }

        \WP_CLI::line('');
        \WP_CLI::line('System prompt section:');
        if (trim((string) $report['system_prompt_section']) === '') {
            \WP_CLI::line('  none');
        } else {
            foreach (explode("\n", trim((string) $report['system_prompt_section'])) as $line) {
                \WP_CLI::line('  ' . $line);
            }
        }

        \WP_CLI::line('');
        \WP_CLI::line('Welcome tips:');
        if (empty($report['welcome_tips'])) {
            \WP_CLI::line('  none');
        } else {
            foreach ($report['welcome_tips'] as $tip) {
                \WP_CLI::line('  - ' . $tip);
            }
        }

        \WP_CLI::line('');
        \WP_CLI::line('Conversation export formats:');
        if (empty($report['conversation_export_formats'])) {
            \WP_CLI::line('  none');
        } else {
            foreach ($report['conversation_export_formats'] as $format) {
                \WP_CLI::line(sprintf('  - %s (.%s)', $format['label'], $format['extension']));
            }
        }

        \WP_CLI::line('');
        \WP_CLI::line('Warnings:');
        if (empty($report['warnings'])) {
            \WP_CLI::line('  none');
        } else {
            foreach ($report['warnings'] as $warning) {
                \WP_CLI::warning($warning);
            }
        }
    }

    private function maybe_fail_strict(array $report, bool $strict): void {
        if ($strict && !empty($report['warnings'])) {
            \WP_CLI::error('AI Assistant integration check failed with warnings.');
        }
    }
}
