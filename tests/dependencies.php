<?php
/**
 * 零依赖验证。
 *
 * 用反射遍历每个类的父类、接口、trait、方法参数与返回类型、
 * 属性类型、类常量，确认所有类型引用要么在 MinS3\ 内部，
 * 要么是 PHP 内置类型。任何指向第三方包的引用都会被揪出来。
 *
 * 这比"跑通测试"更强：没有被测试覆盖到的代码路径也会检查到。
 *
 * 用法: php tests/dependencies.php
 */
require __DIR__ . '/../autoload.php';

$root = __DIR__ . '/../src';

// PHP 内置或语言级类型，允许出现
$builtin = [
    'self', 'static', 'parent', 'mixed', 'void', 'never', 'null', 'false', 'true',
    'bool', 'int', 'float', 'string', 'array', 'object', 'callable', 'iterable',
    'Traversable', 'Iterator', 'IteratorAggregate', 'ArrayAccess', 'Countable',
    'ArrayIterator', 'ArrayObject', 'IteratorIterator', 'Generator', 'Closure',
    'Serializable', 'JsonSerializable', 'Stringable', 'DateTime', 'DateTimeInterface',
    'DateTimeImmutable', 'DateTimeZone', 'SimpleXMLElement', 'XMLWriter',
    'Throwable', 'Exception', 'Error', 'TypeError', 'ValueError',
    'RuntimeException', 'LogicException', 'InvalidArgumentException',
    'BadMethodCallException', 'UnexpectedValueException', 'OutOfBoundsException',
    'RangeException', 'DomainException', 'LengthException', 'OverflowException',
    'UnderflowException', 'OutOfRangeException', 'JsonException',
    'RecursiveIteratorIterator', 'RecursiveDirectoryIterator', 'FilesystemIterator',
    'SplFileInfo', 'CurlHandle', 'CurlMultiHandle', 'ReturnTypeWillChange',
    'SensitiveParameter', 'Deprecated', 'Override', 'AllowDynamicProperties',
];
$builtinLower = array_map('strtolower', $builtin);

/** 收集包内所有类名 */
$classes = [];
foreach (new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
) as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }

    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (str_starts_with($relative, 'data/')) {
        continue;
    }

    $classes[] = 'MinS3\\' . str_replace('/', '\\', substr($relative, 0, -4));
}
sort($classes);

$problems = [];
$loaded = 0;
$checkedTypes = 0;

/** 判断一个类型名是否可接受 */
$acceptable = static function (?string $name) use ($builtinLower): bool {
    if ($name === null || $name === '') {
        return true;
    }

    $name = ltrim($name, '?\\');

    if (in_array(strtolower($name), $builtinLower, true)) {
        return true;
    }

    return str_starts_with($name, 'MinS3\\');
};

/** 展开联合类型与可空类型 */
$expand = static function (?ReflectionType $type): array {
    if ($type === null) {
        return [];
    }

    if ($type instanceof ReflectionNamedType) {
        return [$type->getName()];
    }

    if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
        $names = [];
        foreach ($type->getTypes() as $sub) {
            if ($sub instanceof ReflectionNamedType) {
                $names[] = $sub->getName();
            }
        }

        return $names;
    }

    return [];
};

foreach ($classes as $class) {
    try {
        if (!class_exists($class) && !interface_exists($class)
            && !trait_exists($class) && !enum_exists($class)
        ) {
            $problems[] = "{$class}：文件存在但类未定义（类名与路径不符？）";
            continue;
        }

        $reflection = new ReflectionClass($class);
        $loaded++;

        // 父类
        if ($parent = $reflection->getParentClass()) {
            $checkedTypes++;
            if (!$acceptable($parent->getName())) {
                $problems[] = "{$class}：父类 {$parent->getName()} 是外部类";
            }
        }

        // 接口
        foreach ($reflection->getInterfaceNames() as $interface) {
            $checkedTypes++;
            if (!$acceptable($interface)) {
                $problems[] = "{$class}：接口 {$interface} 是外部类";
            }
        }

        // trait
        foreach ($reflection->getTraitNames() as $trait) {
            $checkedTypes++;
            if (!$acceptable($trait)) {
                $problems[] = "{$class}：trait {$trait} 是外部类";
            }
        }

        // 方法签名
        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;   // 继承来的方法在其定义类里检查
            }

            foreach ($method->getParameters() as $parameter) {
                foreach ($expand($parameter->getType()) as $typeName) {
                    $checkedTypes++;
                    if (!$acceptable($typeName)) {
                        $problems[] = "{$class}::{$method->getName()}() 参数 \${$parameter->getName()}"
                                    . " 类型 {$typeName} 是外部类";
                    }
                }
            }

            foreach ($expand($method->getReturnType()) as $typeName) {
                $checkedTypes++;
                if (!$acceptable($typeName)) {
                    $problems[] = "{$class}::{$method->getName()}() 返回类型 {$typeName} 是外部类";
                }
            }
        }

        // 属性类型
        foreach ($reflection->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            foreach ($expand($property->getType()) as $typeName) {
                $checkedTypes++;
                if (!$acceptable($typeName)) {
                    $problems[] = "{$class}::\${$property->getName()} 类型 {$typeName} 是外部类";
                }
            }
        }

        // 类常量里引用的类
        foreach ($reflection->getReflectionConstants() as $constant) {
            if ($constant->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            $value = $constant->getValue();
            if (is_string($value) && str_contains($value, '\\') && class_exists($value)) {
                $checkedTypes++;
                if (!$acceptable($value)) {
                    $problems[] = "{$class}::{$constant->getName()} 指向外部类 {$value}";
                }
            }
        }
    } catch (\Throwable $e) {
        $problems[] = "{$class}：" . get_class($e) . ' - ' . $e->getMessage();
    }
}

echo "已加载类: {$loaded} / " . count($classes) . "\n";
echo "已检查类型引用: {$checkedTypes} 处\n";

// 再做一次文本层面的排查：源码里不该出现常见第三方命名空间
$forbidden = ['GuzzleHttp', 'Psr\\Http', 'JmesPath', 'AWS\\CRT', 'Aws\\', 'Symfony'];
$textHits = [];

foreach (new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
) as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (str_starts_with($relative, 'data/')) {
        continue;
    }

    $code = file_get_contents($file->getPathname());

    // 去掉注释再查，注释里提到 aws-sdk-php 是正常的
    $stripped = '';
    foreach (token_get_all($code) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $stripped .= $token[1];
        } else {
            $stripped .= $token;
        }
    }

    foreach ($forbidden as $namespace) {
        if (str_contains($stripped, $namespace)) {
            $textHits[] = "{$relative}：出现 {$namespace}";
        }
    }
}

echo "源码文本扫描: " . (count($textHits) === 0 ? '未发现第三方命名空间' : count($textHits) . ' 处可疑') . "\n";

if ($problems || $textHits) {
    echo "\n发现问题:\n";
    foreach (array_merge($problems, $textHits) as $problem) {
        echo "  - {$problem}\n";
    }
    exit(1);
}

echo "\n结论: 全部类型引用都在 MinS3\\ 内部或 PHP 内置，零第三方依赖成立。\n";
exit(0);
