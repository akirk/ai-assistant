<?php
namespace AI_Assistant;

if (!defined('ABSPATH') && !defined('AI_ASSISTANT_FILE_TOOLS_ENDPOINT')) {
    exit;
}

/**
 * Read-only viewer for ordinary .git repositories, with pure-PHP checkout.
 *
 * This intentionally does not mutate refs, branches, or the Git index. Checkout
 * copies files from a commit tree into the working tree and removes files that
 * are tracked by the current checked-out/head tree but absent from the target.
 *
 * Loose objects, packed refs, pack indexes, and packed delta objects are read
 * directly in PHP.
 */
class External_Git_Tracker {

    private string $work_tree;
    private string $git_dir;
    private ?string $last_error = null;
    private ?array $pack_indexes = null;
    private array $object_cache = [];
    private array $pack_data_cache = [];
    private array $pack_offset_cache = [];
    private const CHECKOUTS_OPTION = 'ai_assistant_external_git_checkouts';
    private const DEBUG_VERSION = 'external-git-debug-2026-05-26-shallow';

    public function __construct(string $work_tree) {
        $this->work_tree = rtrim($work_tree, '/');
        $this->git_dir = $this->work_tree . '/.git';
    }

    public function get_work_tree(): string {
        return $this->work_tree;
    }

    public function is_active(): bool {
        return is_dir($this->git_dir);
    }

    public function has_readable_head(): bool {
        $head = $this->get_head_sha();
        return $head !== null && $this->is_valid_commit($head);
    }

    public function get_info(): array {
        $head_sha = $this->get_head_sha();
        $checked_out_sha = $this->get_checked_out_commit();
        $commits = $this->get_recent_commits();
        $tracked_file_count = 0;

        if ($head_sha !== null) {
            $tracked_file_count = count($this->get_commit_tree_files($head_sha));
        }
        $debug = $this->get_debug_info($commits, $head_sha, $tracked_file_count);
        $status_message = $this->last_error ?: ($debug['diagnosis']['message'] ?? null);

        return [
            'path' => basename($this->work_tree),
            'name' => $this->get_name(),
            'work_tree' => $this->work_tree,
            'files' => [],
            'file_count' => 0,
            'tracked_file_count' => $tracked_file_count,
            'commits' => $commits,
            'commit_count' => count($commits),
            'checked_out_sha' => $checked_out_sha,
            'is_external_git' => true,
            'supports_file_actions' => false,
            'status_message' => $status_message,
            'debug' => $debug,
        ];
    }

    public function get_summary_info(): array {
        return [
            'path' => basename($this->work_tree),
            'name' => $this->get_name(),
            'work_tree' => $this->work_tree,
            'files' => [],
            'file_count' => 0,
            'tracked_file_count' => null,
            'commits' => [],
            'commit_count' => null,
            'checked_out_sha' => null,
            'is_external_git' => true,
            'supports_file_actions' => false,
            'status_message' => null,
            'debug' => [],
        ];
    }

    public function get_recent_commits(int $limit = 20, int $offset = 0): array {
        if (!$this->is_active()) {
            return [];
        }

        $head_sha = $this->get_head_sha();
        $refs_by_sha = $this->get_commit_refs();
        if (empty($refs_by_sha)) {
            return [];
        }

        $checked_out_sha = $this->get_checked_out_commit();
        $commits_by_sha = [];
        $stack = array_keys($refs_by_sha);

        while (!empty($stack)) {
            $sha = array_pop($stack);
            if (isset($commits_by_sha[$sha])) {
                continue;
            }

            $commit_data = $this->read_object($sha);
            if ($commit_data === null || $commit_data['type'] !== 'commit') {
                continue;
            }

            $parsed = $this->parse_commit($commit_data['content']);
            $commits_by_sha[$sha] = [
                'sha' => $sha,
                'short_sha' => substr($sha, 0, 7),
                'tree' => $parsed['tree'],
                'parent' => $parsed['parent'],
                'message' => $this->summarize_message($parsed['message']),
                'conversation_id' => null,
                'timestamp' => $parsed['timestamp'],
                'date' => $parsed['timestamp'] ? date('Y-m-d H:i:s', $parsed['timestamp']) : null,
                'is_latest' => $sha === $head_sha,
                'is_checked_out' => $checked_out_sha !== null && $sha === $checked_out_sha,
                'refs' => $refs_by_sha[$sha] ?? [],
            ];

            foreach ($parsed['parents'] as $parent_sha) {
                if (!isset($commits_by_sha[$parent_sha])) {
                    $stack[] = $parent_sha;
                }
            }
        }

        $commits = array_values($commits_by_sha);
        usort($commits, function($a, $b) {
            $a_time = $a['timestamp'] ?? 0;
            $b_time = $b['timestamp'] ?? 0;
            if ($a_time === $b_time) {
                return strcmp($b['sha'], $a['sha']);
            }

            return $b_time <=> $a_time;
        });

        return array_slice($commits, $offset, $limit);
    }

    public function get_commit_log(int $limit = 20, int $offset = 0): array {
        $commits = $this->get_recent_commits($limit + 1, $offset);
        $has_more = count($commits) > $limit;
        if ($has_more) {
            array_pop($commits);
        }

        return ['commits' => $commits, 'has_more' => $has_more];
    }

    public function get_commit_summary(string $sha): ?array {
        if (!$this->is_active() || !$this->is_valid_sha($sha)) {
            return null;
        }

        $commit_data = $this->read_object($sha);
        if ($commit_data === null || $commit_data['type'] !== 'commit') {
            return null;
        }

        $parsed = $this->parse_commit($commit_data['content']);
        $head_sha = $this->get_head_sha();
        $checked_out_sha = $this->get_checked_out_commit();
        $refs_by_sha = $this->get_commit_refs();

        return [
            'sha' => $sha,
            'short_sha' => substr($sha, 0, 7),
            'tree' => $parsed['tree'],
            'parent' => $parsed['parent'],
            'message' => $this->summarize_message($parsed['message']),
            'conversation_id' => null,
            'timestamp' => $parsed['timestamp'],
            'date' => $parsed['timestamp'] ? date('Y-m-d H:i:s', $parsed['timestamp']) : null,
            'is_latest' => $sha === $head_sha,
            'is_checked_out' => $checked_out_sha !== null && $sha === $checked_out_sha,
            'refs' => $refs_by_sha[$sha] ?? [],
        ];
    }

    public function get_commit_diff(string $sha): string {
        $commit_data = $this->read_object($sha);
        if ($commit_data === null || $commit_data['type'] !== 'commit') {
            return '';
        }

        $parsed = $this->parse_commit($commit_data['content']);
        if (!$parsed['tree']) {
            return '';
        }

        $current_files = $this->get_tree_files($parsed['tree'], '');
        $parent_files = [];
        if ($parsed['parent']) {
            $parent_files = $this->get_commit_tree_files($parsed['parent']);
        }

        $all_paths = array_unique(array_merge(array_keys($current_files), array_keys($parent_files)));
        sort($all_paths);

        $diffs = [];
        foreach ($all_paths as $path) {
            $old_sha = $parent_files[$path]['sha'] ?? null;
            $new_sha = $current_files[$path]['sha'] ?? null;

            if ($old_sha === $new_sha) {
                continue;
            }

            $old_content = $old_sha ? $this->read_blob($old_sha) : null;
            $new_content = $new_sha ? $this->read_blob($new_sha) : null;

            if ($old_sha && $old_content === null) {
                $diffs[] = $this->format_unavailable_diff($path, 'old');
                continue;
            }
            if ($new_sha && $new_content === null) {
                $diffs[] = $this->format_unavailable_diff($path, 'new');
                continue;
            }

            if ($old_content === null && $new_content !== null) {
                $diffs[] = $this->format_diff($path, '', $new_content, 'created');
            } elseif ($old_content !== null && $new_content === null) {
                $diffs[] = $this->format_diff($path, $old_content, '', 'deleted');
            } else {
                $diffs[] = $this->format_diff($path, $old_content ?? '', $new_content ?? '', 'modified');
            }
        }

        return implode("\n", $diffs);
    }

    public function checkout_commit(string $target_sha): array {
        if (!$this->is_active()) {
            return ['success' => false, 'errors' => ['Git repository not available']];
        }

        if (!$this->is_valid_commit($target_sha)) {
            return ['success' => false, 'errors' => [$this->last_error ?: 'Invalid commit SHA']];
        }

        $head_sha = $this->get_head_sha();
        $target_files = $this->get_commit_tree_files($target_sha);
        if ($this->last_error !== null) {
            return ['success' => false, 'errors' => [$this->last_error]];
        }

        $tracked_before = [];
        if ($head_sha !== null) {
            $tracked_before = $this->get_commit_tree_files($head_sha);
            if ($this->last_error !== null) {
                return ['success' => false, 'errors' => [$this->last_error]];
            }
        }

        $checked_out_sha = $this->get_checked_out_commit();
        if ($checked_out_sha !== null && $checked_out_sha !== $head_sha && $this->is_valid_commit($checked_out_sha)) {
            $tracked_before = array_merge($tracked_before, $this->get_commit_tree_files($checked_out_sha));
            if ($this->last_error !== null) {
                return ['success' => false, 'errors' => [$this->last_error]];
            }
        }

        $changed = [];
        $errors = [];
        $target_contents = [];

        foreach ($target_files as $path => $info) {
            $content = $this->read_blob($info['sha']);
            if ($content === null) {
                $errors[] = $this->last_error ?: "Could not read content for: $path";
                continue;
            }

            $full_path = $this->work_tree . '/' . $path;
            if (is_dir($full_path)) {
                $errors[] = "Cannot replace directory with file: $path";
                continue;
            }

            $target_contents[$path] = $content;
        }

        if (!empty($errors)) {
            return [
                'success' => false,
                'checked_out' => [],
                'reverted' => [],
                'errors' => $errors,
            ];
        }

        foreach ($target_contents as $path => $content) {
            $full_path = $this->work_tree . '/' . $path;

            $dir = dirname($full_path);
            if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                $errors[] = "Could not create directory for: $path";
                continue;
            }

            if (file_put_contents($full_path, $content) === false) {
                $errors[] = "Could not write file: $path";
                continue;
            }

            $changed[] = $path;
        }

        foreach (array_keys($tracked_before) as $path) {
            if (isset($target_files[$path])) {
                continue;
            }

            $full_path = $this->work_tree . '/' . $path;
            if (is_file($full_path) || is_link($full_path)) {
                if (@unlink($full_path)) {
                    $changed[] = $path;
                    $this->remove_empty_parent_dirs(dirname($full_path));
                } else {
                    $errors[] = "Could not remove file: $path";
                }
            }
        }

        if (!empty($errors)) {
            return [
                'success' => false,
                'checked_out' => $changed,
                'reverted' => $changed,
                'errors' => $errors,
            ];
        }

        if ($head_sha !== null && $target_sha === $head_sha) {
            $this->clear_checkout_state();
        } else {
            $this->write_checkout_state($target_sha);
        }

        return [
            'success' => true,
            'checked_out' => array_values(array_unique($changed)),
            'reverted' => array_values(array_unique($changed)),
            'checked_out_sha' => $target_sha,
            'previous_head' => $head_sha,
            'errors' => [],
        ];
    }

    public function get_checked_out_commit(): ?string {
        $states = $this->read_checkout_states();
        $key = $this->get_state_key();
        $sha = isset($states[$key]) && is_string($states[$key]) ? $states[$key] : null;

        if (!$sha || !$this->is_valid_sha($sha)) {
            return null;
        }

        return $this->is_valid_commit($sha) ? $sha : null;
    }

    public function get_name(): string {
        $dir_name = basename($this->work_tree);
        $fallback_name = ucwords(str_replace(['-', '_'], ' ', $dir_name));

        $style_file = $this->work_tree . '/style.css';
        if (file_exists($style_file)) {
            $content = file_get_contents($style_file, false, null, 0, 8192);
            if (preg_match('/^\s*Theme Name:\s*(.+)$/mi', (string) $content, $matches)) {
                return trim($matches[1]);
            }
        }

        $main_file = $this->work_tree . '/' . $dir_name . '.php';
        if (!file_exists($main_file)) {
            $files = glob($this->work_tree . '/*.php');
            if (!empty($files)) {
                $main_file = $files[0];
            }
        }

        if (file_exists($main_file)) {
            $content = file_get_contents($main_file, false, null, 0, 8192);
            if (preg_match('/^\s*\*?\s*Plugin Name:\s*(.+)$/mi', (string) $content, $matches)) {
                return trim($matches[1]);
            }
        }

        return $fallback_name;
    }

    private function get_head_sha(): ?string {
        $head_path = $this->git_dir . '/HEAD';
        if (!file_exists($head_path)) {
            $this->last_error = 'Git HEAD is missing.';
            return null;
        }

        $head = trim((string) file_get_contents($head_path));
        if (strpos($head, 'ref: ') === 0) {
            return $this->read_ref(trim(substr($head, 5)));
        }

        if ($this->is_valid_sha($head)) {
            return $head;
        }

        $this->last_error = 'Git HEAD does not point to a readable commit.';
        return null;
    }

    private function read_ref(string $ref): ?string {
        if (!$this->is_safe_ref($ref)) {
            $this->last_error = 'Git ref is invalid.';
            return null;
        }

        $ref_path = $this->git_dir . '/' . $ref;
        if (file_exists($ref_path)) {
            $sha = trim((string) file_get_contents($ref_path));
            return $this->is_valid_sha($sha) ? $sha : null;
        }

        $packed = $this->read_packed_ref($ref);
        if ($packed !== null) {
            return $packed;
        }

        $this->last_error = 'Git HEAD ref is missing.';
        return null;
    }

    private function read_packed_ref(string $ref): ?string {
        $packed_refs = $this->git_dir . '/packed-refs';
        if (!file_exists($packed_refs)) {
            return null;
        }

        $lines = file($packed_refs, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return null;
        }

        foreach ($lines as $line) {
            if ($line === '' || $line[0] === '#' || $line[0] === '^') {
                continue;
            }

            $parts = preg_split('/\s+/', $line, 2);
            if (count($parts) === 2 && $parts[1] === $ref && $this->is_valid_sha($parts[0])) {
                return $parts[0];
            }
        }

        return null;
    }

    private function get_commit_refs(): array {
        $head_sha = $this->get_head_sha();
        $refs = array_merge(
            $this->read_loose_refs('refs/heads'),
            $this->read_loose_refs('refs/remotes'),
            $this->read_packed_refs()
        );
        $refs_by_sha = $this->build_commit_refs_by_sha($refs, $head_sha);

        if (count($refs_by_sha) <= 1) {
            $refs = array_merge($refs, $this->read_fetch_head_refs(), $this->read_reflog_refs());
            $refs_by_sha = $this->build_commit_refs_by_sha($refs, $head_sha);
        }

        return $refs_by_sha;
    }

    private function build_commit_refs_by_sha(array $refs, ?string $head_sha): array {
        $refs_by_sha = [];

        if ($head_sha !== null && $this->is_valid_commit($head_sha)) {
            $refs_by_sha[$head_sha][] = 'HEAD';
        }

        foreach ($refs as $ref => $sha) {
            if (!$this->is_valid_commit($sha)) {
                continue;
            }

            $refs_by_sha[$sha][] = $this->format_ref_label($ref);
        }

        foreach ($refs_by_sha as $sha => $labels) {
            $refs_by_sha[$sha] = array_values(array_unique(array_filter($labels)));
        }

        return $refs_by_sha;
    }

    private function read_loose_refs(string $relative_dir): array {
        $base = $this->git_dir . '/' . $relative_dir;
        if (!is_dir($base)) {
            return [];
        }

        $refs = [];
        $this->collect_loose_refs($base, $relative_dir, $refs);
        return $refs;
    }

    private function collect_loose_refs(string $dir, string $relative_dir, array &$refs): void {
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            $ref = $relative_dir . '/' . $entry;
            if (is_dir($path)) {
                $this->collect_loose_refs($path, $ref, $refs);
                continue;
            }

            $content = trim((string) file_get_contents($path));
            if (strpos($content, 'ref: ') === 0) {
                $sha = $this->read_ref(trim(substr($content, 5)));
            } else {
                $sha = $this->is_valid_sha($content) ? $content : null;
            }

            if ($sha !== null) {
                $refs[$ref] = $sha;
            }
        }
    }

    private function read_packed_refs(): array {
        $packed_refs = $this->git_dir . '/packed-refs';
        if (!file_exists($packed_refs)) {
            return [];
        }

        $lines = file($packed_refs, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $refs = [];
        foreach ($lines as $line) {
            if ($line === '' || $line[0] === '#' || $line[0] === '^') {
                continue;
            }

            $parts = preg_split('/\s+/', $line, 2);
            if (count($parts) !== 2 || !$this->is_valid_sha($parts[0])) {
                continue;
            }

            if (strpos($parts[1], 'refs/heads/') !== 0 && strpos($parts[1], 'refs/remotes/') !== 0) {
                continue;
            }

            $refs[$parts[1]] = $parts[0];
        }

        return $refs;
    }

    private function read_fetch_head_refs(): array {
        $path = $this->git_dir . '/FETCH_HEAD';
        if (!file_exists($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $refs = [];
        foreach ($lines as $index => $line) {
            if (!preg_match('/^([a-f0-9]{40})\b/', $line, $sha_match)) {
                continue;
            }

            $label = 'FETCH_HEAD/' . ($index + 1);
            if (preg_match("/\\bbranch '([^']+)' of /", $line, $branch_match)) {
                $label = 'FETCH_HEAD/' . $branch_match[1];
            } elseif (preg_match("/\\btag '([^']+)' of /", $line, $tag_match)) {
                $label = 'FETCH_HEAD/tags/' . $tag_match[1];
            }

            $refs[$label] = $sha_match[1];
        }

        return $refs;
    }

    private function read_reflog_refs(): array {
        $logs_dir = $this->git_dir . '/logs';
        if (!is_dir($logs_dir)) {
            return [];
        }

        $refs = [];
        $this->collect_reflog_refs($logs_dir, 'logs', $refs);
        return $refs;
    }

    private function collect_reflog_refs(string $dir, string $relative_dir, array &$refs): void {
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            $relative = $relative_dir . '/' . $entry;
            if (is_dir($path)) {
                $this->collect_reflog_refs($path, $relative, $refs);
                continue;
            }

            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }

            foreach ($lines as $line) {
                if (!preg_match('/^[a-f0-9]{40}\s+([a-f0-9]{40})\b/', $line, $matches)) {
                    continue;
                }

                if ($matches[1] === str_repeat('0', 40)) {
                    continue;
                }

                $refs['REFLOG/' . substr($relative, strlen('logs/')) . '/' . substr($matches[1], 0, 7)] = $matches[1];
            }
        }
    }

    private function format_ref_label(string $ref): string {
        if (strpos($ref, 'refs/heads/') === 0) {
            return substr($ref, strlen('refs/heads/'));
        }

        if (strpos($ref, 'refs/remotes/') === 0) {
            return substr($ref, strlen('refs/remotes/'));
        }

        if (strpos($ref, 'FETCH_HEAD/') === 0) {
            return 'fetch/' . substr($ref, strlen('FETCH_HEAD/'));
        }

        if (strpos($ref, 'REFLOG/') === 0) {
            return 'reflog/' . substr($ref, strlen('REFLOG/'));
        }

        return $ref;
    }

    private function get_debug_info(array $commits, ?string $head_sha, int $tracked_file_count): array {
        $raw_head = null;
        $head_path = $this->git_dir . '/HEAD';
        if (file_exists($head_path)) {
            $raw_head = trim((string) file_get_contents($head_path));
        }

        $loose_heads = $this->read_loose_refs('refs/heads');
        $loose_remotes = $this->read_loose_refs('refs/remotes');
        $packed_refs = $this->read_packed_refs();
        $fetch_head_refs = $this->read_fetch_head_refs();
        $reflog_refs = $this->read_reflog_refs();
        $shallow_commits = $this->read_shallow_commits();
        $commit_refs = $this->get_commit_refs();

        $debug = [
            'debug_version' => self::DEBUG_VERSION,
            'git_dir_exists' => is_dir($this->git_dir),
            'head' => [
                'raw' => $raw_head,
                'sha' => $head_sha,
            ],
            'shallow' => [
                'exists' => file_exists($this->git_dir . '/shallow'),
                'commit_count' => count($shallow_commits),
                'commits' => array_map(fn($sha) => substr($sha, 0, 12), array_slice($shallow_commits, 0, 20)),
            ],
            'counts' => [
                'loose_heads' => count($loose_heads),
                'loose_remotes' => count($loose_remotes),
                'packed_refs' => count($packed_refs),
                'fetch_head_refs' => count($fetch_head_refs),
                'reflog_refs' => count($reflog_refs),
                'valid_commit_ref_tips' => count($commit_refs),
                'displayed_commits' => count($commits),
                'tracked_files_at_head' => $tracked_file_count,
                'pack_indexes' => count($this->get_pack_indexes()),
            ],
            'refs' => [
                'loose_heads' => $this->summarize_refs($loose_heads),
                'loose_remotes' => $this->summarize_refs($loose_remotes),
                'packed_refs' => $this->summarize_refs($packed_refs),
                'fetch_head_refs' => $this->summarize_refs($fetch_head_refs),
                'reflog_refs' => $this->summarize_refs($reflog_refs),
                'valid_commit_refs_by_sha' => $this->summarize_refs_by_sha($commit_refs),
            ],
            'displayed_commits' => $this->summarize_commits($commits),
            'last_error' => $this->last_error,
        ];

        $debug['diagnosis'] = $this->diagnose_history($debug);
        return $debug;
    }

    private function read_shallow_commits(): array {
        $path = $this->git_dir . '/shallow';
        if (!file_exists($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        return array_values(array_filter(array_map('trim', $lines), fn($line) => $this->is_valid_sha($line)));
    }

    private function diagnose_history(array $debug): array {
        $counts = $debug['counts'] ?? [];
        $displayed_commits = (int) ($counts['displayed_commits'] ?? 0);
        $valid_ref_tips = (int) ($counts['valid_commit_ref_tips'] ?? 0);
        $is_shallow = !empty($debug['shallow']['exists']);

        if ($displayed_commits <= 1 && $valid_ref_tips <= 1) {
            if ($is_shallow) {
                return [
                    'limited' => true,
                    'reason' => 'shallow_single_ref',
                    'message' => 'Only one commit is available from this shallow Git checkout. The main branch history is not present in this .git directory.',
                ];
            }

            return [
                'limited' => true,
                'reason' => 'single_ref',
                'message' => 'Only one commit is reachable from refs in this .git directory. Additional branch history is not present locally.',
            ];
        }

        if ($is_shallow) {
            return [
                'limited' => true,
                'reason' => 'shallow',
                'message' => 'This is a shallow Git checkout, so some history may be unavailable.',
            ];
        }

        return [
            'limited' => false,
            'reason' => null,
            'message' => null,
        ];
    }

    private function summarize_refs(array $refs, int $limit = 60): array {
        ksort($refs);
        $summary = [];
        $count = 0;

        foreach ($refs as $ref => $sha) {
            if ($count >= $limit) {
                $summary['...'] = count($refs) - $limit . ' more';
                break;
            }
            $summary[$this->format_ref_label((string) $ref)] = substr((string) $sha, 0, 12);
            $count++;
        }

        return $summary;
    }

    private function summarize_refs_by_sha(array $refs_by_sha, int $limit = 60): array {
        ksort($refs_by_sha);
        $summary = [];
        $count = 0;

        foreach ($refs_by_sha as $sha => $refs) {
            if ($count >= $limit) {
                $summary['...'] = count($refs_by_sha) - $limit . ' more';
                break;
            }
            $summary[substr((string) $sha, 0, 12)] = array_values((array) $refs);
            $count++;
        }

        return $summary;
    }

    private function summarize_commits(array $commits, int $limit = 20): array {
        $summary = [];
        foreach (array_slice($commits, 0, $limit) as $commit) {
            $summary[] = [
                'sha' => substr((string) ($commit['sha'] ?? ''), 0, 12),
                'refs' => array_values((array) ($commit['refs'] ?? [])),
                'message' => (string) ($commit['message'] ?? ''),
                'timestamp' => $commit['timestamp'] ?? null,
                'latest' => !empty($commit['is_latest']),
            ];
        }

        return $summary;
    }

    private function parse_commit(string $content): array {
        $tree = null;
        $parent = null;
        $parents = [];
        $timestamp = null;
        $message = '';
        $in_message = false;

        foreach (explode("\n", $content) as $line) {
            if ($in_message) {
                $message .= ($message ? "\n" : '') . $line;
            } elseif ($line === '') {
                $in_message = true;
            } elseif (strpos($line, 'tree ') === 0) {
                $tree = substr($line, 5);
            } elseif (strpos($line, 'parent ') === 0) {
                $parent_sha = substr($line, 7);
                if ($parent === null) {
                    $parent = $parent_sha;
                }
                $parents[] = $parent_sha;
            } elseif (strpos($line, 'author ') === 0 && preg_match('/(\d+)\s+[+-]\d{4}$/', $line, $matches)) {
                $timestamp = (int) $matches[1];
            }
        }

        return [
            'tree' => $tree,
            'parent' => $parent,
            'parents' => $parents,
            'timestamp' => $timestamp,
            'message' => trim($message),
        ];
    }

    private function summarize_message(string $message): string {
        $message = trim($message);
        if ($message === '') {
            return '(no commit message)';
        }

        $first_line = strtok($message, "\n");
        return $first_line !== false ? $first_line : $message;
    }

    private function get_commit_tree_files(string $commit_sha): array {
        $commit_data = $this->read_object($commit_sha);
        if ($commit_data === null || $commit_data['type'] !== 'commit') {
            return [];
        }

        $parsed = $this->parse_commit($commit_data['content']);
        if (!$parsed['tree']) {
            return [];
        }

        return $this->get_tree_files($parsed['tree'], '');
    }

    private function get_tree_files(string $tree_sha, string $prefix): array {
        $tree_data = $this->read_object($tree_sha);
        if ($tree_data === null || $tree_data['type'] !== 'tree') {
            return [];
        }

        $files = [];
        foreach ($this->parse_tree($tree_data['content']) as $entry) {
            $path = $prefix ? $prefix . '/' . $entry['name'] : $entry['name'];
            if (!$this->is_safe_path($path)) {
                continue;
            }

            if ($entry['mode'] === '40000') {
                $files = array_merge($files, $this->get_tree_files($entry['sha'], $path));
            } elseif (strpos($entry['mode'], '100') === 0) {
                $files[$path] = [
                    'sha' => $entry['sha'],
                    'mode' => $entry['mode'],
                ];
            }
        }

        return $files;
    }

    private function read_blob(string $sha): ?string {
        $object = $this->read_object($sha);
        if ($object === null || $object['type'] !== 'blob') {
            return null;
        }

        return $object['content'];
    }

    private function read_object(string $sha): ?array {
        if (!$this->is_valid_sha($sha)) {
            $this->last_error = 'Invalid Git object SHA.';
            return null;
        }

        if (isset($this->object_cache[$sha])) {
            return $this->object_cache[$sha];
        }

        $path = $this->git_dir . '/objects/' . substr($sha, 0, 2) . '/' . substr($sha, 2);
        if (file_exists($path)) {
            $compressed = file_get_contents($path);
            if ($compressed === false) {
                $this->last_error = 'Could not read Git object: ' . substr($sha, 0, 7);
                return null;
            }

            $data = @gzuncompress($compressed);
            if ($data === false) {
                $this->last_error = 'Could not decompress Git object: ' . substr($sha, 0, 7);
                return null;
            }

            $object = $this->parse_loose_object($data, $sha);
            if ($object !== null) {
                $this->object_cache[$sha] = $object;
            }

            return $object;
        }

        $packed = $this->read_packed_object($sha);
        if ($packed !== null) {
            $this->object_cache[$sha] = $packed;
            return $packed;
        }

        $this->last_error = 'Git object is missing: ' . substr($sha, 0, 7);
        return null;
    }

    private function parse_loose_object(string $data, string $sha): ?array {
        $null_pos = strpos($data, "\0");
        if ($null_pos === false) {
            $this->last_error = 'Git object has invalid format: ' . substr($sha, 0, 7);
            return null;
        }

        $header = substr($data, 0, $null_pos);
        $content = substr($data, $null_pos + 1);
        $parts = explode(' ', $header, 2);
        if (count($parts) !== 2) {
            $this->last_error = 'Git object has invalid header: ' . substr($sha, 0, 7);
            return null;
        }

        $this->last_error = null;
        return ['type' => $parts[0], 'size' => (int) $parts[1], 'content' => $content];
    }

    private function read_packed_object(string $sha): ?array {
        foreach ($this->get_pack_indexes() as $index) {
            $offset = $this->find_pack_object_offset($index, $sha);
            if ($offset === null) {
                continue;
            }

            return $this->read_pack_object_at_offset($index['pack_path'], $offset, []);
        }

        return null;
    }

    private function get_pack_indexes(): array {
        if ($this->pack_indexes !== null) {
            return $this->pack_indexes;
        }

        $this->pack_indexes = [];
        $idx_files = glob($this->git_dir . '/objects/pack/*.idx');
        if ($idx_files === false) {
            return $this->pack_indexes;
        }

        foreach ($idx_files as $idx_path) {
            $pack_path = substr($idx_path, 0, -4) . '.pack';
            if (!file_exists($pack_path)) {
                continue;
            }

            $data = file_get_contents($idx_path);
            if ($data === false || strlen($data) < 8 || substr($data, 0, 4) !== "\xfftOc") {
                continue;
            }

            $version = unpack('N', substr($data, 4, 4))[1];
            if ($version !== 2) {
                continue;
            }

            $count = unpack('N', substr($data, 8 + 255 * 4, 4))[1];
            $names_offset = 8 + 256 * 4;
            $crc_offset = $names_offset + $count * 20;
            $offsets_offset = $crc_offset + $count * 4;
            $large_offsets_offset = $offsets_offset + $count * 4;

            $this->pack_indexes[] = [
                'idx_path' => $idx_path,
                'pack_path' => $pack_path,
                'data' => $data,
                'count' => $count,
                'names_offset' => $names_offset,
                'offsets_offset' => $offsets_offset,
                'large_offsets_offset' => $large_offsets_offset,
            ];
        }

        return $this->pack_indexes;
    }

    private function find_pack_object_offset(array $index, string $sha): ?int {
        $data = $index['data'];
        $low = 0;
        $high = $index['count'] - 1;

        while ($low <= $high) {
            $mid = (int) floor(($low + $high) / 2);
            $mid_sha = bin2hex(substr($data, $index['names_offset'] + $mid * 20, 20));
            $compare = strcmp($mid_sha, $sha);

            if ($compare === 0) {
                $offset_32 = unpack('N', substr($data, $index['offsets_offset'] + $mid * 4, 4))[1];
                if (($offset_32 & 0x80000000) === 0) {
                    return $offset_32;
                }

                $large_index = $offset_32 & 0x7fffffff;
                $large = unpack('Nhigh/Nlow', substr($data, $index['large_offsets_offset'] + $large_index * 8, 8));
                return (int) ($large['high'] * 4294967296 + $large['low']);
            }

            if ($compare < 0) {
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return null;
    }

    private function read_pack_object_at_offset(string $pack_path, int $offset, array $seen_offsets): ?array {
        $cache_key = $pack_path . ':' . $offset;
        if (isset($this->pack_offset_cache[$cache_key])) {
            return $this->pack_offset_cache[$cache_key];
        }

        if (isset($seen_offsets[$cache_key])) {
            $this->last_error = 'Git pack delta cycle detected.';
            return null;
        }
        $seen_offsets[$cache_key] = true;

        $pack = $this->get_pack_data($pack_path);
        if ($pack === null || strlen($pack) < 12 || substr($pack, 0, 4) !== 'PACK') {
            $this->last_error = 'Git packfile is unreadable.';
            return null;
        }

        $pos = $offset;
        $header = $this->read_pack_object_header($pack, $pos);
        if ($header === null) {
            return null;
        }

        $type = $header['type'];
        $size = $header['size'];
        $type_names = [
            1 => 'commit',
            2 => 'tree',
            3 => 'blob',
            4 => 'tag',
        ];

        if (isset($type_names[$type])) {
            $content = $this->inflate_pack_data($pack, $pos);
            if ($content === null) {
                return null;
            }

            $object = ['type' => $type_names[$type], 'size' => $size, 'content' => $content];
            $this->pack_offset_cache[$cache_key] = $object;
            $this->last_error = null;
            return $object;
        }

        if ($type === 6) {
            $base_offset = $this->read_pack_offset_delta_base($pack, $pos, $offset);
            if ($base_offset === null) {
                return null;
            }

            $delta = $this->inflate_pack_data($pack, $pos);
            if ($delta === null) {
                return null;
            }

            $base = $this->read_pack_object_at_offset($pack_path, $base_offset, $seen_offsets);
            if ($base === null) {
                return null;
            }

            $content = $this->apply_delta($base['content'], $delta);
            if ($content === null) {
                return null;
            }

            $object = ['type' => $base['type'], 'size' => strlen($content), 'content' => $content];
            $this->pack_offset_cache[$cache_key] = $object;
            $this->last_error = null;
            return $object;
        }

        if ($type === 7) {
            if ($pos + 20 > strlen($pack)) {
                $this->last_error = 'Git pack ref-delta is truncated.';
                return null;
            }

            $base_sha = bin2hex(substr($pack, $pos, 20));
            $pos += 20;
            $delta = $this->inflate_pack_data($pack, $pos);
            if ($delta === null) {
                return null;
            }

            $base = $this->read_object($base_sha);
            if ($base === null) {
                return null;
            }

            $content = $this->apply_delta($base['content'], $delta);
            if ($content === null) {
                return null;
            }

            $object = ['type' => $base['type'], 'size' => strlen($content), 'content' => $content];
            $this->pack_offset_cache[$cache_key] = $object;
            $this->last_error = null;
            return $object;
        }

        $this->last_error = 'Unsupported Git pack object type: ' . $type;
        return null;
    }

    private function get_pack_data(string $pack_path): ?string {
        if (!array_key_exists($pack_path, $this->pack_data_cache)) {
            $data = file_get_contents($pack_path);
            $this->pack_data_cache[$pack_path] = $data === false ? null : $data;
        }

        return $this->pack_data_cache[$pack_path];
    }

    private function read_pack_object_header(string $pack, int &$pos): ?array {
        if ($pos >= strlen($pack)) {
            $this->last_error = 'Git pack object header is out of range.';
            return null;
        }

        $byte = ord($pack[$pos++]);
        $type = ($byte >> 4) & 0x07;
        $size = $byte & 0x0f;
        $shift = 4;

        while (($byte & 0x80) !== 0) {
            if ($pos >= strlen($pack)) {
                $this->last_error = 'Git pack object header is truncated.';
                return null;
            }

            $byte = ord($pack[$pos++]);
            $size |= ($byte & 0x7f) << $shift;
            $shift += 7;
        }

        return ['type' => $type, 'size' => $size];
    }

    private function read_pack_offset_delta_base(string $pack, int &$pos, int $object_offset): ?int {
        if ($pos >= strlen($pack)) {
            $this->last_error = 'Git pack ofs-delta base is truncated.';
            return null;
        }

        $byte = ord($pack[$pos++]);
        $base_distance = $byte & 0x7f;

        while (($byte & 0x80) !== 0) {
            if ($pos >= strlen($pack)) {
                $this->last_error = 'Git pack ofs-delta base is truncated.';
                return null;
            }

            $byte = ord($pack[$pos++]);
            $base_distance = (($base_distance + 1) << 7) | ($byte & 0x7f);
        }

        $base_offset = $object_offset - $base_distance;
        if ($base_offset < 12) {
            $this->last_error = 'Git pack ofs-delta base offset is invalid.';
            return null;
        }

        return $base_offset;
    }

    private function inflate_pack_data(string $pack, int $pos): ?string {
        $content = @zlib_decode(substr($pack, $pos));
        if ($content === false) {
            $this->last_error = 'Could not decompress Git pack object.';
            return null;
        }

        return $content;
    }

    private function apply_delta(string $base, string $delta): ?string {
        $pos = 0;
        $base_size = $this->read_delta_size($delta, $pos);
        $result_size = $this->read_delta_size($delta, $pos);

        if ($base_size === null || $result_size === null) {
            $this->last_error = 'Git delta header is invalid.';
            return null;
        }

        $result = '';
        $delta_len = strlen($delta);

        while ($pos < $delta_len) {
            $opcode = ord($delta[$pos++]);
            if (($opcode & 0x80) !== 0) {
                $copy_offset = 0;
                $copy_size = 0;

                if ($opcode & 0x01) $copy_offset |= ord($delta[$pos++]);
                if ($opcode & 0x02) $copy_offset |= ord($delta[$pos++]) << 8;
                if ($opcode & 0x04) $copy_offset |= ord($delta[$pos++]) << 16;
                if ($opcode & 0x08) $copy_offset |= ord($delta[$pos++]) << 24;
                if ($opcode & 0x10) $copy_size |= ord($delta[$pos++]);
                if ($opcode & 0x20) $copy_size |= ord($delta[$pos++]) << 8;
                if ($opcode & 0x40) $copy_size |= ord($delta[$pos++]) << 16;
                if ($copy_size === 0) {
                    $copy_size = 0x10000;
                }

                $result .= substr($base, $copy_offset, $copy_size);
            } elseif ($opcode !== 0) {
                $result .= substr($delta, $pos, $opcode);
                $pos += $opcode;
            } else {
                $this->last_error = 'Git delta contains an invalid opcode.';
                return null;
            }
        }

        if (strlen($result) !== $result_size) {
            $this->last_error = 'Git delta result size did not match.';
            return null;
        }

        return $result;
    }

    private function read_delta_size(string $delta, int &$pos): ?int {
        $size = 0;
        $shift = 0;
        $len = strlen($delta);

        do {
            if ($pos >= $len) {
                return null;
            }

            $byte = ord($delta[$pos++]);
            $size |= ($byte & 0x7f) << $shift;
            $shift += 7;
        } while (($byte & 0x80) !== 0);

        return $size;
    }

    private function parse_tree(string $content): array {
        $entries = [];
        $pos = 0;
        $len = strlen($content);

        while ($pos < $len) {
            $space_pos = strpos($content, ' ', $pos);
            if ($space_pos === false) {
                break;
            }

            $mode = substr($content, $pos, $space_pos - $pos);
            $pos = $space_pos + 1;

            $null_pos = strpos($content, "\0", $pos);
            if ($null_pos === false) {
                break;
            }

            $name = substr($content, $pos, $null_pos - $pos);
            $pos = $null_pos + 1;

            $sha = bin2hex(substr($content, $pos, 20));
            $pos += 20;

            $entries[] = ['mode' => $mode, 'name' => $name, 'sha' => $sha];
        }

        return $entries;
    }

    private function format_unavailable_diff(string $path, string $side): string {
        return "diff --git a/{$path} b/{$path}\n"
            . "# Unable to render {$side} side of diff because the Git object is not readable.\n";
    }

    private function format_diff(string $path, string $original, string $current, string $type): string {
        if (strpos($original, "\0") !== false || strpos($current, "\0") !== false) {
            return "diff --git a/{$path} b/{$path}\n# Binary files differ\n";
        }

        $lines = ["diff --git a/{$path} b/{$path}"];

        if ($type === 'created') {
            $lines[] = 'new file mode 100644';
            $lines[] = '--- /dev/null';
            $lines[] = "+++ b/{$path}";
            $current_lines = explode("\n", $current);
            $lines[] = '@@ -0,0 +1,' . count($current_lines) . ' @@';
            foreach ($current_lines as $line) {
                $lines[] = '+' . $line;
            }
        } elseif ($type === 'deleted') {
            $lines[] = 'deleted file mode 100644';
            $lines[] = "--- a/{$path}";
            $lines[] = '+++ /dev/null';
            $original_lines = explode("\n", $original);
            $lines[] = '@@ -1,' . count($original_lines) . ' +0,0 @@';
            foreach ($original_lines as $line) {
                $lines[] = '-' . $line;
            }
        } else {
            $lines[] = "--- a/{$path}";
            $lines[] = "+++ b/{$path}";
            $hunks = $this->compute_hunks($original, $current);
            if ($hunks !== '') {
                $lines[] = $hunks;
            }
        }

        $lines[] = '';
        return implode("\n", $lines);
    }

    private function compute_hunks(string $original, string $current): string {
        $old = explode("\n", $original);
        $new = explode("\n", $current);
        $diff = $this->lcs_diff($old, $new);

        $hunks = [];
        $hunk = [];
        $old_line = 0;
        $new_line = 0;
        $hunk_old_start = 0;
        $hunk_new_start = 0;
        $context = [];

        foreach ($diff as [$type, $line]) {
            if ($type === '=') {
                $old_line++;
                $new_line++;
                if (!empty($hunk)) {
                    $hunk[] = ' ' . $line;
                    $trailing = 0;
                    for ($i = count($hunk) - 1; $i >= 0 && $hunk[$i][0] === ' '; $i--) {
                        $trailing++;
                    }
                    if ($trailing >= 3) {
                        $hunks[] = $this->format_hunk($hunk_old_start, $old_line - $hunk_old_start, $hunk_new_start, $new_line - $hunk_new_start, $hunk);
                        $hunk = [];
                    }
                } else {
                    $context[] = ' ' . $line;
                    if (count($context) > 3) {
                        array_shift($context);
                    }
                }
            } else {
                if (empty($hunk)) {
                    $hunk_old_start = $old_line - count($context) + 1;
                    $hunk_new_start = $new_line - count($context) + 1;
                    $hunk = $context;
                    $context = [];
                }
                if ($type === '-') {
                    $old_line++;
                    $hunk[] = '-' . $line;
                } else {
                    $new_line++;
                    $hunk[] = '+' . $line;
                }
            }
        }

        if (!empty($hunk)) {
            $hunks[] = $this->format_hunk($hunk_old_start, $old_line - $hunk_old_start + 1, $hunk_new_start, $new_line - $hunk_new_start + 1, $hunk);
        }

        return implode("\n", $hunks);
    }

    private function format_hunk(int $old_start, int $old_count, int $new_start, int $new_count, array $lines): string {
        return "@@ -{$old_start},{$old_count} +{$new_start},{$new_count} @@\n" . implode("\n", $lines);
    }

    private function lcs_diff(array $old, array $new): array {
        $old_count = count($old);
        $new_count = count($new);
        $matrix = [];

        for ($i = 0; $i <= $old_count; $i++) {
            $matrix[$i][0] = 0;
        }
        for ($j = 0; $j <= $new_count; $j++) {
            $matrix[0][$j] = 0;
        }

        for ($i = 1; $i <= $old_count; $i++) {
            for ($j = 1; $j <= $new_count; $j++) {
                $matrix[$i][$j] = ($old[$i - 1] === $new[$j - 1])
                    ? $matrix[$i - 1][$j - 1] + 1
                    : max($matrix[$i - 1][$j], $matrix[$i][$j - 1]);
            }
        }

        $result = [];
        $i = $old_count;
        $j = $new_count;

        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $old[$i - 1] === $new[$j - 1]) {
                array_unshift($result, ['=', $old[$i - 1]]);
                $i--;
                $j--;
            } elseif ($j > 0 && ($i === 0 || $matrix[$i][$j - 1] >= $matrix[$i - 1][$j])) {
                array_unshift($result, ['+', $new[$j - 1]]);
                $j--;
            } else {
                array_unshift($result, ['-', $old[$i - 1]]);
                $i--;
            }
        }

        return $result;
    }

    private function write_checkout_state(string $sha): void {
        $states = $this->read_checkout_states();
        $states[$this->get_state_key()] = $sha;
        $this->write_checkout_states($states);
    }

    private function clear_checkout_state(): void {
        $states = $this->read_checkout_states();
        $key = $this->get_state_key();
        if (isset($states[$key])) {
            unset($states[$key]);
            $this->write_checkout_states($states);
        }
    }

    private function read_checkout_states(): array {
        if (!function_exists('get_option')) {
            return [];
        }

        $states = get_option(self::CHECKOUTS_OPTION, []);
        return is_array($states) ? $states : [];
    }

    private function write_checkout_states(array $states): void {
        if (function_exists('update_option')) {
            update_option(self::CHECKOUTS_OPTION, $states, false);
        }
    }

    private function get_state_key(): string {
        $real = realpath($this->work_tree);
        return sha1($real !== false ? $real : $this->work_tree);
    }

    private function remove_empty_parent_dirs(string $dir): void {
        $root = realpath($this->work_tree);
        while ($root !== false && $dir !== $root && strpos($dir, $root) === 0 && is_dir($dir)) {
            $items = scandir($dir);
            if ($items === false || count(array_diff($items, ['.', '..'])) > 0) {
                return;
            }

            if (!@rmdir($dir)) {
                return;
            }
            $dir = dirname($dir);
        }
    }

    private function is_valid_commit(?string $sha): bool {
        if (!$this->is_valid_sha($sha)) {
            return false;
        }

        $object = $this->read_object((string) $sha);
        return $object !== null && $object['type'] === 'commit';
    }

    private function is_valid_sha(?string $sha): bool {
        return is_string($sha) && (bool) preg_match('/^[a-f0-9]{40}$/', $sha);
    }

    private function is_safe_ref(string $ref): bool {
        return $ref !== ''
            && strpos($ref, "\0") === false
            && strpos($ref, '..') === false
            && strpos($ref, '\\') === false
            && strpos($ref, '/') !== 0;
    }

    private function is_safe_path(string $path): bool {
        return $path !== ''
            && strpos($path, "\0") === false
            && strpos($path, '..') === false
            && strpos($path, '\\') === false
            && strpos($path, '/') !== 0;
    }
}
