#!/usr/bin/env php
<?php

class JsonPatchCompiler
{
    private static $args = [
        'json_dir:',
    ];

    public function __construct()
    {
        error_reporting(0);
        ini_set('display_startup_errors', 0);
        ini_set('display_errors', 0);
    }

    public function run()
    {
        $this->log('start json patching');
        $json_dir = $this->prepareDir('json');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($json_dir));
        foreach ($iterator as $v) {
            if ($v->isDir()) {
                continue;
            }
            if ($v->getExtension() == 'json') {
                $json_content = file_get_contents($v->getRealPath());
                $json = json_decode(trim($json_content), true);
                if ($json && $json['name']) {
                    $this->log('start adding annotation:' . $json['name']);
                    $this->addAnnotation($json);
                    $this->log('end adding annotation:' . $json['name']);
                    file_put_contents(
                        $v->getRealPath(),
                        json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                    );
                }
            }
        }
        $this->log('end json patching');
    }

    private function addAnnotation(&$data)
    {
        if (! is_array($data)) {
            return;
        }
        $doc = $data['doc'];
        if ($doc && preg_match_all('@%([^:%]+):([^%]+)%@', $doc, $match)) {
            foreach ($match[0] as $k => $v) {
                $args = explode(':', trim($v, '%'));
                $name = array_shift($args);
                $value = array_pop($args);
                $this->addThriftTypeAnnotation($data, $name, $value, $args);
            }
            $data['doc'] = preg_replace('@%[^%\n]+%@', '', $data['doc']);
        }
        foreach ($data as $k => &$v) {
            $this->addAnnotation($v);
        }
    }

    private function addThriftTypeAnnotation(&$data, $name, $value, $args)
    {
        $name = trim($name);
        $value = trim($value);
        $all_types = ['type', 'returnType', 'valueType', 'elemType', 'keyType'];
        if (! is_array($data)) {
            return;
        }
        if (preg_match('@list<(.*)>@', $value, $match)) {
            foreach ($all_types as $type) {
                if (isset($data[$type])) {
                    $this->addThriftTypeAnnotation($data[$type], $name, $match[1], $args);
                }
            }
        } elseif (preg_match('@set<(.*)>@', $value, $match)) {
            foreach ($all_types as $type) {
                if (isset($data[$type])) {
                    $this->addThriftTypeAnnotation($data[$type], $name, $match[1], $args);
                }
            }
        } elseif (preg_match('@map<[^,]+,(.*)>@', $value, $match)) {
            foreach ($all_types as $type) {
                if (isset($data[$type])) {
                    $this->addThriftTypeAnnotation($data[$type], $name, $match[1], $args);
                }
            }
        } else {
            $tmp = $value;
            foreach (array_reverse($args) as $arg) {
                $arg = trim($arg);
                if ($arg) {
                    $tmp = [$arg => $tmp];
                }
            }
            $tmp = [$name => $tmp];
            $data = array_replace_recursive($data, $tmp);
        }
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
            mkdir($dir, 0777, true);
        }

        return realpath($dir) . DIRECTORY_SEPARATOR;
    }

    private function log($msg)
    {
        echo $msg . PHP_EOL;
    }

}

$json_patch_compiler = new JsonPatchCompiler();
$json_patch_compiler->run();
