<?php
/**
 * Plugin Name: Neo Plugin Manager
 * Description: Installiert und aktualisiert freigegebene Neo-Plugins aus dem offiziellen GitHub-Katalog.
 * Version: 0.1.4
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: Neo Consult
 * License: GPL-2.0-or-later
 * Update URI: https://github.com/neo-consult/neo-plugin-manager
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class NeoPluginManager
{
    private const CATALOG_OPTION = 'neo_plugin_manager_catalog_url';
    private const DEFAULT_CATALOG_URL = 'https://raw.githubusercontent.com/neo-consult/neo-plugin-catalog/main/plugins.json';
    private const CACHE_KEY = 'neo_plugin_manager_catalog';

    /** @var array<string, string> */
    private static array $pendingPackageHashes = [];

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'registerPage']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'injectUpdates']);
        add_filter('plugins_api', [self::class, 'pluginInformation'], 10, 3);
        add_filter('upgrader_pre_download', [self::class, 'verifyPackageDownload'], 10, 4);
        add_action('admin_post_neo_plugin_manager_install', [self::class, 'installPlugin']);
    }

    public static function registerPage(): void
    {
        add_management_page('Neo Plugins', 'Neo Plugins', 'install_plugins', 'neo-plugin-manager', [self::class, 'renderPage']);
    }

    public static function registerSettings(): void
    {
        register_setting('neo_plugin_manager', self::CATALOG_OPTION, [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitizeCatalogUrl'],
            'default' => self::DEFAULT_CATALOG_URL,
        ]);
    }

    public static function sanitizeCatalogUrl(string $url): string
    {
        $url = esc_url_raw($url);
        return wp_parse_url($url, PHP_URL_SCHEME) === 'https' ? $url : self::DEFAULT_CATALOG_URL;
    }

    /** @return array<int, array<string, mixed>> */
    private static function getCatalog(bool $force = false): array
    {
        if (!$force) {
            $cached = get_transient(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $url = (string) get_option(self::CATALOG_OPTION, self::DEFAULT_CATALOG_URL);
        $response = wp_remote_get($url, ['timeout' => 10, 'headers' => ['Accept' => 'application/json']]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return [];
        }
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $plugins = is_array($decoded['plugins'] ?? null) ? $decoded['plugins'] : [];
        $plugins = array_values(array_filter($plugins, [self::class, 'isValidPluginRecord']));
        set_transient(self::CACHE_KEY, $plugins, 15 * MINUTE_IN_SECONDS);

        return $plugins;
    }

    /** @param mixed $plugin */
    private static function isValidPluginRecord(mixed $plugin): bool
    {
        if (!is_array($plugin)) {
            return false;
        }
        if (($plugin['published'] ?? null) !== true) {
            return false;
        }
        foreach (['slug', 'plugin_file', 'name', 'version', 'package', 'sha256'] as $field) {
            if (!is_string($plugin[$field] ?? null) || $plugin[$field] === '') {
                return false;
            }
        }
        return wp_parse_url($plugin['package'], PHP_URL_SCHEME) === 'https'
            && preg_match('/^[a-f0-9]{64}$/', $plugin['sha256']) === 1;
    }

    public static function injectUpdates(object $transient): object
    {
        if (empty($transient->checked) || !is_array($transient->checked)) {
            return $transient;
        }

        foreach (self::getCatalog() as $plugin) {
            $pluginFile = $plugin['plugin_file'];
            $installedVersion = $transient->checked[$pluginFile] ?? null;
            if (!is_string($installedVersion) || !version_compare($plugin['version'], $installedVersion, '>')) {
                continue;
            }
            $transient->response[$pluginFile] = (object) [
                'id' => 'neo-plugin://' . $plugin['slug'],
                'slug' => $plugin['slug'],
                'plugin' => $pluginFile,
                'new_version' => $plugin['version'],
                'url' => $plugin['homepage'] ?? 'https://github.com/neo-consult/' . $plugin['slug'],
                'package' => $plugin['package'],
                'tested' => $plugin['tested'] ?? '',
                'requires_php' => $plugin['requires_php'] ?? '8.1',
            ];
        }

        return $transient;
    }

    public static function pluginInformation(mixed $result, string $action, object $args): mixed
    {
        if ($action !== 'plugin_information' || !isset($args->slug)) {
            return $result;
        }
        foreach (self::getCatalog() as $plugin) {
            if ($plugin['slug'] !== $args->slug) {
                continue;
            }
            return (object) [
                'name' => $plugin['name'],
                'slug' => $plugin['slug'],
                'version' => $plugin['version'],
                'author' => 'Neo Consult',
                'homepage' => $plugin['homepage'] ?? '',
                'requires' => $plugin['requires'] ?? '6.0',
                'requires_php' => $plugin['requires_php'] ?? '8.1',
                'download_link' => $plugin['package'],
                'sections' => ['description' => wp_kses_post($plugin['description'] ?? '')],
            ];
        }
        return $result;
    }

    /**
     * Verifiziert Neo-Pakete vor dem Entpacken im WordPress-Upgrader.
     */
    public static function verifyPackageDownload(mixed $reply, string $package, object $upgrader, array $hookExtra): mixed
    {
        if ($reply !== false) {
            return $reply;
        }

        $expectedHash = self::$pendingPackageHashes[$package] ?? null;
        if ($expectedHash === null && isset($hookExtra['plugin'])) {
            $plugin = self::findCatalogPluginByFile((string) $hookExtra['plugin']);
            $expectedHash = $plugin['sha256'] ?? null;
        }
        if (!is_string($expectedHash) || !preg_match('/^[a-f0-9]{64}$/', $expectedHash)) {
            return $reply;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $download = download_url($package, 300);
        if (is_wp_error($download)) {
            return $download;
        }
        $actualHash = hash_file('sha256', $download);
        if (!is_string($actualHash) || !hash_equals($expectedHash, $actualHash)) {
            wp_delete_file($download);
            return new WP_Error('neo_plugin_checksum_mismatch', 'Die Prüfsumme des Plugin-Pakets stimmt nicht mit dem offiziellen Katalog überein.');
        }

        return $download;
    }

    /** @return array<string, mixed>|null */
    private static function findCatalogPluginByFile(string $pluginFile): ?array
    {
        foreach (self::getCatalog() as $plugin) {
            if ($plugin['plugin_file'] === $pluginFile) {
                return $plugin;
            }
        }
        return null;
    }

    public static function installPlugin(): void
    {
        if (!current_user_can('install_plugins')) {
            wp_die('Nicht berechtigt.');
        }
        check_admin_referer('neo_plugin_manager_install');
        $slug = sanitize_key((string) ($_POST['slug'] ?? ''));
        $catalog = self::getCatalog(true);
        $plugin = current(array_filter($catalog, static fn(array $item): bool => $item['slug'] === $slug));
        if (!$plugin) {
            wp_safe_redirect(add_query_arg('neo_plugin_manager_notice', 'not-found', admin_url('tools.php?page=neo-plugin-manager')));
            exit;
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skins.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
        self::$pendingPackageHashes[$plugin['package']] = $plugin['sha256'];
        try {
            $installed = $upgrader->install($plugin['package']);
        } finally {
            unset(self::$pendingPackageHashes[$plugin['package']]);
        }
        $notice = $installed ? 'installed' : 'failed';
        wp_safe_redirect(add_query_arg('neo_plugin_manager_notice', $notice, admin_url('tools.php?page=neo-plugin-manager')));
        exit;
    }

    public static function renderPage(): void
    {
        if (!current_user_can('install_plugins')) {
            return;
        }
        $notice = sanitize_key((string) ($_GET['neo_plugin_manager_notice'] ?? ''));
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $installed = get_plugins();
        ?>
        <div class="wrap">
            <h1>Neo Plugins</h1>
            <?php if ($notice === 'installed') : ?><div class="notice notice-success"><p>Plugin wurde installiert.</p></div><?php endif; ?>
            <?php if ($notice === 'failed') : ?><div class="notice notice-error"><p>Plugin konnte nicht installiert werden.</p></div><?php endif; ?>
            <?php if ($notice === 'not-found') : ?><div class="notice notice-error"><p>Plugin ist nicht im Katalog verfügbar.</p></div><?php endif; ?>
            <p>Installationen und Updates werden ausschließlich aus dem konfigurierten HTTPS-Katalog bezogen.</p>
            <table class="widefat striped"><thead><tr><th>Plugin</th><th>Version</th><th>Status</th><th></th></tr></thead><tbody>
            <?php foreach (self::getCatalog() as $plugin) : $isInstalled = isset($installed[$plugin['plugin_file']]); ?>
                <tr><td><strong><?php echo esc_html($plugin['name']); ?></strong><br><span class="description"><?php echo esc_html($plugin['description'] ?? ''); ?></span></td>
                    <td><?php echo esc_html($plugin['version']); ?></td><td><?php echo $isInstalled ? 'Installiert' : 'Nicht installiert'; ?></td><td>
                    <?php if (!$isInstalled) : ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('neo_plugin_manager_install'); ?><input type="hidden" name="action" value="neo_plugin_manager_install"><input type="hidden" name="slug" value="<?php echo esc_attr($plugin['slug']); ?>"><button class="button button-primary">Installieren</button></form><?php endif; ?>
                </td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <h2>Katalog</h2><form method="post" action="options.php"><?php settings_fields('neo_plugin_manager'); ?><input class="regular-text code" type="url" name="<?php echo esc_attr(self::CATALOG_OPTION); ?>" value="<?php echo esc_attr((string) get_option(self::CATALOG_OPTION, self::DEFAULT_CATALOG_URL)); ?>" required> <?php submit_button('Katalog speichern', 'secondary', 'submit', false); ?></form>
        </div>
        <?php
    }
}

NeoPluginManager::init();
