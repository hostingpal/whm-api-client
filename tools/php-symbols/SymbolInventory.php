<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Trusted repository declarations only. Reflection never invokes methods or attributes.
 *
 * @phpstan-type Symbol array{id: string, kind: string, declaring_class: string, file: string,
 *     line: int, public: bool, static: bool, abstract: bool, providers: list<string>}
 * @phpstan-type Diagnostic array{severity: string, code: string, message: string, class: string, file: string}
 * @phpstan-type Token array{int, string, int}|string
 * @phpstan-type Declaration array{class: string, file: string, namespace: string, imports: array<string, string>}
 */
final class SymbolInventory
{
    /** @return array{schema_version: string, symbols: list<Symbol>, diagnostics: list<Diagnostic>} */
    public function scan(string $root): array
    {
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved)) {
            throw new InvalidArgumentException('Symbol inventory root must be an existing directory.');
        }
        $root = $resolved;
        if (is_file($root . '/vendor/autoload.php')) {
            require_once $root . '/vendor/autoload.php';
        }
        $symbols = [];
        $diagnostics = [];
        foreach ($this->files($root) as $file) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($root) + 1));
            try {
                $source = file_get_contents($file);
                if ($source === false) {
                    throw new RuntimeException('Cannot read declaration file.');
                }
                $tokens = token_get_all($source, TOKEN_PARSE);
            } catch (Throwable) {
                $diagnostics[] = $this->diagnostic('parse_failed', '', $relative);
                continue;
            }
            $metadata = $this->methodAttributes($tokens);
            foreach ($this->declarations($tokens, $relative) as $declaration) {
                $name = $declaration['class'];
                try {
                    if (!class_exists($name) && !interface_exists($name) && !trait_exists($name)) {
                        throw new RuntimeException('Named declaration cannot be autoloaded.');
                    }
                    $class = new ReflectionClass($name);
                } catch (Throwable) {
                    $diagnostics[] = $this->diagnostic('autoload_failed', $name, $relative);
                    continue;
                }
                if ($class->isAnonymous() || $class->getFileName() !== $file) {
                    $diagnostics[] = $this->diagnostic('declaration_file_mismatch', $name, $relative);
                    continue;
                }
                $isTest = str_starts_with($relative, 'tests/') && $class->isSubclassOf(TestCase::class);
                foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    if ($method->getDeclaringClass()->getName() !== $name || $method->getFileName() !== $file) {
                        continue;
                    }
                    $line = $method->getStartLine();
                    if ($line === false) {
                        continue;
                    }
                    $kind = $method->isConstructor() ? 'constructor' : 'method';
                    if (
                        $isTest && (str_starts_with($method->getName(), 'test')
                        || $method->getAttributes('PHPUnit\\Framework\\Attributes\\Test') !== [])
                    ) {
                        $kind = 'test';
                    }
                    $providers = [];
                    $attributes = $method->getAttributes();
                    foreach ($attributes as $index => $attribute) {
                        $attributeName = $attribute->getName();
                        if (
                            !in_array($attributeName, [
                            'PHPUnit\\Framework\\Attributes\\DataProvider',
                            'PHPUnit\\Framework\\Attributes\\DataProviderExternal',
                            ], true)
                        ) {
                            continue;
                        }
                        $provider = $this->provider($metadata[$line][$index] ?? [], $attributeName, $declaration);
                        if ($provider === null) {
                            $diagnostics[] = $this->diagnostic('provider_metadata_unresolved', $name, $relative);
                        } else {
                            $providers[] = $provider;
                        }
                    }
                    sort($providers, SORT_STRING);
                    $symbols[] = [
                        'id' => $name . '::' . $method->getName(),
                        'kind' => $kind,
                        'declaring_class' => $name,
                        'file' => $relative,
                        'line' => $line,
                        'public' => $method->isPublic(),
                        'static' => $method->isStatic(),
                        'abstract' => $method->isAbstract(),
                        'providers' => array_values(array_unique($providers)),
                    ];
                }
            }
        }
        $providerIDs = [];
        foreach ($symbols as $symbol) {
            if ($symbol['kind'] === 'test') {
                foreach ($symbol['providers'] as $id) {
                    $providerIDs[$id] = true;
                }
            }
        }
        foreach ($symbols as &$symbol) {
            if (isset($providerIDs[$symbol['id']]) && $symbol['kind'] === 'method') {
                $symbol['kind'] = 'provider';
            }
        }
        unset($symbol);
        usort($symbols, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
        usort($diagnostics, static fn (array $a, array $b): int =>
            [$a['file'], $a['class'], $a['code']] <=> [$b['file'], $b['class'], $b['code']]);
        return ['schema_version' => '1.0', 'symbols' => $symbols, 'diagnostics' => $diagnostics];
    }

    /** @return list<string> */
    private function files(string $root): array
    {
        $files = [];
        foreach (['src', 'tests'] as $directory) {
            if (!is_dir($root . '/' . $directory)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $root . '/' . $directory,
                RecursiveDirectoryIterator::SKIP_DOTS,
            ));
            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $path = $file->getRealPath();
                if ($path !== false && str_starts_with($path, $root . DIRECTORY_SEPARATOR . $directory . '/')) {
                    $files[] = $path;
                }
            }
        }
        sort($files, SORT_STRING);
        return array_values(array_unique($files));
    }

    /** @param list<Token> $tokens
     * @return list<Declaration>
     */
    private function declarations(array $tokens, string $file): array
    {
        $namespace = '';
        $imports = [];
        $depth = 0;
        $namespaceDepth = 0;
        $bracketed = false;
        $result = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (
                $token === '{' || (is_array($token)
                && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))
            ) {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
                if ($bracketed && $depth < $namespaceDepth) {
                    $namespace = '';
                    $imports = [];
                    $namespaceDepth = 0;
                    $bracketed = false;
                }
            } elseif (is_array($token) && $token[0] === T_NAMESPACE) {
                $namespace = '';
                $imports = [];
                while (++$i < $count && $tokens[$i] !== ';' && $tokens[$i] !== '{') {
                    $part = $tokens[$i];
                    if (is_array($part) && in_array($part[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                        $namespace .= $part[1];
                    }
                }
                $bracketed = ($tokens[$i] ?? null) === '{';
                if ($bracketed) {
                    $depth++;
                }
                $namespaceDepth = $depth;
            } elseif (is_array($token) && $token[0] === T_USE && $depth === $namespaceDepth) {
                $text = '';
                while (++$i < $count && $tokens[$i] !== ';') {
                    $part = $tokens[$i];
                    if (is_array($part) && in_array($part[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        $text .= ' ';
                        continue;
                    }
                    $text .= is_array($part) ? $part[1] : $part;
                }
                $imports = array_replace($imports, $this->imports($text));
            } elseif (is_array($token) && in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $next = $i + 1;
                while (
                    $next < $count && is_array($tokens[$next])
                    && in_array($tokens[$next][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
                ) {
                    $next++;
                }
                $name = $tokens[$next] ?? null;
                if (is_array($name) && $name[0] === T_STRING) {
                    $result[] = ['class' => ($namespace === '' ? '' : $namespace . '\\') . $name[1],
                        'file' => $file, 'namespace' => $namespace, 'imports' => $imports];
                }
            }
        }
        return $result;
    }

    /** @return array<string, string> */
    private function imports(string $text): array
    {
        // Function and constant imports occupy separate PHP name tables.
        if (preg_match('/^\s*(?:function|const)\s+/i', $text) === 1) {
            return [];
        }
        $prefix = '';
        if (preg_match('/^\s*([\\\\\w]+)\s*\{(.*)\}\s*$/s', $text, $group) === 1) {
            $prefix = trim($group[1], '\\') . '\\';
            $text = $group[2];
        }
        $imports = [];
        foreach (explode(',', $text) as $import) {
            if (preg_match('/^\s*([\\\\\w]+)(?:\s+as\s+(\w+))?\s*$/i', $import, $match) !== 1) {
                continue;
            }
            $full = $prefix . ltrim($match[1], '\\');
            $short = $match[2] ?? substr($full, (int) strrpos('\\' . $full, '\\'));
            $imports[strtolower($short)] = $full;
        }
        return $imports;
    }

    /** @param list<Token> $tokens
     * @return array<int, list<list<Token>>>
     */
    private function methodAttributes(array $tokens): array
    {
        $pending = [];
        $result = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && $token[0] === T_ATTRIBUTE) {
                $depth = 0;
                $attribute = [];
                while (++$i < $count) {
                    $part = $tokens[$i];
                    if ($part === ']' && $depth === 0) {
                        $pending[] = $attribute;
                        break;
                    }
                    if ($part === ',' && $depth === 0) {
                        $pending[] = $attribute;
                        $attribute = [];
                        continue;
                    }
                    if ($part === '(' || $part === '[') {
                        $depth++;
                    } elseif ($part === ')' || $part === ']') {
                        $depth--;
                    }
                    $attribute[] = $part;
                }
            } elseif (is_array($token) && $token[0] === T_FUNCTION) {
                $result[$token[2]] = $pending;
                $pending = [];
            } elseif ($token === ';' || $token === '{' || $token === '}') {
                $pending = [];
            }
        }
        return $result;
    }

    /** @param list<Token> $tokens
     * @param Declaration $declaration
     */
    private function provider(array $tokens, string $attribute, array $declaration): ?string
    {
        $arguments = [];
        $started = false;
        foreach ($tokens as $token) {
            if ($token === '(') {
                $started = true;
                continue;
            }
            if (!$started || $token === ')' || (is_array($token) && $token[0] === T_WHITESPACE)) {
                continue;
            }
            $arguments[] = $token;
        }
        if ($attribute === 'PHPUnit\\Framework\\Attributes\\DataProvider') {
            $method = $this->literalString($arguments[0] ?? null);
            return count($arguments) === 1 && $method !== null ? $declaration['class'] . '::' . $method : null;
        }
        if (count($arguments) !== 5 || $arguments[3] !== ',') {
            return null;
        }
        $class = $arguments[0];
        $separator = $arguments[1];
        $keyword = $arguments[2];
        $method = $this->literalString($arguments[4]);
        if (
            !is_array($class) || !is_array($separator) || !is_array($keyword)
            || $separator[0] !== T_DOUBLE_COLON || strtolower($keyword[1]) !== 'class' || $method === null
        ) {
            return null;
        }
        $name = $class[1];
        if ($name === 'self' || $name === 'static') {
            $name = $declaration['class'];
        } elseif ($class[0] === T_NAME_RELATIVE) {
            $name = ($declaration['namespace'] === '' ? '' : $declaration['namespace'] . '\\')
                . substr($name, strlen('namespace\\'));
        } elseif (str_starts_with($name, '\\')) {
            $name = ltrim($name, '\\');
        } else {
            $parts = explode('\\', $name, 2);
            $import = $declaration['imports'][strtolower($parts[0])] ?? null;
            $name = $import === null
                ? ($declaration['namespace'] === '' ? '' : $declaration['namespace'] . '\\') . $name
                : $import . (isset($parts[1]) ? '\\' . $parts[1] : '');
        }
        return $name . '::' . $method;
    }

    /** @param Token|null $token */
    private function literalString(array|string|null $token): ?string
    {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        $text = $token[1];
        $value = substr($text, 1, -1);
        // Provider method names need no interpolation or escape evaluation.
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) === 1 ? $value : null;
    }

    /** @return Diagnostic */
    private function diagnostic(string $code, string $class, string $file): array
    {
        return ['severity' => 'error', 'code' => $code, 'message' => 'Declaration could not be inventoried: ' . $code,
            'class' => $class, 'file' => $file];
    }
}
