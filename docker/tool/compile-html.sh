#!/bin/sh

current_dir=$(dirname "$0")
thrift_dir="$current_dir"/thrift
html_dir="$current_dir"/html

while getopts "t:h:" opt; do
    case $opt in
        t)
            dir=$OPTARG
            if [ -d "$dir" ]; then
                thrift_dir=$dir
            fi
            ;;
        h)
            dir=$OPTARG
            if [ -d "$dir" ]; then
                html_dir=$dir
            fi
            ;;
        \?)
            echo "Invalid option: -$OPTARG"
            ;;
    esac
done

echo "thrift_dir:$thrift_dir"
echo "html_dir:$html_dir"

if [ -d "$html_dir" ]
then
    rm -rf "$html_dir"/*.html
    rm -rf "$html_dir"/*.css
fi

for thrift_file_name in "$thrift_dir"/*.thrift; do
    thrift -r -gen html -v -strict -out "$html_dir" "$thrift_file_name"
done

echo ":target {background-color: #ffa;}" >> "$html_dir"/style.css

for html_file_name in "$html_dir"/*.html; do
    sed -i -e "s/#Typedef_/#Struct_/g" "$html_file_name"
    sed -i -e "s/Thrift/Scf/g" "$html_file_name"
    sed -i -e "s/thrift/scf/g" "$html_file_name"
done
