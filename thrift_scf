#!/bin/sh

usr_dir=/usr
tool_dir="$usr_dir"/thrift_scf
thrift_dir="$usr_dir"/thrift
html_dir="$usr_dir"/html
json_dir="$usr_dir"/json
php_dir="$usr_dir"/php
java_dir="$usr_dir"/java


"$tool_dir"/compile-html.sh -t "$thrift_dir" -h "$html_dir"
"$tool_dir"/compile-json.sh -t "$thrift_dir" -j "$json_dir"
"$tool_dir"/compile-scf.php --json_dir="$json_dir" --php_dir="$php_dir" --java_dir="$java_dir"
