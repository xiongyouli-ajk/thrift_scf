#!/bin/sh

current_dir=$(dirname "$0")
thrift_dir="$current_dir"/thrift
json_dir="$current_dir"/json

while getopts "t:j:" opt; do
    case $opt in
        t)
            dir=$OPTARG
            if [ -d "$dir" ]; then
                thrift_dir=$dir
            fi
            ;;
        j)
            dir=$OPTARG
            if [ -d "$dir" ]; then
                json_dir=$dir
            fi
            ;;
        \?)
            echo "Invalid option: -$OPTARG"
            ;;
    esac
done

echo "thrift_dir:$thrift_dir"
echo "json_dir:$json_dir"

if [ -d "$json_dir" ]
then
    rm -rf "$json_dir"/*.json
fi

for thrift_file_name in "$thrift_dir"/*.thrift; do
    thrift -r -gen json -v -strict -out "$json_dir" "$thrift_file_name"
done

"$current_dir"/compile-json-patch.php --json_dir="$json_dir"
