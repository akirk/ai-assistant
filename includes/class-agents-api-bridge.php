<?php
/**
 * Bridge between AI Assistant's Executor and the Agents API substrate.
 *
 * Demonstration patch — proof of concept showing how AI Assistant's existing
 * Executor can plug into Automattic/agents-api without changing the JS-owned
 * loop, the chat UI, or the conversation storage.
 *
 * @package AI_Assistant
 */

namespace AI_Assistant;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AgentsAPI\AI\Tools\WP_Agent_Tool_Executor;
use AgentsAPI\AI\Tools\WP_Agent_Tool_Execution_Core;
use AgentsAPI\AI\Tools\WP_Agent_Tool_Result;

/**
 * Adapts AI Assistant's Executor to the WP_Agent_Tool_Executor contract.
 *
 * After wiring, AJAX tool calls flow through the substrate's Tool Execution
 * Core, which:
 *
 *   - Validates required parameters against the tool's input schema
 *   - Normalizes the prepared tool call into a stable envelope
 *   - Resolves action policy (direct / preview / forbidden) through an
 *     8-layer resolver — AI Assistant's YOLO toggle becomes one layer
 *   - Picks up future substrate primitives (tier resolution, approval gate,
 *     transcript audit) without further code changes here
 *
 * What this bridge does NOT touch:
 *   - The JS loop (browser still owns turn orchestration and streaming)
 *   - The chat UI / panel rendering
 *   - The conversation CPT storage and history
 *   - Git-compatible change tracking, patch export, Playground portability
 *   - The signed direct-file endpoint for mutating filesystem tools
 *
 * Enable by passing an instance into API_Handler in place of the raw Executor,
 * or by routing handle_execute_tool() through it (see commented example below).
 */
class Agents_API_Bridge implements WP_Agent_Tool_Executor {

	/** @var Executor */
	private $executor;

	/** @var Tools */
	private $tools;

	/** @var WP_Agent_Tool_Execution_Core */
	private $core;

	public function __construct( Executor $executor, Tools $tools ) {
		$this->executor = $executor;
		$this->tools    = $tools;
		$this->core     = new WP_Agent_Tool_Execution_Core();
	}

	/**
	 * Execute a tool call from the AJAX handler through the substrate.
	 *
	 * Drop-in replacement for `$executor->execute_tool( $tool, $args, $permission, $conversation_id )`
	 * that adds substrate-level parameter validation and policy resolution.
	 *
	 * @param string   $tool_name        AI Assistant tool identifier.
	 * @param array    $tool_parameters  Caller-supplied parameters.
	 * @param string   $permission       AI Assistant permission tier ('full' | 'read_only').
	 * @param int|null $conversation_id  Conversation CPT id, if any.
	 * @return array Normalized execution result, ready for wp_send_json_success().
	 */
	public function dispatch( string $tool_name, array $tool_parameters, string $permission = 'full', ?int $conversation_id = null ): array {
		$available_tools = $this->tool_declarations_for_substrate();
		$context         = array(
			'ai_assistant_permission' => $permission,
			'conversation_id'         => $conversation_id,
		);

		$prepared = $this->core->prepareWP_Agent_Tool_Call( $tool_name, $tool_parameters, $available_tools, $context );
		if ( empty( $prepared['ready'] ) ) {
			return $prepared;
		}

		return $this->core->executeWP_Agent_Tool_Call( $prepared['tool_call'], $prepared['tool_def'], $this, $context );
	}

	/**
	 * WP_Agent_Tool_Executor contract.
	 *
	 * The substrate calls this with an already-prepared tool call. We delegate
	 * to AI Assistant's existing Executor and shape the result into the
	 * substrate's normalized envelope.
	 */
	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		$tool_name  = (string) ( $tool_call['tool_name'] ?? '' );
		$parameters = is_array( $tool_call['parameters'] ?? null ) ? $tool_call['parameters'] : array();
		$permission = (string) ( $context['ai_assistant_permission'] ?? 'full' );
		$conv_id    = isset( $context['conversation_id'] ) ? (int) $context['conversation_id'] : null;

		try {
			$result = $this->executor->execute_tool( $tool_name, $parameters, $permission, $conv_id );
		} catch ( \Throwable $error ) {
			return WP_Agent_Tool_Result::error(
				$tool_name,
				$error->getMessage() !== '' ? $error->getMessage() : ( 'Unknown error: ' . get_class( $error ) ),
				array( 'error_type' => 'executor_threw' )
			);
		}

		return WP_Agent_Tool_Result::success( $tool_name, is_array( $result ) ? $result : array( 'value' => $result ) );
	}

	/**
	 * Translate AI Assistant's tool registry into substrate tool declarations.
	 *
	 * Schema-light bridge — the substrate validates required parameters and
	 * basic shape. AI Assistant's Executor still owns the deep semantics
	 * (filesystem boundaries, permission checks, etc.).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function tool_declarations_for_substrate(): array {
		$declarations = array();
		foreach ( $this->tools->get_all_tools() as $name => $tool ) {
			$declarations[ $name ] = array(
				'name'        => $name,
				'description' => $tool['description'] ?? '',
				'parameters'  => $tool['parameters'] ?? array( 'type' => 'object', 'properties' => new \stdClass() ),
				'source'      => 'ai_assistant',
			);
		}
		return $declarations;
	}
}

/*
 * Example wiring inside ai-assistant.php (illustrative, not applied):
 *
 *   if ( class_exists( '\\AgentsAPI\\AI\\Tools\\WP_Agent_Tool_Executor' ) ) {
 *       $bridge = new \AI_Assistant\Agents_API_Bridge( $executor, $tools );
 *       $api_handler = new \AI_Assistant\API_Handler( $tools, $bridge );
 *   } else {
 *       $api_handler = new \AI_Assistant\API_Handler( $tools, $executor );
 *   }
 *
 * And the corresponding hand-off inside API_Handler::handle_execute_tool():
 *
 *   $result = $this->executor instanceof Agents_API_Bridge
 *       ? $this->executor->dispatch( $tool_name, $arguments, $permission, $conversation_id )
 *       : $this->executor->execute_tool( $tool_name, $arguments, $permission, $conversation_id );
 *
 * Both paths produce the same shape, so the JS layer continues to consume
 * results identically. The substrate path gains parameter validation, policy
 * resolution, and forward compatibility with future substrate primitives.
 */
