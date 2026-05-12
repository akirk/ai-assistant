<?php
namespace AI_Assistant\Tests;

use AI_Assistant\Skill_Registry;
use PHPUnit\Framework\TestCase;

class SkillRegistryTest extends TestCase {

    public function test_skill_without_required_class_is_available(): void {
        $this->assertTrue(Skill_Registry::is_available([]));
    }

    public function test_skill_with_missing_required_class_is_unavailable(): void {
        $this->assertFalse(Skill_Registry::is_available([
            'requires_class' => '\\AI_Assistant\\Tests\\MissingDependency',
        ]));
    }

    public function test_skill_with_existing_required_class_is_available(): void {
        $this->assertTrue(Skill_Registry::is_available([
            'requires_class' => '\\AI_Assistant\\Skill_Registry',
        ]));
    }

    public function test_parse_frontmatter_reads_dependency_metadata(): void {
        $parsed = Skill_Registry::parse_frontmatter("---\ntitle: Demo\nrequires_class: \\\\Demo\\\\Runtime\n---\nBody");

        $this->assertSame('Demo', $parsed['frontmatter']['title']);
        $this->assertSame('\\\\Demo\\\\Runtime', $parsed['frontmatter']['requires_class']);
        $this->assertSame('Body', $parsed['body']);
    }
}
