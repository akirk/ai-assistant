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
     * [<plugin>]
     * : Plugin slug as shown by wp plugin list, for example memex.
     *   Omit to check active plugins.
     *
     * [--all-active]
     * : Check every active plugin.
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
     * : URL path component to use when resolving contextual welcome tips. This is independent of the plugin slug.
     *
     * [--verbose]
     * : Show full ability details, prompt text, welcome tip text, and export formats.
     *
     * [--strict]
     * : Exit with an error when warnings are present.
     *
     * @subcommand integration-check
     */
    public function integration_check(array $args, array $assoc_args): void {
        $all_active = !empty($assoc_args['all-active']) || empty($args);
        $inspector = new Integration_Inspector();
        $plugin_slugs = [];

        if ($all_active) {
            $plugin_slugs = $inspector->get_active_plugin_slugs();
            if (empty($plugin_slugs)) {
                \WP_CLI::error('No active plugins found.');
            }
        } else {
            $plugin_slug = sanitize_key((string) ($args[0] ?? ''));
            if ($plugin_slug === '') {
                \WP_CLI::error('Missing plugin slug.');
            }
            $plugin_slugs = [$plugin_slug];
        }

        $format = (string) ($assoc_args['format'] ?? 'table');
        $url_component = array_key_exists('url-component', $assoc_args)
            ? sanitize_key((string) $assoc_args['url-component'])
            : $this->get_url_component_from_cli_url();
        $reports = [];
        foreach ($plugin_slugs as $plugin_slug) {
            $options = [];
            if ($url_component !== '') {
                $options['url_component'] = $url_component;
            }
            if ($all_active) {
                $options['warn_missing_integration'] = false;
            }
            $reports[] = $inspector->inspect($plugin_slug, $options);
        }

        if ($format !== 'table') {
            $items = count($reports) > 1
                ? $this->prepare_multi_report_output($reports, $url_component)
                : $reports;
            \WP_CLI\Utils\format_items($format, $items, array_keys($items[0]));
            $this->maybe_fail_strict($reports, !empty($assoc_args['strict']));
            return;
        }

        if (!empty($assoc_args['verbose'])) {
            if (count($reports) > 1 && $url_component !== '') {
                $this->render_url_context($url_component, $reports[0]['welcome_tips'] ?? []);
            }
            if (count($reports) > 1) {
                $this->render_global_export_formats($reports[0]['conversation_export_formats'] ?? []);
            }

            foreach ($reports as $index => $report) {
                if ($index > 0) {
                    \WP_CLI::line('');
                    \WP_CLI::line(str_repeat('-', 72));
                    \WP_CLI::line('');
                }
                $this->render_verbose_report($report, count($reports) > 1);
            }
        } elseif (count($reports) > 1) {
            $this->render_summary_table($reports, $url_component, $all_active);
        } else {
            $this->render_summary_report($reports[0], $url_component);
        }

        $this->maybe_fail_strict($reports, !empty($assoc_args['strict']));
    }

    private function render_summary_report(array $report, string $url_component): void {
        $plugin = $report['plugin'];
        $summary = $this->get_report_summary($report);

        \WP_CLI::line('AI Assistant integration check: ' . ($plugin['name'] ?: $plugin['slug']));
        \WP_CLI::line('');
        \WP_CLI::line('Active: ' . (!empty($plugin['active']) ? 'yes' : 'no'));
        \WP_CLI::line('Abilities: ' . $summary['abilities']);
        foreach ((array) ($report['abilities'] ?? []) as $ability) {
            \WP_CLI::line('  - ' . $ability['id']);
            if (!empty($ability['description'])) {
                \WP_CLI::line('    ' . $ability['description']);
            }
        }
        \WP_CLI::line('');
        if (!empty($report['ability_domains'])) {
            $domains = array_values($report['ability_domains']);
            \WP_CLI::line('Guidance for using this plugin when:');
            \WP_CLI::line('  ' . (string) $domains[0]);
        } else {
            \WP_CLI::line('Guidance for using this plugin when:');
            \WP_CLI::line('  none');
        }
        \WP_CLI::line('');
        if ($url_component !== '') {
            \WP_CLI::line('On /' . trim($url_component, '/') . '/ shows tips:');
            $this->render_tip_items((array) ($report['welcome_tips'] ?? []));
        } elseif (!empty($report['route_tips'])) {
            $this->render_route_tips($report['route_tips']);
        }

        if (!empty($report['warnings'])) {
            \WP_CLI::line('');
            \WP_CLI::line('Warnings: ' . $summary['warnings']);
            foreach ($report['warnings'] as $warning) {
                \WP_CLI::line('- ' . $warning);
            }
        }
    }

    private function render_summary_table(array $reports, string $url_component, bool $all_active): void {
        \WP_CLI::line('AI Assistant integration check');
        if ($url_component !== '') {
            \WP_CLI::line('');
            \WP_CLI::line('URL context: /' . trim($url_component, '/') . '/');
            $this->render_tip_summary($reports[0]['welcome_tips'] ?? []);
        }
        \WP_CLI::line('');

        $rows = [];
        $without_integration = 0;
        $show_route_tips = $url_component === '' && $this->has_route_tips($reports);
        foreach ($reports as $report) {
            if ($all_active && !$this->report_has_signal($report)) {
                $without_integration++;
                continue;
            }

            $summary = $this->get_report_summary($report);
            $row = [
                'plugin' => $report['plugin']['slug'],
                'abilities' => $summary['abilities'],
                'used_for' => $summary['domain'],
                'warnings' => $summary['warnings'],
            ];
            if ($show_route_tips) {
                $tip_count = $this->count_route_tips((array) ($report['route_tips'] ?? []));
                $row['route_tips'] = $tip_count > 0 ? (string) $tip_count : '';
            }
            if (!$all_active) {
                $row = array_merge(['plugin' => $report['plugin']['slug'], 'active' => $summary['active']], array_slice($row, 1));
            }
            $rows[] = $row;
        }

        if (empty($rows)) {
            \WP_CLI::line('No AI Assistant integrations detected.');
        } else {
            $fields = array_keys($rows[0]);
            \WP_CLI\Utils\format_items('table', $rows, $fields);
        }

        if ($without_integration > 0) {
            \WP_CLI::line('');
            \WP_CLI::line(sprintf(
                'No AI Assistant integration detected: %d active plugin%s. Use --verbose to list them.',
                $without_integration,
                $without_integration === 1 ? '' : 's'
            ));
        }

        $warnings = $this->collect_warnings($reports);
        if (!empty($warnings)) {
            \WP_CLI::line('');
            \WP_CLI::line('Warnings:');
            foreach ($warnings as $warning) {
                \WP_CLI::line('- ' . $warning);
            }
        }
    }

    private function render_verbose_report(array $report, bool $omit_url_context = false): void {
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

        if (!$omit_url_context) {
            \WP_CLI::line('');
            \WP_CLI::line('Welcome tips:');
            if (empty($report['welcome_tips'])) {
                \WP_CLI::line('  none');
            } else {
                foreach ($report['welcome_tips'] as $tip) {
                    \WP_CLI::line('  - ' . $tip);
                }
            }
        }

        if (!empty($report['route_tips'])) {
            \WP_CLI::line('');
            $this->render_route_tips($report['route_tips']);
        }

        if (!$omit_url_context) {
            \WP_CLI::line('');
            \WP_CLI::line('Global conversation export formats:');
            if (empty($report['conversation_export_formats'])) {
                \WP_CLI::line('  none');
            } else {
                foreach ($report['conversation_export_formats'] as $format) {
                    \WP_CLI::line(sprintf('  - %s (.%s)', $format['label'], $format['extension']));
                }
            }
        }

        \WP_CLI::line('');
        \WP_CLI::line('Warnings:');
        if (empty($report['warnings'])) {
            \WP_CLI::line('  none');
        } else {
            foreach ($report['warnings'] as $warning) {
                \WP_CLI::line('  - ' . $warning);
            }
        }
    }

    private function render_url_context(string $url_component, array $welcome_tips): void {
        \WP_CLI::line('URL context: /' . trim($url_component, '/') . '/');
        \WP_CLI::line('');
        $this->render_tip_summary($welcome_tips);
        \WP_CLI::line('');
        \WP_CLI::line(str_repeat('-', 72));
        \WP_CLI::line('');
    }

    private function render_tip_summary(array $welcome_tips): void {
        \WP_CLI::line('Welcome tips:');
        $this->render_tip_items($welcome_tips);
    }

    private function render_tip_items(array $tips): void {
        if (empty($tips)) {
            \WP_CLI::line('  none');
            return;
        }

        foreach ($tips as $tip) {
            \WP_CLI::line('  - ' . $tip);
        }
    }

    private function render_route_tips(array $route_tips): void {
        foreach ($route_tips as $route => $tips) {
            \WP_CLI::line('On /' . trim((string) $route, '/') . '/ shows tips:');
            $this->render_tip_items((array) $tips);
        }
    }

    private function get_url_component_from_cli_url(): string {
        if (!class_exists('\WP_CLI') || !method_exists('\WP_CLI', 'get_config')) {
            return '';
        }

        $url = (string) \WP_CLI::get_config('url');
        if ($url === '') {
            return '';
        }

        if (!preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }

        $parts = explode('/', $path);
        return sanitize_key((string) ($parts[0] ?? ''));
    }

    private function render_global_export_formats(array $formats): void {
        \WP_CLI::line('Global conversation export formats:');
        if (empty($formats)) {
            \WP_CLI::line('  none');
        } else {
            foreach ($formats as $format) {
                \WP_CLI::line(sprintf('  - %s (.%s)', $format['label'], $format['extension']));
            }
        }
        \WP_CLI::line('');
        \WP_CLI::line(str_repeat('-', 72));
        \WP_CLI::line('');
    }

    private function prepare_multi_report_output(array $reports, string $url_component): array {
        if ($url_component === '') {
            return $reports;
        }

        foreach ($reports as &$report) {
            $report['url_context'] = [
                'url_component' => $url_component,
                'welcome_tips' => $report['welcome_tips'] ?? [],
            ];
            unset($report['welcome_tips']);
        }
        unset($report);

        return $reports;
    }

    private function get_report_summary(array $report): array {
        $abilities = (array) ($report['abilities'] ?? []);
        $ability_count = count($abilities);

        return [
            'active' => !empty($report['plugin']['active']) ? 'yes' : 'no',
            'abilities' => (string) $ability_count,
            'domain' => !empty($report['ability_domains']) ? 'yes' : 'no',
            'prompt' => trim((string) ($report['system_prompt_section'] ?? '')) !== '' ? 'yes' : 'no',
            'warnings' => (string) count($report['warnings'] ?? []),
        ];
    }

    private function report_has_signal(array $report): bool {
        return !empty($report['abilities'])
            || !empty($report['ability_domains'])
            || !empty($report['system_prompt_section'])
            || !empty($report['route_tips'])
            || !empty($report['warnings']);
    }

    private function has_route_tips(array $reports): bool {
        foreach ($reports as $report) {
            if (!empty($report['route_tips'])) {
                return true;
            }
        }

        return false;
    }

    private function count_route_tips(array $route_tips): int {
        $count = 0;
        foreach ($route_tips as $tips) {
            $count += count((array) $tips);
        }

        return $count;
    }

    private function collect_warnings(array $reports): array {
        $warnings = [];
        foreach ($reports as $report) {
            $slug = (string) ($report['plugin']['slug'] ?? '');
            foreach ((array) ($report['warnings'] ?? []) as $warning) {
                $warnings[] = $slug !== '' ? "{$slug}: {$warning}" : (string) $warning;
            }
        }

        return $warnings;
    }

    private function maybe_fail_strict(array $reports, bool $strict): void {
        if (!$strict) {
            return;
        }

        foreach ($reports as $report) {
            if (!empty($report['warnings'])) {
                \WP_CLI::error('AI Assistant integration check failed with warnings.');
            }
        }

        if (!isset($reports[0]['warnings']) && !empty($reports['warnings'])) {
            \WP_CLI::error('AI Assistant integration check failed with warnings.');
        }
    }
}
