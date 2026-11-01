#!/bin/sh
set -eu

sync_bundled_component() {
    seed_dir="$1"
    target_dir="$2"
    manifest_name="$3"

    [ -d "$seed_dir" ] || return 0
    [ -f "$seed_dir/$manifest_name" ] || return 0
    seed_version="$(php -r '$m=json_decode(file_get_contents($argv[1]), true); echo is_array($m) ? ($m["version"] ?? "") : "";' "$seed_dir/$manifest_name")"
    [ -n "$seed_version" ] || return 0

    if [ ! -f "$target_dir/$manifest_name" ]; then
        mkdir -p "$target_dir"
        cp -a "$seed_dir/." "$target_dir/"
        chown -R www-data:www-data "$target_dir"
        return 0
    fi

    installed_version="$(php -r '$m=json_decode(file_get_contents($argv[1]), true); echo is_array($m) ? ($m["version"] ?? "") : "";' "$target_dir/$manifest_name")"
    [ -n "$installed_version" ] || return 0

    if php -r 'exit(version_compare($argv[1], $argv[2], ">") ? 0 : 1);' "$seed_version" "$installed_version"; then
        find "$target_dir" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
        cp -a "$seed_dir/." "$target_dir/"
        chown -R www-data:www-data "$target_dir"
    fi
}

# Named volumes keep extension directories across image updates. Advance only
# bundled components whose manifest version is behind the version in this image.
sync_bundled_component /usr/local/share/pafish-seed/themes/default /var/www/html/themes/default theme.json
sync_bundled_component /usr/local/share/pafish-seed/plugins/sitemap /var/www/html/plugins/sitemap plugin.json
sync_bundled_component /usr/local/share/pafish-seed/plugins/ai_foundation /var/www/html/plugins/ai_foundation plugin.json

exec "$@"
