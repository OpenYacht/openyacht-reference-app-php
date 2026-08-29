<?php

namespace App\Support\ApiDocs;

use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use ReflectionMethod;

/**
 * Generates the agent-facing Markdown guide for the data API from the
 * manifest in config/api-docs.php, so the guide can never drift from the
 * code: endpoint summaries and descriptions are read from the controller
 * docblocks via reflection, and query-parameter tables come from the
 * same #[QueryParameter] attributes Scramble uses for the OpenAPI spec.
 *
 * Response field tables are declared inline in the manifest and cover
 * only what is local to this surface — the envelope and the x_ extension
 * fields. The listing document itself is deliberately not re-documented:
 * the protocol spec (listing-schema.md) is normative for that shape, and
 * duplicating it here is how docs drift. (The production system this is
 * modelled on parses field tables from Eloquent API Resources; this app
 * serves spec-shaped documents, so there are no Resources to parse.)
 */
class ApiDocGenerator
{
    public function generate(string $guideKey): string
    {
        $guide = config("api-docs.guides.{$guideKey}");

        if ($guide === null) {
            throw new InvalidArgumentException("Unknown API doc guide [{$guideKey}].");
        }

        $sections = [
            $this->intro($guide['intro'] ?? null),
            '## Endpoints',
        ];

        foreach ($guide['endpoints'] as $endpoint) {
            $sections[] = $this->renderEndpoint($endpoint);
        }

        return $this->join($sections)."\n";
    }

    private function intro(?string $introFile): string
    {
        if ($introFile === null) {
            return '';
        }

        $path = base_path("docs/api/{$introFile}");

        return File::exists($path) ? rtrim(File::get($path)) : '';
    }

    /**
     * @param  array{method: string, uri: string, action: array{0: class-string, 1: string}, scope?: string, responses?: array<string, array{fields: array<int, array<string, mixed>>}>}  $endpoint
     */
    private function renderEndpoint(array $endpoint): string
    {
        [$class, $method] = $endpoint['action'];
        $reflection = new ReflectionMethod($class, $method);

        [$summary, $description] = $this->summaryAndDescription($reflection);

        $parts = ["### `{$endpoint['method']} {$endpoint['uri']}`"];

        if ($summary !== '') {
            $parts[] = $summary;
        }

        if (isset($endpoint['scope'])) {
            $parts[] = "**Required scope:** `{$endpoint['scope']}`";
        }

        if ($description !== '') {
            $parts[] = $description;
        }

        $parameters = $this->renderParameters($reflection);

        if ($parameters !== '') {
            $parts[] = "**Query parameters**\n\n{$parameters}";
        }

        foreach ($endpoint['responses'] ?? [] as $label => $response) {
            $parts[] = "**Response — {$label}**\n\n".$this->renderFieldTables($response['fields']);
        }

        return $this->join($parts);
    }

    /**
     * The leading docblock prose, split into a one-line summary and the
     * remaining description (PHPDoc tags are ignored).
     *
     * @return array{0: string, 1: string}
     */
    private function summaryAndDescription(ReflectionMethod $method): array
    {
        $doc = $method->getDocComment();

        if ($doc === false) {
            return ['', ''];
        }

        $lines = [];

        foreach (preg_split('/\R/', $doc) ?: [] as $line) {
            $line = preg_replace('/^\s*\/?\*+\/?/', '', $line);
            $line = preg_replace('/\*\/\s*$/', '', $line ?? '');
            $lines[] = trim($line ?? '');
        }

        $prose = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, '@') || str_starts_with($line, '//')) {
                break;
            }

            $prose[] = $line;
        }

        $paragraphs = preg_split('/\n\s*\n/', trim(implode("\n", $prose)), 2) ?: [];

        return [
            trim($paragraphs[0] ?? ''),
            trim($paragraphs[1] ?? ''),
        ];
    }

    private function renderParameters(ReflectionMethod $method): string
    {
        $rows = [];

        foreach ($method->getAttributes(QueryParameter::class) as $attribute) {
            $parameter = $attribute->newInstance();

            $rows[] = [
                "`{$parameter->name}`",
                $this->normalizeType($parameter->type ?? 'string'),
                $this->scalarOrBlank($parameter->default),
                $this->cell($parameter->description ?? ''),
            ];
        }

        if ($rows === []) {
            return '';
        }

        return $this->table(['Parameter', 'Type', 'Default', 'Description'], $rows);
    }

    /**
     * Render a field table, recursing into nested object/array-of-object
     * fields as their own sub-tables beneath the parent. Rows are plain
     * arrays (name, type, nullable, description, children) rather than a
     * typed shape because the tree is recursive.
     *
     * @param  array<int, array<string, mixed>>  $fields
     */
    private function renderFieldTables(array $fields): string
    {
        $rows = [];
        $nested = [];

        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            $type = (string) ($field['type'] ?? 'string');

            $rows[] = [
                "`{$name}`",
                $type,
                ($field['nullable'] ?? false) ? 'yes' : 'no',
                $this->cell((string) ($field['description'] ?? '')),
            ];

            $children = $field['children'] ?? [];

            if (is_array($children) && $children !== []) {
                $title = $type === 'array of objects'
                    ? "*Each item in `{$name}`:*"
                    : "*Object `{$name}`:*";

                $nested[] = $title."\n\n".$this->renderFieldTables($children);
            }
        }

        return $this->join(array_merge(
            [$this->table(['Field', 'Type', 'Nullable', 'Description'], $rows)],
            $nested,
        ));
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function table(array $headers, array $rows): string
    {
        $lines = [
            '| '.implode(' | ', $headers).' |',
            '| '.implode(' | ', array_fill(0, count($headers), '---')).' |',
        ];

        foreach ($rows as $row) {
            $lines[] = '| '.implode(' | ', $row).' |';
        }

        return implode("\n", $lines);
    }

    private function normalizeType(string $type): string
    {
        return match (strtolower($type)) {
            'int', 'integer' => 'integer',
            'float', 'double', 'number' => 'number',
            'bool', 'boolean' => 'boolean',
            default => $type,
        };
    }

    private function scalarOrBlank(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '`true`' : '`false`';
        }

        if (is_scalar($value)) {
            return "`{$value}`";
        }

        return '—';
    }

    private function cell(string $value): string
    {
        return str_replace(['|', "\n"], ['\|', ' '], trim($value));
    }

    /**
     * @param  array<int, string>  $parts
     */
    private function join(array $parts): string
    {
        return implode("\n\n", array_filter(array_map('trim', $parts), fn (string $part): bool => $part !== ''));
    }
}
