<?php

namespace A2uiLivewire\Tests\Conformance;

use A2uiLivewire\Catalog\Basic\BasicCatalog;
use A2uiLivewire\Evaluation\ExpressionParser;
use A2uiLivewire\Exceptions\A2uiException;
use A2uiLivewire\State\Surface;
use A2uiLivewire\State\SurfaceGroup;
use A2uiLivewire\Support\JsonPointer;
use A2uiLivewire\Support\Values;
use A2uiLivewire\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;

/**
 * Runs the official A2UI conformance suites (copied from
 * a2ui-project/a2ui/conformance/core) against this implementation.
 */
class ConformanceTest extends TestCase
{
    /**
     * Cases that cannot be expressed in PHP: PHP arrays cannot tell an empty
     * JSON object from an empty list.
     */
    private const SKIP = [
        'test_evaluate_function_required_object_empty' => 'PHP cannot distinguish {} from []',
    ];

    public static function cases(): iterable
    {
        foreach (glob(__DIR__.'/../Fixtures/conformance/*.yaml') as $file) {
            foreach (Yaml::parse((string) file_get_contents($file)) ?? [] as $case) {
                if (! is_array($case) || ! isset($case['name'], $case['action'])) {
                    continue;
                }

                yield basename($file, '.yaml').'::'.$case['name'] => [$case];
            }
        }
    }

    #[DataProvider('cases')]
    public function test_conformance_case(array $case): void
    {
        if (isset(self::SKIP[$case['name']])) {
            $this->markTestSkipped(self::SKIP[$case['name']]);
        }

        $version = (string) ($case['protocolVersion'] ?? $case['catalog']['protocolVersion'] ?? '1.0');

        if (str_starts_with($version, 'v0.')) {
            // This renderer implements v1.0 only; v0.9-specific semantics are out of scope.
            if (in_array($case['action'], ['evaluate_function', 'validate'], true) && $this->differsInV09($case)) {
                $this->markTestSkipped('v0.9-specific behaviour');
            }
        }

        match ($case['action']) {
            'parse_expression_template' => $this->runExpression($case),
            'evaluate_function' => $this->runFunction($case),
            'data_model' => $this->runDataModel($case),
            'validate' => $this->runValidate($case),
            default => $this->markTestSkipped("Unsupported conformance action {$case['action']}"),
        };
    }

    private function differsInV09(array $case): bool
    {
        // v0.9 validation functions return booleans and NOT has no ValidationResult coercion.
        return str_contains($case['name'], 'v0_9') || str_contains($case['name'], 'v09');
    }

    // -- expressions ---------------------------------------------------------

    private function runExpression(array $case): void
    {
        $parser = new ExpressionParser;

        $case['expectError'] ??= $case['expect_error'] ?? null;

        if (isset($case['expectError'])) {
            try {
                $parser->parse((string) $case['input']);
                $this->fail("Expected an error for {$case['name']}");
            } catch (A2uiException $e) {
                if (isset($case['expectError']['message'])) {
                    $this->assertStringContainsStringIgnoringCase($case['expectError']['message'], $e->getMessage());
                }
                $this->addToAssertionCount(1);
            }

            return;
        }

        $actual = self::joinLiterals($parser->parse((string) $case['input']));
        $expected = self::joinLiterals(self::toV10($case['expect'] ?? []));

        $this->assertEquals($expected, $actual, $case['description'] ?? $case['name']);
    }

    private static function toV10(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value) && isset($value['path']) && count($value) === 1) {
            return ['@path' => $value['path']];
        }

        if (! array_is_list($value) && isset($value['call'])) {
            return ['@call' => $value['call'], 'args' => self::toV10($value['args'] ?? [])];
        }

        return array_map(self::toV10(...), $value);
    }

    private static function joinLiterals(array $parts): array
    {
        $out = [];
        foreach ($parts as $part) {
            $last = count($out) - 1;
            if (is_string($part) && $last >= 0 && is_string($out[$last])) {
                $out[$last] .= $part;
            } else {
                $out[] = $part;
            }
        }

        return $out;
    }

    // -- functions -----------------------------------------------------------

    private function runFunction(array $case): void
    {
        foreach ($case['catalogPaths'] ?? [] as $path) {
            if (! str_contains($path, 'catalogs/basic/v1')) {
                $this->markTestSkipped('Requires the conformance custom catalog');
            }
        }

        $surface = new Surface('main', array_key_exists('surface', $case) ? ($case['surface']['catalogId'] ?? null) : BasicCatalog::ID);
        $group = new SurfaceGroup([$surface->id => $surface]);
        $context = $this->evaluator($group)->context($surface, activation: true);
        $call = ['@call' => $case['function'], 'args' => $case['args'] ?? []];

        if (isset($case['catalogId'])) {
            $call['catalogId'] = $case['catalogId'];
        }

        if (isset($case['expectError'])) {
            try {
                $context->resolve($call);
                $this->fail("Expected an error for {$case['name']}");
            } catch (A2uiException) {
                $this->addToAssertionCount(1);
            }

            return;
        }

        $result = $context->resolve($call);
        $expected = $case['expect'] ?? null;

        if (is_bool($expected) && Values::isValidationResult($result)) {
            $result = $result['valid'];
        }

        $this->assertSame($expected, $result, $case['description'] ?? $case['name']);
    }

    // -- data model ----------------------------------------------------------

    private function runDataModel(array $case): void
    {
        $data = $case['initial'] ?? [];

        foreach ($case['steps'] as $i => $step) {
            $label = "{$case['name']} step {$i}";

            switch ($step['op']) {
                case 'get':
                    if (isset($step['expect_error'])) {
                        try {
                            JsonPointer::find($data, $step['path']);
                            $this->fail("{$label}: expected an error");
                        } catch (A2uiException) {
                            $this->addToAssertionCount(1);
                        }
                        break;
                    }
                    $value = JsonPointer::find($data, $step['path']);

                    if (! empty($step['expect_absent'])) {
                        $this->assertTrue($value === JsonPointer::MISSING || $value === null, $label);
                    } elseif (isset($step['expect_type'])) {
                        $this->assertIsArray($value, $label);
                        if ($value !== []) {
                            $this->assertSame($step['expect_type'] === 'list', array_is_list($value), $label);
                        }
                    } elseif (array_key_exists('expect', $step)) {
                        $this->assertEquals($step['expect'], $value === JsonPointer::MISSING ? null : $value, $label);
                    }
                    break;

                case 'set':
                    if (isset($step['expect_error'])) {
                        try {
                            JsonPointer::set($data, $step['path'], $step['value'] ?? null);
                            $this->fail("{$label}: expected an error");
                        } catch (A2uiException) {
                            $this->addToAssertionCount(1);
                        }
                    } else {
                        $data = array_key_exists('value', $step) && $step['value'] === null
                            ? JsonPointer::remove($data, $step['path'])
                            : JsonPointer::set($data, $step['path'], $step['value'] ?? null);
                    }
                    break;

                case 'delete':
                    $data = JsonPointer::remove($data, $step['path']);
                    break;
            }

            foreach ($step['expect_values'] ?? [] as $path => $expected) {
                $actual = JsonPointer::find($data, (string) $path);
                $this->assertEquals($expected, $actual === JsonPointer::MISSING ? null : $actual, "{$label} value at {$path}");
            }
        }

        $this->addToAssertionCount(1);
    }

    // -- validate (process messages, inspect resolved state) -----------------

    private function runValidate(array $case): void
    {
        $group = new SurfaceGroup;
        $processor = $this->a2ui()->processor();
        $errors = [];

        foreach ($case['steps'] as $step) {
            $result = $processor->process($group, $step['payload'] ?? []);
            $errors = [...$errors, ...$result->errors];

            if (isset($step['expectError'])) {
                $resolutionErrors = $this->resolveAll($group, $case);
                $this->assertNotEmpty([...$result->errors, ...$resolutionErrors], "{$case['name']}: expected an error");

                return;
            }

            if (isset($step['expect'])) {
                $this->assertExpectations($group, $step['expect'], $case['name']);
            }
        }

        if (isset($case['expectError'])) {
            $this->assertNotEmpty([...$errors, ...$this->resolveAll($group, $case)], "{$case['name']}: expected an error");

            return;
        }

        $this->assertExpectations($group, $case['expect'] ?? [], $case['name']);
        $this->addToAssertionCount(1);
    }

    private function assertExpectations(SurfaceGroup $group, array $expect, string $name): void
    {
        $case = ['name' => $name];

        foreach ($expect['surfaces'] ?? [] as $surfaceId => $expectations) {
            $surface = $group->get((string) $surfaceId);
            $this->assertNotNull($surface, "Surface {$surfaceId} exists");

            if (array_key_exists('dataModel', $expectations)) {
                $this->assertEquals($expectations['dataModel'], $surface->dataModel, "{$case['name']}: data model");
            }

            foreach ($expectations['components'] ?? [] as $componentId => $props) {
                $component = $surface->component((string) $componentId);

                if ($component === null) {
                    // Template instances (e.g. text_0) are named per implementation.
                    continue;
                }

                $context = $this->evaluator($group)->context($surface);

                foreach ($props as $prop => $expected) {
                    if ($prop === 'component') {
                        $this->assertSame($expected, $component['component']);

                        continue;
                    }

                    if (! array_key_exists($prop, $component)) {
                        continue;
                    }

                    $actual = $context->resolve($component[$prop]);
                    $this->assertEquals($expected, $actual, "{$case['name']}: {$componentId}.{$prop}");
                }
            }

            foreach ($expectations['isValid'] ?? [] as $componentId => $valid) {
                $results = $this->checkResults($group, $surface, (string) $componentId);
                $isValid = array_reduce($results, fn ($carry, $r) => $carry && ($r['valid'] || ($r['severity'] ?? 'error') !== 'error'), true);
                $this->assertSame($valid, $isValid, "{$case['name']}: {$componentId} isValid");
            }

            foreach ($expectations['validationErrors'] ?? [] as $componentId => $messages) {
                $results = $this->checkResults($group, $surface, (string) $componentId);
                $actual = array_values(array_map(fn ($r) => $r['message'] ?? '', array_filter($results, fn ($r) => ! $r['valid'] && ($r['severity'] ?? 'error') === 'error')));
                $this->assertSame($messages, $actual, "{$case['name']}: {$componentId} validationErrors");
            }
        }
    }

    private function checkResults(SurfaceGroup $group, Surface $surface, string $componentId): array
    {
        $component = $surface->component($componentId) ?? [];
        $context = $this->evaluator($group)->context($surface);
        $results = [];

        foreach ($component['@checks'] ?? [] as $rule) {
            try {
                $results[] = Values::toValidationResult($context->resolve($rule['condition']), $rule['message'] ?? null, ! isset($rule['condition']['@path']));
            } catch (A2uiException $e) {
                $results[] = ['valid' => false, 'message' => $rule['message'] ?? $e->getMessage(), 'severity' => 'error'];
            }
        }

        return $results;
    }

    /**
     * Resolves every dynamic property of every component, collecting errors.
     *
     * @return list<A2uiException>
     */
    private function resolveAll(SurfaceGroup $group, array $case): array
    {
        $errors = [];

        foreach ($group->surfaces as $surface) {
            $context = $this->evaluator($group)->context($surface);
            foreach ($surface->components as $component) {
                foreach ($component as $key => $value) {
                    if (in_array($key, ['id', 'component'], true)) {
                        continue;
                    }
                    try {
                        $context->resolve($value);
                    } catch (A2uiException $e) {
                        $errors[] = $e;
                    }
                }
            }
        }

        return $errors;
    }
}
