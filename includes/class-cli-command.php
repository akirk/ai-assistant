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
     *   Omit when using --all-active.
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
        $all_active = !empty($assoc_args['all-active']);
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
                \WP_CLI::error('Missing plugin slug. Pass a plugin slug or use --all-active.');
            }
            $plugin_slugs = [$plugin_slug];
        }

        $format = (string) ($assoc_args['format'] ?? 'table');
        $url_component = array_key_exists('url-component', $assoc_args)
            ? sanitize_key((string) $assoc_args['url-component'])
            : '';
        $reports = [];
        foreach ($plugin_slugs as $plugin_slug) {
            $options = [];
            if ($url_component !== '') {
                $options['url_component'] = $url_component;
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

            foreach ($reports as $index => $report) {
                if ($index > 0) {
                    \WP_CLI::line('');
                    \WP_CLI::line(str_repeat('-', 72));
                    \WP_CLI::line('');
                }
                $this->render_verbose_report($report, count($reports) > 1);
            }
        } elseif (count($reports) > 1) {
            $this->render_summary_table($reports, $url_component);
        } else {
            $this->render_summary_report($reports[0], $url_component);
        }

        $this->maybe_fail_strict($reports, !empty($assoc_args['strict']));
    }

    private function render_summary_report(array $report, string $url_component): void {
        $plugin = $report['plugin'];
        $summary = $this->get_report_summary($report, $url_component);

        \WP_CLI::line('AI Assistant integration check: ' . $plugin['slug']);
        \WP_CLI::line('');
        \WP_CLI::line('Plugin: ' . ($plugin['name'] ?: '(not found)'));
        \WP_CLI::line('File: ' . ($plugin['file'] ?: '(unknown)'));
        \WP_CLI::line('Active: ' . (!empty($plugin['active']) ? 'yes' : 'no'));
        \WP_CLI::line('Abilities: ' . $summary['abilities']);
        if (!empty($report['abilities'])) {
            \WP_CLI::line(sprintf(
                'Ability status: %d readonly, %d mutating, %d destructive',
                $summary['readonly'],
                $summary['mutating'],
                $summary['destructive']
            ));
        }
        \WP_CLI::line('Domain: ' . $summary['domain']);
        if (!empty($report['ability_domains'])) {
            $domains = array_values($report['ability_domains']);
            \WP_CLI::line('Domain terms: ' . $this->truncate_text((string) $domains[0], 140));
        }
        \WP_CLI::line('Prompt routing: ' . $summary['prompt']);
        if ($url_component !== '') {
            \WP_CLI::line('URL tips for /' . trim($url_component, '/') . '/: ' . $summary['tips']);
        }
        \WP_CLI::line('Warnings: ' . $summary['warnings']);

        if (!empty($report['warnings'])) {
            \WP_CLI::line('');
            \WP_CLI::line('Warnings:');
            foreach ($report['warnings'] as $warning) {
                \WP_CLI::line('- ' . $warning);
            }
        }
    }

    private function render_summary_table(array $reports, string $url_component): void {
        \WP_CLI::line('AI Assistant integration check');
        if ($url_component !== '') {
            \WP_CLI::line('');
            \WP_CLI::line('URL context: /' . trim($url_component, '/') . '/');
            \WP_CLI::line('Tips: ' . count($reports[0]['welcome_tips'] ?? []));
        }
        \WP_CLI::line('');

        $rows = [];
        foreach ($reports as $report) {
            $summary = $this->get_report_summary($report, $url_component);
            $rows[] = [
                'plugin' => $report['plugin']['slug'],
                'active' => $summary['active'],
                'abilities' => $summary['abilities'],
                'domain' => $summary['domain'],
                'prompt' => $summary['prompt'],
                'tips' => $summary['tips'],
                'warnings' => $summary['warnings'],
            ];
        }

        \WP_CLI\Utils\format_items('table', $rows, ['plugin', 'active', 'abilities', 'domain', 'prompt', 'tips', 'warnings']);

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

    private function render_url_context(string $url_component, array $welcome_tips): void {
        \WP_CLI::line('URL context: /' . trim($url_component, '/') . '/');
        \WP_CLI::line('');
        \WP_CLI::line('Welcome tips:');
        if (empty($welcome_tips)) {
            \WP_CLI::line('  none');
        } else {
            foreach ($welcome_tips as $tip) {
                \WP_CLI::line('  - ' . $tip);
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

    private function get_report_summary(array $report, string $url_component): array {
        $abilities = (array) ($report['abilities'] ?? []);
        $readonly = 0;
        $destructive = 0;
        foreach ($abilities as $ability) {
            if (!empty($ability['readonly'])) {
                $readonly++;
            }
            if (!empty($ability['destructive'])) {
                $destructive++;
            }
        }

        $ability_count = count($abilities);
        $tips = $url_component === '' ? 'n/a' : (string) count($report['welcome_tips'] ?? []);

        return [
            'active' => !empty($report['plugin']['active']) ? 'yes' : 'no',
            'abilities' => (string) $ability_count,
            'readonly' => $readonly,
            'mutating' => max(0, $ability_count - $readonly),
            'destructive' => $destructive,
            'domain' => !empty($report['ability_domains']) ? 'yes' : 'no',
            'prompt' => trim((string) ($report['system_prompt_section'] ?? '')) !== '' ? 'yes' : 'no',
            'tips' => $tips,
            'warnings' => (string) count($report['warnings'] ?? []),
        ];
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

    private function truncate_text(string $text, int $max_length): string {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if (strlen($text) <= $max_length) {
            return $text;
        }

        return rtrim(substr($text, 0, max(0, $max_length - 3))) . '...';
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
