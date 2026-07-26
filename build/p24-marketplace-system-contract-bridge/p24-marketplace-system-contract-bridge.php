<?php
/**
 * Plugin Name: P24 Marketplace OS — System Contract Bridge
 * Plugin URI:  https://маркет.шахты24.рф/
 * Description: Единый машинный паспорт Маркета, реестр маршрутов, тестовых объектов и безопасная семантика рубрик для P24 Marketplace OS.
 * Version:     1.0.0-alpha.3.5.2
 * Author:      Верещагин и Партнёры / NOVA
 * Text Domain: p24-market-system-contract
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

define('P24MSC_VERSION', '1.0.0-alpha.3.5.2');
define('P24MSC_FILE', __FILE__);
define('P24MSC_DIR', plugin_dir_path(__FILE__));
define('P24MSC_URL', plugin_dir_url(__FILE__));

require_once P24MSC_DIR . 'includes/class-p24msc-system-registry.php';
require_once P24MSC_DIR . 'includes/class-p24msc-category-semantics.php';
require_once P24MSC_DIR . 'includes/class-p24msc-admin.php';

final class P24MSC_Plugin {
    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', array($this, 'boot'), 20);
        add_action('admin_notices', array($this, 'dependency_notice'));
    }

    public function boot() {
        P24MSC_System_Registry::instance();
        P24MSC_Category_Semantics::instance();
        if (is_admin()) {
            P24MSC_Admin::instance();
        }
        do_action('p24msc_loaded', P24MSC_VERSION);
    }

    public function dependency_notice() {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        if (!defined('P24M_VERSION')) {
            echo '<div class="notice notice-warning"><p><strong>P24 Marketplace OS — System Contract Bridge:</strong> ядро P24 Marketplace OS не обнаружено. Мост останется безопасно активным, но часть маршрутов и тестовых объектов будет недоступна.</p></div>';
            return;
        }
        if (version_compare((string) P24M_VERSION, '1.0.0-alpha.3.5.1', '<')) {
            echo '<div class="notice notice-warning"><p><strong>P24 Marketplace OS — System Contract Bridge:</strong> рекомендуется ядро не ниже 1.0.0-alpha.3.5.1. Сейчас обнаружена версия ' . esc_html((string) P24M_VERSION) . '.</p></div>';
        }
    }
}

P24MSC_Plugin::instance();
