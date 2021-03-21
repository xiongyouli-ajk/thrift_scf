#!/usr/bin/env php
<?php

class ScfCodeCompiler
{
    private static $args = [
        'json_dir:',
        'php_dir:',
        'java_dir:',
    ];

    private $type_id_map = [
        'typeId' => 'type',
        'elemTypeId' => 'elemType',
        'valueTypeId' => 'valueType',
        'returnTypeId' => 'returnType',
    ];

    /**
     * @var array
     */
    private $all_json = [];
    /**
     * @var string
     */
    private static $template_dir = '';

    public function __construct()
    {
        error_reporting(0);
        ini_set('display_startup_errors', 0);
        ini_set('display_errors', 0);
        self::$template_dir = __DIR__ . DIRECTORY_SEPARATOR . 'template' . DIRECTORY_SEPARATOR;
    }

    public function run()
    {
        $this->log('start scf compiling');
        $json_dir = $this->prepareDir('json');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($json_dir));
        foreach ($iterator as $v) {
            if ($v->isDir()) {
                continue;
            }
            if ($v->getExtension() == 'json') {
                $content = file_get_contents($v->getRealPath());
                $json = json_decode(trim($content), true);
                if ($json && $json['name']) {
                    $json = $this->applyEnum($json, 'enum_field');
                    $json = $this->applyEnum($json, 'enum_return');
                    $json = $this->applyEnum($json, 'enum_arg');
                    $this->all_json[$json['name']] = $json;
                }
            }
        }
        foreach (['php', 'java'] as $code_type) {
            $this->log("compiling {$code_type} code");
            $dir = $this->prepareDir($code_type);
            foreach ($this->all_json as $name => $json) {
                if ($json['namespaces'][$code_type]) {
                    $this->log("compiling {$name}");
                    $this->compileCode($dir, $code_type, $json);
                }
            }
        }
        $this->log('end scf compiling');
    }

    private function applyEnum($json, $enum_type)
    {
        if (! is_array($json)) {
            return $json;
        }
        foreach ($this->type_id_map as $k => $v) {
            if ($json[$k] == 'i32' && $json[$enum_type]) {
                $json[$k] = 'struct';
                $json[$v]['class'] = $json[$enum_type];
            }
        }
        foreach ($json as $k => $v) {
            $json[$k] = $this->applyEnum($v, $enum_type);
        }

        return $json;
    }

    private function prepareDir($code_type)
    {
        $args = getopt('', self::$args);
        $dir = $args["{$code_type}_dir"] ? : __DIR__ . DIRECTORY_SEPARATOR . $code_type;
        if ($code_type == 'json') {
            if (! is_dir($dir) || ! is_readable($dir)) {
                $this->log("{$code_type}_dir:{$dir} not readable");
                exit;
            }
        } else {
            self::rmdir($dir);
            $this->log("rmdir:{$dir}");
            mkdir($dir, 0777, true);
        }

        return realpath($dir) . DIRECTORY_SEPARATOR;
    }

    private function compileCode($dir, $code_type, $json)
    {
        $namespace = $this->getNamespaceFromJson($code_type, $json);
        $path = $this->getPathFromJson($code_type, $json);
        $full_dir = "{$dir}{$path}" . DIRECTORY_SEPARATOR;
        if (! is_readable($full_dir)) {
            mkdir($full_dir, 0777, true);
        }
        $this->log("mkdir:{$full_dir}");
        foreach (['enums', 'structs', 'services'] as $type) {
            foreach ($json[$type] as $data) {
                $template_file = self::$template_dir . "{$code_type}_{$type}.phtml";
                $output_file = $full_dir . "{$data['name']}.{$code_type}";
                $data['namespace'] = $namespace;
                $data['current_json'] = $json['name'];
                $code = $this->renderTemplate($template_file, $data);
                if ($code_type == 'php') {
                    $code = '<?' . 'php' . PHP_EOL . $code;
                }
                file_put_contents($output_file, $code);
                $this->log("generate:{$output_file}");
            }
        }
    }

    private function getNamespaceFromJson($code_type, $json)
    {
        $thrift_namespace = $json['namespaces'][$code_type];
        switch ($code_type) {
            case 'php':
                return trim(str_replace('.', "\\", $thrift_namespace), "\\");
            case 'java':
                return trim($thrift_namespace, '.');
            default:
                return '';
        }
    }

    private function getPathFromJson($code_type, $json)
    {
        $thrift_namespace = $json['namespaces'][$code_type];

        return trim(str_replace('.', DIRECTORY_SEPARATOR, $thrift_namespace), DIRECTORY_SEPARATOR);
    }

    private function renderTemplate($path, $data)
    {
        ob_start();
        if ($data) {
            include($path);
        }
        $str = ob_get_clean();

        return preg_replace("@\n\s+\n@", PHP_EOL, $str);
    }

    public function renderComment($doc, $prefix)
    {
        return str_replace("\n", PHP_EOL . "{$prefix}", rtrim($doc, "\n")) . PHP_EOL;
    }

    public function renderPhpSpec($path, $data, $type, $field_extra, $prefix)
    {
        $data['typeId'] = $type;
        $data['prefix'] = $prefix . '    ';
        $data['field_extra'] = $field_extra;
        if ($field_extra['import_type'] && 'binary' == $data['typeId']) {
            $data['import_type'] = $field_extra['import_type'];
        } elseif ($field_extra['import_list'] && 'binary' == $data['typeId']) {
            $data['typeId'] = 'list';
            $data['elemTypeId'] = 'binary';
            $data['field_extra'] = [
                'import_type' => $field_extra['import_list'],
            ];
        }

        return $this->renderTemplate($path, $data);
    }

    public function getPhpTypeDoc($type_id, $type, $field)
    {
        switch ($type_id) {
            case 'list':
            case 'set':
                return "{$this->getPhpTypeDoc($type['elemTypeId'], $type['elemType'], $type)}[]";
            case 'map':
                return "{$this->getPhpTypeDoc($type['valueTypeId'], $type['valueType'], $type)}[]";
            case 'struct':
                return $this->getShortClassName($type['class']) ? : 'object';
            case 'bool':
                return 'bool';
            case 'byte':
            case 'i8':
            case 'i16':
            case 'i32':
            case 'i64':
                return 'int';
            case 'double':
                return 'float';
            case 'string':
                return 'string';
            case 'binary':
                if ($field['import_list']) {
                    return $this->convertImportTypeToPhpClass($field['import_list']) . '[]';
                } else {
                    return $this->convertImportTypeToPhpClass($field['import_type']);
                }
            default:
                return 'mixed';
        }
    }

    public function convertImportTypeToPhpClass($import_type)
    {
        return "\\" . trim(str_replace('.', "\\", $import_type), "\\");
    }

    public function getPhpArgumentList($field_list)
    {
        $str = '';
        $length = count($field_list);
        foreach ($field_list as $k => $field) {
            $str .= "\${$field['name']} = null";
            if ($k + 1 < $length) {
                $str .= ', ';
            }
        }

        return $str;
    }

    public function getJavaType($type_id, $type, $field)
    {
        $is_required = $field['required'] == 'required';
        switch ($type_id) {
            case 'list':
                return "java.util.List<{$this->getJavaType($type['elemTypeId'], $type['elemType'], $type)}>";
            case 'set':
                return "java.util.Set<{$this->getJavaType($type['elemTypeId'], $type['elemType'], $type)}>";
            case 'map':
                return "java.util.Map<{$this->getJavaType($type['keyTypeId'], $type['keyType'], $type)}, {$this->getJavaType($type['valueTypeId'], $type['valueType'], $type)}>";
            case 'struct':
                return $this->getShortClassName($type['class']) ? : 'Object';
            case 'bool':
                return $is_required ? 'bool' : 'Boolean';
            case 'byte':
            case 'i8':
                return $is_required ? 'byte' : 'Byte';
            case 'i16':
                return $is_required ? 'short' : 'Short';
            case 'i32':
                return $is_required ? 'int' : 'Integer';
            case 'i64':
                return $is_required ? 'long' : 'Long';
            case 'double':
                return $is_required ? 'double' : 'Double';
            case 'string':
                return 'String';
            case 'binary':
                if ($field['import_list']) {
                    return "java.util.List<{$field['import_list']}>";
                } else {
                    return $field['import_type'];
                }

            default:
                return '';
        }
    }

    public function getJavaArgumentList($field_list)
    {
        $str = '';
        $length = count($field_list);
        foreach ($field_list as $k => $field) {
            $str .= "{$this->getJavaType($field['typeId'], $field['type'], $field)} {$field['name']}";
            if ($k + 1 < $length) {
                $str .= ', ';
            }
        }

        return $str;
    }

    private function getStructRecursive($field, $code_type, $current_json = '', $get_full = false)
    {
        $type_id = '';
        $type = [];
        foreach ($this->type_id_map as $k => $v) {
            if (! empty($field[$k])) {
                $type_id = $field[$k];
                $type = $field[$v];
            }
        }
        switch ($type_id) {
            case 'list':
            case 'set':
                return $this->getStructRecursive($type, $code_type, $current_json, $get_full);
            case 'map':
                return $this->getStructRecursive($type, $code_type, $current_json, $get_full);
            case 'struct':
                $full_class_names = $this->getFullClassName(
                    $type['class'],
                    $code_type,
                    $current_json
                );
                $short_class_names = $this->getShortClassName($type['class']);

                return $get_full ? $full_class_names : $short_class_names;
            default:
                return '';
        }
    }

    /**
     * http://igit.58corp.com/_apf/system-ext/blob/master/lib/scf/com/bj58/spat/scf/serialize/component/TypeMapV3.php
     * @param       $type_id
     * @param array $type
     *
     * @return string
     */
    private function getScfType($type_id, $type)
    {
        switch ($type_id) {
            case 'list':
                return "List<{$this->getScfType($type['elemTypeId'], $type['elemType'])}>";
            case 'set':
                return "Set<{$this->getScfType($type['elemTypeId'], $type['elemType'])}>";
            case 'map':
                return "Map<key = {$this->getScfType($type['keyTypeId'], $type['keyType'])}, value = {$this->getScfType($type['valueTypeId'], $type['valueType'])}>";
            case 'struct':
                return $this->getShortClassName($type['class']) ? : 'Object';
            case 'bool':
                return 'Boolean';
            case 'byte':
            case 'i8':
                return 'Byte';
            case 'i16':
                return 'Short';
            case 'i32':
                return 'Integer';
            case 'i64':
                return 'Long';
            case 'double':
                return 'Double';
            case 'string':
                return 'String';
            default:
                return '';
        }
    }

    /**
     * http://igit.58corp.com/_apf/system-ext/blob/master/lib/scf/com/bj58/spat/scf/serialize/component/SCFType.php
     * @param $type_id
     * @param $version
     *
     * @return string
     */
    public function getScfTypeConst($type_id, $version)
    {
        switch ($type_id) {
            case 'list':
                $map = [
                    'v1' => 'LST',
                ];
                break;
            case 'set':
                $map = [
                    'v1' => 'SET',
                ];
                break;
            case 'map':
                $map = [
                    'v1' => 'MAP',
                ];
                break;
            case 'struct':
            case 'binary':
                $map = [
                    'v1' => 'OBJECT',
                ];
                break;
            case 'bool':
                $map = [
                    'v1' => 'BOOL',
                    'v3' => 'BBOOL',
                ];
                break;
            case 'byte':
            case 'i8':
                $map = [
                    'v1' => 'BYTE',
                    'v3' => 'BBYTE',
                ];
                break;
            case 'i16':
                $map = [
                    'v1' => 'I16',
                    'v3' => 'BSHORT',
                ];
                break;
            case 'i32':
                $map = [
                    'v1' => 'I32',
                    'v3' => 'BINT',
                ];
                break;
            case 'i64':
                $map = [
                    'v1' => 'I64',
                    'v3' => 'BLONG',
                ];
                break;
            case 'double':
                $map = [
                    'v1' => 'DOUBLE',
                    'v3' => 'BDOUBLE',
                ];
                break;
            case 'string':
                $map = [
                    'v1' => 'STRING',
                ];
                break;
            default:
                $map = [];
                break;
        }

        return $map[$version] ? : '';
    }

    private function getFullClassName($thrift_struct, $code_type, $current_json)
    {
        list($include, $short_class_name) = explode('.', $thrift_struct);
        if (! $short_class_name) {
            $short_class_name = $include;
            $include = $current_json;
        }
        $php_namespace = $this->getNamespaceFromJson($code_type, $this->all_json[$include]);
        switch ($code_type) {
            case 'php':
                return "{$php_namespace}\\{$short_class_name}";
            case 'java':
                return "{$php_namespace}.{$short_class_name}";
            default:
                return '';
        }
    }

    private function getShortClassName($thrift_struct)
    {
        list($include, $short_class_name) = explode('.', $thrift_struct);

        return $short_class_name ? : $include;
    }

    private function rmdir($dir)
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != '.' && $object != '..') {
                    if (is_dir($full_path = $dir . DIRECTORY_SEPARATOR . $object)) {
                        $this->rmdir($full_path);
                    } else {
                        @unlink($full_path);
                    }
                }
            }
            @rmdir($dir);
        }
    }

    private function log($msg)
    {
        echo $msg . PHP_EOL;
    }

}

$scf_code_compiler = new ScfCodeCompiler;
$scf_code_compiler->run();
