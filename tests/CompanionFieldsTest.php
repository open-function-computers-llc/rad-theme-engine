<?php

namespace ofc\tests;

use ofc\CompanionAssets;
use ofc\CompanionFields;
use ofc\FieldTemplates;
use ReflectionClass;
use ReflectionMethod;

class CompanionFieldsTest extends RadTestCase
{
    /**
     * @var string[] theme files created by a test; removed on teardown
     */
    private $themeFiles = [];

    /**
     * @var string[] theme directories created by a test; removed when empty
     */
    private $themeDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetStaticState();
    }

    protected function tearDown(): void
    {
        foreach ($this->themeFiles as $file) {
            @unlink($file);
        }
        $this->themeFiles = [];
        foreach (array_reverse($this->themeDirs) as $dir) {
            @rmdir($dir);
        }
        $this->themeDirs = [];
        $this->resetStaticState();
        parent::tearDown();
    }

    private function resetStaticState(): void
    {
        $reflection = new ReflectionClass(CompanionFields::class);

        foreach (['importErrors' => [], 'adminStylesPrinted' => false] as $property => $value) {
            $prop = $reflection->getProperty($property);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    /**
     * Write a file inside the active theme (cleaned up on teardown).
     */
    private function themeFile(string $relative, string $contents): string
    {
        $path = get_template_directory() . '/' . $relative;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            // Record the dirs that do not exist yet (shallowest first) so
            // teardown can remove them deepest-first.
            $missing = [];
            $probe = $dir;
            while (!is_dir($probe) && $probe !== get_template_directory()) {
                array_unshift($missing, $probe);
                $probe = dirname($probe);
            }
            $this->themeDirs = array_merge($this->themeDirs, $missing);
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, $contents);
        $this->themeFiles[] = $path;

        return $path;
    }

    /** @test */
    public function fieldLevelImportsAreRejected()
    {
        $field = CompanionFields::normalizeField('content', [
            'type' => 'flexible',
            'imports' => 'slices/base.yml',
        ]);

        $this->assertNotEmpty($field['import_errors']);
        $this->assertStringContainsString('only allowed at the top of the yml file', $field['import_errors'][0]);
    }

    /** @test */
    public function topLevelImportsFeedEveryFlexibleFieldAndSameNamesOverride()
    {
        $this->themeFile('rad-test-slices.yml', "layouts:\n  hero:\n    label: Hero\n    fields:\n      heading: text\n  cta:\n    label: CTA\n");
        $group = $this->themeFile('rad-test-group.yml', "imports: rad-test-slices.yml\nfields:\n  content: flexible\n  extra:\n    type: flexible\n    layouts:\n      cta:\n        label: Local CTA\n");

        $method = new ReflectionMethod(CompanionFields::class, 'groupFromYml');
        $method->setAccessible(true);
        $group = $method->invoke(null, $group, 'rad-test-group', 'Test', null);

        $this->assertNotNull($group);
        [$content, $extra] = $group['fields'];

        // A bare `content: flexible` needs no layouts/description below it.
        $this->assertSame(['hero', 'cta'], array_keys($content['layouts']));
        $this->assertSame('Hero', $content['layouts']['hero']['label']);

        // The local layout replaces the imported one in place.
        $this->assertSame(['hero', 'cta'], array_keys($extra['layouts']));
        $this->assertSame('Local CTA', $extra['layouts']['cta']['label']);
    }

    /** @test */
    public function brokenTopLevelImportsSurfaceInlineOnTheField()
    {
        $group = $this->themeFile('rad-test-group.yml', "imports: rad-test-nope.yml\nfields:\n  content: flexible\n");

        $method = new ReflectionMethod(CompanionFields::class, 'groupFromYml');
        $method->setAccessible(true);
        $group = $method->invoke(null, $group, 'rad-test-group', 'Test', null);

        $this->assertStringContainsString('rad-test-nope.yml', $group['fields'][0]['import_errors'][0]);
    }

    /** @test */
    public function bareThumbnailDefaultsToTheAssetsFolder()
    {
        $this->themeFile('assets/rad-thumb-test.png', 'x');

        $field = CompanionFields::normalizeField('content', ['type' => 'flexible'], [
            ['name' => 'hero', 'label' => 'Hero', 'thumbnail' => 'rad-thumb-test'],
        ]);

        $this->assertSame(
            get_template_directory_uri() . '/assets/rad-thumb-test.png',
            $field['layouts']['hero']['thumbnail']
        );
    }

    /** @test */
    public function thumbnailsWithASlashResolveRelativeToTheThemeRoot()
    {
        $this->themeFile('assets/thumbnails/rad-thumb-test2.png', 'x');

        $field = CompanionFields::normalizeField('content', ['type' => 'flexible'], [
            ['name' => 'hero', 'label' => 'Hero', 'thumbnail' => 'assets/thumbnails/rad-thumb-test2'],
            ['name' => 'cta', 'label' => 'CTA', 'thumbnail' => 'rad-thumb-test2'],
        ]);

        // Explicit folder path resolves as written …
        $this->assertSame(
            get_template_directory_uri() . '/assets/thumbnails/rad-thumb-test2.png',
            $field['layouts']['hero']['thumbnail']
        );
        // … while the bare name only looks inside assets/ (no thumbnails/).
        $this->assertArrayNotHasKey('thumbnail', $field['layouts']['cta']);
    }

    /** @test */
    public function repeaterAddButtonTextIsExplicitWithAnAddRowFallback()
    {
        $field = CompanionFields::normalizeField('features', [
            'type' => 'repeater',
            'label' => 'Features',
            'add button text' => 'Add Feature',
            'fields' => ['title' => 'text'],
        ]);
        $this->assertSame('Add Feature', $field['add button text']);

        $html = CompanionFields::renderField($field, serialize([['title' => 'One']]));
        $this->assertStringContainsString('+ Add Feature</a>', $html);
        $this->assertStringContainsString('data-item-label="Features"', $html);
        $this->assertStringNotContainsString('+ Add Features</a>', $html);
        $this->assertStringNotContainsString('+ Add Row</a>', $html);

        // No explicit key → the plain fallback (no singularisation magic).
        $plain = CompanionFields::normalizeField('team', [
            'type' => 'repeater',
            'label' => 'Team Members',
            'fields' => ['title' => 'text'],
        ]);
        $html = CompanionFields::renderField($plain, serialize([['title' => 'Ada']]));
        $this->assertStringContainsString('+ Add Row</a>', $html);
    }

    /** @test */
    public function renderedFieldsEscapeLabelsValuesAndHints()
    {
        $field = CompanionFields::normalizeField('headline', [
            'type' => 'text',
            'label' => 'My <Head>',
            'hint' => 'Say "hi"',
        ]);

        $html = CompanionFields::renderField($field, '<script>alert(1)</script>');

        $this->assertStringContainsString('My &lt;Head&gt;', $html);
        $this->assertStringContainsString('Say &quot;hi&quot;', $html);
        $this->assertStringContainsString('value="&lt;script&gt;alert(1)&lt;/script&gt;"', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
    }

    /** @test */
    public function adminStylesArePrintedOncePerRequest()
    {
        $field = CompanionFields::normalizeField('a', 'text');

        $first = CompanionFields::renderField($field, '1');
        $second = CompanionFields::renderField($field, '2');

        $this->assertStringContainsString('<style>', $first);
        $this->assertStringNotContainsString('<style>', $second);
    }

    /** @test */
    public function flexibleFieldsRenderBlocksAndTheSlicePicker()
    {
        $this->themeFile('assets/rad-thumb-test.png', 'x');

        $field = CompanionFields::normalizeField('content', 'flexible', [
            ['name' => 'hero', 'label' => 'Hero', 'description' => 'Top banner', 'thumbnail' => 'rad-thumb-test', 'fields' => ['heading' => ['type' => 'text']]],
        ]);

        $html = CompanionFields::renderField($field, serialize([['_layout' => 'hero', 'heading' => 'Hello']]));

        $this->assertStringContainsString('id="rad-flexible-content"', $html);
        $this->assertStringContainsString('data-layout="hero"', $html);
        $this->assertStringContainsString('name="rad_block-0[_layout]" value="hero"', $html);
        $this->assertStringContainsString('rad-flexible-template-content-hero', $html);
        $this->assertStringContainsString('rad-flexible-card-desc">Top banner</span>', $html);
        $this->assertStringContainsString('src="' . get_template_directory_uri() . '/assets/rad-thumb-test.png"', $html);
        // The flexible + rekey scripts ride along with the field.
        $this->assertStringContainsString('__radFlexibleBound', $html);
    }

    /** @test */
    public function collapseStatePersistenceShipsWithTheFlexibleScript()
    {
        $script = CompanionAssets::file('flexible.js');

        $this->assertStringContainsString('persistState', $script);
        $this->assertStringContainsString('applyState', $script);
        $this->assertStringContainsString('localStorage', $script);
        $this->assertStringContainsString('rad-flex-collapse:', $script);
    }

    /** @test */
    public function assetsAreServedFromRealFiles()
    {
        $this->assertStringContainsString('.rad-flexible-block', CompanionAssets::file('companion-fields.css'));
        $this->assertStringContainsString('.rad-flexible-modal', CompanionAssets::file('flexible-picker.css'));
        $this->assertStringStartsWith('<script>', CompanionAssets::script('rekey.js'));
        $this->assertStringEndsWith('</script>', CompanionAssets::script('rekey.js'));
        $this->assertSame('', CompanionAssets::file('definitely-missing.js'));
    }

    /** @test */
    public function templatesEscapeDataByDefault()
    {
        $html = FieldTemplates::render('input', [
            'type' => 'text',
            'id' => 'rad_x',
            'name' => 'rad_x',
            'required' => true,
            'value' => '"q"<tag>',
            'hasPlaceholder' => false,
            'placeholder' => '',
        ]);

        $this->assertStringContainsString('<input type="text" id="rad_x" name="rad_x" required value="&quot;q&quot;&lt;tag&gt;" />', $html);
    }

    /** @test */
    public function unimplementedTypesRenderAPlaceholder()
    {
        $field = CompanionFields::normalizeField('attachment', 'file');
        $html = CompanionFields::renderField($field);

        $this->assertStringContainsString('file fields are not implemented yet.', $html);
    }
}
