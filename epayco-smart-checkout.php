<?php
/**
 * Plugin Name: ePayco Smart Checkout
 * Description: Shortcode + UI + ePayco Smart Checkout v2 usando sessionId (Apify). Panel de ajustes. Compatible con Elementor.
 * Version: 1.2.3
 * Author: Daniel Rozo
 * Author URI: https://danielrozo.com/
 */

if (!defined('ABSPATH'))
  exit;

define('EPAYCO_SCS_OPT_KEY', 'epayco_scs_settings');
define('EPAYCO_SCS_TRM_TRANSIENT', 'epayco_scs_trm_today');

/** Add "Ajustes" link in Plugins list */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
  $url = admin_url('options-general.php?page=epayco-smart-checkout');
  array_unshift($links, '<a href="' . esc_url($url) . '">Ajustes</a>');
  return $links;
});



/**
 * Hardening for guest caches / optimizers (Cloudflare Rocket Loader, Autoptimize, LiteSpeed)
 * Adds attributes to prevent delaying/deferring critical scripts.
 */
add_filter('script_loader_tag', function ($tag, $handle, $src) {
  if (in_array($handle, ['epayco-checkout-v2', 'epayco-sc'], true)) {
    // Cloudflare Rocket Loader
    if (strpos($tag, 'data-cfasync') === false) {
      $tag = str_replace('<script ', '<script data-cfasync="false" ', $tag);
    }
    // Autoptimize (and some others)
    if (strpos($tag, 'data-noptimize') === false) {
      $tag = str_replace('<script ', '<script data-noptimize="1" ', $tag);
    }
    // WP Rocket (Delay JS) - best effort
    if (strpos($tag, 'data-rocketlazyloadscript') === false) {
      $tag = str_replace('<script ', '<script data-rocketlazyloadscript="false" ', $tag);
    }

    // LiteSpeed / generic
    if (strpos($tag, 'data-no-optimize') === false) {
      $tag = str_replace('<script ', '<script data-no-optimize="1" ', $tag);
    }
  }
  return $tag;
}, 10, 3);





/**
 * Fuente TRM (Socrata / datos.gov.co) con Fallback (DolarApi.com)
 * Dev docs (Socrata): https://dev.socrata.com/
 * Dataset TRM: https://www.datos.gov.co/Econom-a-y-Finanzas/TRM/ceyp-9c7c
 */
function epayco_scs_fetch_trm()
{
  $trm_value = 0;
  $vig = '';
  $userAgent = 'WordPress/' . get_bloginfo('version') . '; ' . home_url();

  // 1. Intentar con Socrata / datos.gov.co
  $url = 'https://www.datos.gov.co/resource/ceyp-9c7c.json?$order=vigenciadesde%20DESC&$limit=1';
  $res = wp_remote_get($url, [
    'timeout' => 15,
    'headers' => [
      'Accept' => 'application/json',
      'User-Agent' => $userAgent,
    ],
  ]);

  if (!is_wp_error($res)) {
    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);
    if ($code >= 200 && $code < 300) {
      $json = json_decode($body, true);
      if (is_array($json) && !empty($json[0])) {
        $trm_value = isset($json[0]['valor']) ? floatval($json[0]['valor']) : 0;
        $vig = $json[0]['vigenciadesde'] ?? '';
      }
    }
  }

  // 2. Si falló la anterior, usar co.dolarapi.com como fallback rápido y estable
  if ($trm_value <= 0) {
    $url = 'https://co.dolarapi.com/v1/trm';
    $res = wp_remote_get($url, [
      'timeout' => 10,
      'headers' => [
        'Accept' => 'application/json',
        'User-Agent' => $userAgent,
      ],
    ]);

    if (!is_wp_error($res)) {
      $code = wp_remote_retrieve_response_code($res);
      $body = wp_remote_retrieve_body($res);
      if ($code >= 200 && $code < 300) {
        $json = json_decode($body, true);
        if (is_array($json) && isset($json['valor'])) {
          $trm_value = floatval($json['valor']);
          $vig = $json['fechaActualizacion'] ?? '';
        }
      }
    }
  }

  if ($trm_value <= 0) {
    return new WP_Error('epayco_trm_failed', 'No se pudo obtener la TRM de ninguna fuente.');
  }

  return [
    'trm' => $trm_value,
    'vigenciadesde' => $vig,
    'fetched_at' => time(),
  ];
}

function epayco_scs_get_trm_today()
{
  $cached = get_transient(EPAYCO_SCS_TRM_TRANSIENT);
  if (is_array($cached) && !empty($cached['trm']) && floatval($cached['trm']) > 0)
    return $cached;

  $data = epayco_scs_fetch_trm();
  if (is_wp_error($data)) {
    // Fallback: usar el último valor guardado en base de datos
    $last = get_option('epayco_scs_trm_last', null);
    if (is_array($last) && !empty($last['trm']) && floatval($last['trm']) > 0) {
      // Guardar el fallback temporalmente por 1 hora para evitar reintentos lentos en cada recarga
      set_transient(EPAYCO_SCS_TRM_TRANSIENT, $last, HOUR_IN_SECONDS);
      return $last;
    }
    // Último recurso (quemado)
    $resort = ['trm' => 4000, 'vigenciadesde' => '', 'fetched_at' => time()];
    set_transient(EPAYCO_SCS_TRM_TRANSIENT, $resort, HOUR_IN_SECONDS);
    return $resort;
  }

  set_transient(EPAYCO_SCS_TRM_TRANSIENT, $data, DAY_IN_SECONDS);
  update_option('epayco_scs_trm_last', $data, false);
  return $data;
}

function epayco_scs_defaults()
{
  $trm = epayco_scs_get_trm_today();
  $trm_value = floatval($trm['trm'] ?? 4000);
  if ($trm_value <= 0) {
    $trm_value = 4000;
  }

  $copMin = 5000;
  $copMax = 5000000;

  // Default USD bounds derived from COP bounds / TRM
  $usdMin = ceil(($copMin / $trm_value) * 100) / 100;
  $usdMax = floor(($copMax / $trm_value) * 100) / 100;

  return [
    'mode' => 'onpage',          // desktop: onpage | standard
    'test' => 1,                 // 1 | 0
    'button_text' => 'Pagar con ePayco',

    // Pago (payload a ePayco)
    'payment_name' => 'Pago en WordPress',
    'payment_description' => 'Pago en {site_name}',
    'url_response' => '', // Nuevo campo para URL de respuesta

    'default_currency' => 'COP', // COP | USD
    'show_currency' => 1,        // 1 | 0

    'primary_color' => '#0d6efd',

    // ePayco keys (configured in admin)
    'epayco_public_key' => '',
    'epayco_private_key' => '',

    // Fonts: google preset or custom link
    'font_mode' => 'google',     // google | custom
    'google_font' => 'Poppins',  // preset
    'custom_font_name' => '',
    'custom_font_css_url' => '',

    // Limits
    'cop_min' => $copMin,
    'cop_max' => $copMax,
    'usd_min' => $usdMin,
    'usd_max' => $usdMax,
    'auto_usd_limits' => 1,
  ];
}

function epayco_scs_get_settings()
{
  $saved = get_option(EPAYCO_SCS_OPT_KEY, []);
  if (!is_array($saved))
    $saved = [];
  $settings = array_merge(epayco_scs_defaults(), $saved);

  // Si auto_usd_limits está activo (siempre forzado a 1 en sanidad), recalcular dinámicamente según la TRM actual
  if (isset($settings['auto_usd_limits']) && (int) $settings['auto_usd_limits'] === 1) {
    $trm = epayco_scs_get_trm_today();
    $trm_value = floatval($trm['trm'] ?? 4000);
    if ($trm_value <= 0) {
      $trm_value = 4000;
    }
    $settings['usd_min'] = ceil(($settings['cop_min'] / $trm_value) * 100) / 100;
    $settings['usd_max'] = floor(($settings['cop_max'] / $trm_value) * 100) / 100;

    // Asegurar que min <= max
    if ($settings['usd_min'] > $settings['usd_max']) {
      $settings['usd_min'] = $settings['usd_max'];
    }
  }

  return $settings;
}

/** Admin settings page */
add_action('admin_enqueue_scripts', function ($hook) {
  // Only on Settings -> ePayco Smart Checkout
  if ($hook !== 'settings_page_epayco-smart-checkout')
    return;
  wp_enqueue_style('wp-color-picker');
  wp_enqueue_style('epayco-sc-admin', plugins_url('admin/admin.css', __FILE__), [], '3.8.2');
  wp_enqueue_script('wp-color-picker');
  wp_enqueue_script('epayco-sc-admin', plugins_url('admin/admin.js', __FILE__), ['wp-color-picker'], '3.8.2', true);
});

add_action('admin_menu', function () {
  add_options_page(
    'ePayco Smart Checkout',
    'ePayco Smart Checkout',
    'manage_options',
    'epayco-smart-checkout',
    'epayco_scs_render_settings_page'
  );
});

add_action('admin_init', function () {
  register_setting('epayco_scs_group', EPAYCO_SCS_OPT_KEY, 'epayco_scs_sanitize_settings');

  add_settings_section(
    'epayco_scs_main',
    'Ajustes Generales',
    function () {

      $trm = epayco_scs_get_trm_today();
      $trmValue = floatval($trm['trm'] ?? 0);
      $trmText = $trmValue ? number_format($trmValue, 2, ',', '.') : '—';
      $vig = !empty($trm['vigenciadesde']) ? $trm['vigenciadesde'] : '—';

      // Formatting date nicely
      $fetchedTs = 0;
      if (isset($trm['fetched_at'])) {
        $fetchedTs = is_numeric($trm['fetched_at']) ? intval($trm['fetched_at']) : strtotime((string) $trm['fetched_at']);
      }
      $dateStr = $fetchedTs ? (function_exists('wp_date') ? wp_date('d M Y, h:i A', $fetchedTs) : date_i18n('d M Y, h:i A', $fetchedTs)) : '—';

      // Current mode detection for display
      $s = epayco_scs_get_settings();
      $isTest = (int) $s['test'] === 1;

      ?>
    <div class="epayco-sc-admin-grid">
      <!-- Status Card -->
      <div class="epayco-sc-card">
        <h2>
          <span class="dashicons dashicons-chart-line"></span> Estado del Sistema
        </h2>
        <div style="margin: 20px 0;">
          <div style="display:flex; justify-content:space-between; margin-bottom:12px; align-items:center;">
            <strong>TRM Actual (COP/USD):</strong>
            <span class="epayco-sc-pill epayco-sc-badge-ok">$<?php echo esc_html($trmText); ?></span>
          </div>
          <div style="display:flex; justify-content:space-between; margin-bottom:12px; align-items:center;">
            <strong>Última Actualización:</strong>
            <span class="epayco-sc-admin-small"><?php echo esc_html($dateStr); ?></span>
          </div>
          <div style="display:flex; justify-content:space-between; margin-bottom:12px; align-items:center;">
            <strong>Acciones:</strong>
            <button type="button" class="button button-secondary button-small" id="epayco-sc-refresh-trm" style="margin: 0; line-height: 1.5; height: 26px; display: inline-flex; align-items: center; justify-content: center; gap: 4px;">
              <span class="dashicons dashicons-update" style="font-size:14px; width:14px; height:14px;"></span> Actualizar ahora
            </button>
          </div>
          <div style="display:flex; justify-content:space-between; align-items:center;">
            <strong>Fuente:</strong>
            <a href="https://www.datos.gov.co/Econom-a-y-Finanzas/TRM/ceyp-9c7c" target="_blank"
              style="text-decoration:none;font-size:13px;">datos.gov.co ↗</a>
          </div>
        </div>
        <p class="epayco-sc-muted">Los límites en USD se calculan automáticamente con esta tasa.</p>
      </div>

      <!-- Usage Card -->
      <div class="epayco-sc-card">
        <h2>
          <span class="dashicons dashicons-shortcode"></span> Integración Rápida
        </h2>
        <p>Copia y pega este shortcode en cualquier página o widget de Elementor:</p>

        <code id="epayco-sc-shortcode">[epayco_smart_checkout]</code>

        <div style="text-align:center; margin-top:15px;">
          <button type="button" class="button button-secondary" id="epayco-sc-copy-shortcode">
            <span class="dashicons dashicons-admin-page" style="line-height:1.3;"></span> Copiar al portapapeles
          </button>
        </div>
      </div>
    </div>

    <div
      style="background:#fff; padding:20px; border-radius:8px; border-left:4px solid var(--epayco-secondary); box-shadow:0 1px 3px rgba(0,0,0,0.05); margin-bottom:30px;">
      <h3 style="margin:0 0 10px; font-size:16px;">🚀 Primeros pasos</h3>
      <ol style="margin:0 0 0 20px; color:#475569;">
        <li>Configura tus <strong>Credenciales</strong> (Public & Private Key) abajo.</li>
        <li>Define si estás en <strong>Pruebas</strong> o Producción.</li>
        <li>Pega el shortcode en tu página de ventas.</li>
      </ol>
    </div>
    <?php
    },
    'epayco-smart-checkout'
  );

  add_settings_field('mode', 'Modo (desktop)', 'epayco_scs_field_mode', 'epayco-smart-checkout', 'epayco_scs_main');
  add_settings_field('test', 'Entorno', 'epayco_scs_field_test', 'epayco-smart-checkout', 'epayco_scs_main');
  add_settings_field('button_text', 'Texto del botón', 'epayco_scs_field_button', 'epayco-smart-checkout', 'epayco_scs_main');
  add_settings_field('payment_name', 'Nombre del pago', 'epayco_scs_field_payment_name', 'epayco-smart-checkout', 'epayco_scs_main');
  add_settings_field('payment_description', 'Descripción del pago', 'epayco_scs_field_payment_description', 'epayco-smart-checkout', 'epayco_scs_main');
  add_settings_field('url_response', 'URL de Respuesta', 'epayco_scs_field_url_response', 'epayco-smart-checkout', 'epayco_scs_main');
  add_settings_field('primary_color', 'Color del botón', 'epayco_scs_field_primary', 'epayco-smart-checkout', 'epayco_scs_main');

  add_settings_field('epayco_keys', 'Credenciales ePayco', 'epayco_scs_field_epayco_keys', 'epayco-smart-checkout', 'epayco_scs_main');

  add_settings_field('font_mode', 'Fuente', 'epayco_scs_field_font_mode', 'epayco-smart-checkout', 'epayco_scs_main');

  add_settings_field('default_currency', 'Divisa por defecto', 'epayco_scs_field_currency', 'epayco-smart-checkout', 'epayco_scs_main');
  add_settings_field('show_currency', 'Mostrar selector de divisa', 'epayco_scs_field_show_currency', 'epayco-smart-checkout', 'epayco_scs_main');

  add_settings_field('limits', 'Límites de Pago (Mínimo y Máximo)', 'epayco_scs_field_limits', 'epayco-smart-checkout', 'epayco_scs_main');
  add_settings_field('auto_usd_limits', 'USD (automático)', 'epayco_scs_field_auto_usd_limits', 'epayco-smart-checkout', 'epayco_scs_main');
});

function epayco_scs_is_hex_color($c)
{
  return is_string($c) && preg_match('/^#[0-9A-Fa-f]{6}$/', $c);
}

function epayco_scs_sanitize_settings($input)
{
  // Force TRM refresh when saving settings so calculations use latest data
  delete_transient(EPAYCO_SCS_TRM_TRANSIENT);

  $d = epayco_scs_defaults();
  $out = [];

  $mode = isset($input['mode']) ? sanitize_text_field($input['mode']) : $d['mode'];
  $out['mode'] = in_array($mode, ['onpage', 'standard'], true) ? $mode : $d['mode'];

  // Fix test/production mode logic
  if (isset($input['test'])) {
    $out['test'] = ((int) $input['test'] === 1) ? 1 : 0;
  } else {
    $out['test'] = $d['test'];
  }
  $out['button_text'] = isset($input['button_text']) ? sanitize_text_field($input['button_text']) : $d['button_text'];

  $out['payment_name'] = isset($input['payment_name']) ? sanitize_text_field($input['payment_name']) : $d['payment_name'];
  $out['payment_description'] = isset($input['payment_description']) ? sanitize_text_field($input['payment_description']) : $d['payment_description'];
  $out['url_response'] = isset($input['url_response']) ? esc_url_raw($input['url_response']) : $d['url_response'];

  $primary = isset($input['primary_color']) ? trim(sanitize_text_field($input['primary_color'])) : $d['primary_color'];
  $out['primary_color'] = epayco_scs_is_hex_color($primary) ? $primary : $d['primary_color'];

  $out['epayco_public_key'] = isset($input['epayco_public_key']) ? trim(sanitize_text_field($input['epayco_public_key'])) : $d['epayco_public_key'];
  $out['epayco_private_key'] = isset($input['epayco_private_key']) ? trim(sanitize_text_field($input['epayco_private_key'])) : $d['epayco_private_key'];

  // Font handling
  $fontMode = isset($input['font_mode']) ? sanitize_text_field($input['font_mode']) : $d['font_mode'];
  $out['font_mode'] = in_array($fontMode, ['google', 'custom'], true) ? $fontMode : $d['font_mode'];

  $googleFont = isset($input['google_font']) ? sanitize_text_field($input['google_font']) : $d['google_font'];
  $allowed = ['Poppins', 'Inter', 'Nunito', 'Roboto', 'Montserrat', 'Open Sans'];
  $out['google_font'] = in_array($googleFont, $allowed, true) ? $googleFont : $d['google_font'];

  $out['custom_font_name'] = isset($input['custom_font_name']) ? sanitize_text_field($input['custom_font_name']) : '';
  $out['custom_font_css_url'] = isset($input['custom_font_css_url']) ? esc_url_raw($input['custom_font_css_url']) : '';

  $currency = isset($input['default_currency']) ? strtoupper(sanitize_text_field($input['default_currency'])) : $d['default_currency'];
  $out['default_currency'] = in_array($currency, ['COP', 'USD'], true) ? $currency : $d['default_currency'];

  $out['show_currency'] = isset($input['show_currency']) ? 1 : 0;

  // Limits
  $cop_min_raw = isset($input['cop_min']) ? sanitize_text_field($input['cop_min']) : '';
  $cop_min_clean = str_replace('.', '', $cop_min_raw);
  $cop_min_clean = str_replace(',', '.', $cop_min_clean);
  $out['cop_min'] = $cop_min_raw !== '' ? max(0, floatval($cop_min_clean)) : $d['cop_min'];

  $cop_max_raw = isset($input['cop_max']) ? sanitize_text_field($input['cop_max']) : '';
  $cop_max_clean = str_replace('.', '', $cop_max_raw);
  $cop_max_clean = str_replace(',', '.', $cop_max_clean);
  $out['cop_max'] = $cop_max_raw !== '' ? max(0, floatval($cop_max_clean)) : $d['cop_max'];


  $out['auto_usd_limits'] = isset($input['auto_usd_limits']) ? 1 : 0;

  if ((int) $out['auto_usd_limits'] === 1) {
    $trm = epayco_scs_get_trm_today();
    $trm_value = floatval($trm['trm'] ?? 4000);
    if ($trm_value <= 0)
      $trm_value = 4000;

    $out['usd_min'] = ceil(($out['cop_min'] / $trm_value) * 100) / 100;
    $out['usd_max'] = floor(($out['cop_max'] / $trm_value) * 100) / 100;
  } else {
    $usd_min_raw = isset($input['usd_min']) ? sanitize_text_field($input['usd_min']) : '';
    $usd_min_clean = str_replace(',', '', $usd_min_raw);
    $out['usd_min'] = $usd_min_raw !== '' ? max(0, floatval($usd_min_clean)) : $d['usd_min'];

    $usd_max_raw = isset($input['usd_max']) ? sanitize_text_field($input['usd_max']) : '';
    $usd_max_clean = str_replace(',', '', $usd_max_raw);
    $out['usd_max'] = $usd_max_raw !== '' ? max(0, floatval($usd_max_clean)) : $d['usd_max'];
  }


  // Ensure min <= max
  if ($out['cop_min'] > $out['cop_max'])
    $out['cop_min'] = $out['cop_max'];
  if ($out['usd_min'] > $out['usd_max'])
    $out['usd_min'] = $out['usd_max'];

  return $out;
}

function epayco_scs_render_settings_page()
{
  if (!current_user_can('manage_options'))
    return; ?>
  <div class="wrap epayco-sc-admin-wrap">
    <h1>ePayco Smart Checkout</h1>
    <form method="post" action="options.php" style="margin-top:16px;">
      <?php
      settings_fields('epayco_scs_group');
      do_settings_sections('epayco-smart-checkout');
      submit_button('Guardar cambios');
      ?>
    </form>
  </div>
<?php }

/** Field renderers */

function epayco_scs_field_epayco_keys()
{
  $s = epayco_scs_get_settings();
  $key = esc_attr(EPAYCO_SCS_OPT_KEY);

  $hasPub = !empty($s['epayco_public_key']);
  $hasPriv = !empty($s['epayco_private_key']);
  $status = ($hasPub && $hasPriv) ? '✅ Configuradas' : '⚠️ Faltan credenciales';

  ?>
  <div style="max-width:560px;">
    <div style="margin-bottom:10px;"><strong><?php echo esc_html($status); ?></strong></div>

    <label style="display:block; margin:8px 0 6px;"><strong>Public Key</strong></label>
    <input type="text" class="regular-text" name="<?php echo $key; ?>[epayco_public_key]"
      value="<?php echo esc_attr($s['epayco_public_key']); ?>" placeholder="pk_test_..." autocomplete="off" />

    <label style="display:block; margin:10px 0 6px;"><strong>Private Key</strong></label>
    <input id="epayco_private_key" type="password" class="regular-text" name="<?php echo $key; ?>[epayco_private_key]"
      value="<?php echo esc_attr($s['epayco_private_key']); ?>" placeholder="sk_test_..." autocomplete="new-password" />

    <div style="margin-top:8px;">
      <button type="button" class="button"
        onclick="(function(){var i=document.getElementById('epayco_private_key'); if(!i) return; i.type = (i.type==='password'?'text':'password');})();">
        Mostrar / ocultar
      </button>
    </div>

    <p class="description" style="margin-top:10px;">
      La <strong>Private Key</strong> se usa solo en el servidor para crear la sesión. No se expone al navegador.
    </p>
  </div>
<?php }

function epayco_scs_field_mode()
{
  $s = epayco_scs_get_settings(); ?>
  <select name="<?php echo esc_attr(EPAYCO_SCS_OPT_KEY); ?>[mode]">
    <option value="onpage" <?php selected($s['mode'], 'onpage'); ?>>In-site (onpage)</option>
    <option value="standard" <?php selected($s['mode'], 'standard'); ?>>Externo (standard)</option>
  </select>
  </div>
<?php }


function epayco_scs_field_test()
{
  $s = epayco_scs_get_settings();
  $isTestMode = (int) $s['test'] === 1;
  $pub = $s['epayco_public_key'];
  $priv = $s['epayco_private_key'];

  // Detect mismatch: Production mode BUT using Test keys
  $isTestKey = (strpos($pub, 'pk_test_') === 0) || (strpos($priv, 'sk_test_') === 0);
  $showWarning = !$isTestMode && $isTestKey;
  ?>
  <label style="display:block;margin:4px 0;">
    <input type="radio" name="<?php echo esc_attr(EPAYCO_SCS_OPT_KEY); ?>[test]" value="1" <?php checked($isTestMode, true); ?> />
    Pruebas (test)
  </label>
  <label style="display:block;margin:4px 0;">
    <input type="radio" name="<?php echo esc_attr(EPAYCO_SCS_OPT_KEY); ?>[test]" value="0" <?php checked($isTestMode, false); ?> />
    Producción
  </label>

  <?php if ($showWarning): ?>
    <div
      style="background:#fff3cd; color:#856404; padding:8px 12px; border:1px solid #ffeeba; border-radius:4px; margin-top:8px; display:inline-block;">
      <strong>⚠️ Conflicto detectado:</strong> Estás en modo <strong>Producción</strong> pero tus llaves parecen ser de
      <strong>Pruebas</strong> (<code>pk_test_...</code>).
      <br>Si quieres hacer pruebas reales, cambia a modo <strong>Pruebas</strong> arriba.
      <br>Si quieres cobrar dinero real, usa tus llaves de <strong>Producción</strong> (<code>pk_client_...</code>).
    </div>
  <?php endif; ?>

  <p class="description">
    Define si las transacciones son reales (dinero real) o simuladas.
  </p>
<?php }



function epayco_scs_field_button()
{
  $s = epayco_scs_get_settings(); ?>
  <input type="text" class="regular-text" name="<?php echo esc_attr(EPAYCO_SCS_OPT_KEY); ?>[button_text]"
    value="<?php echo esc_attr($s['button_text']); ?>" />
<?php }


function epayco_scs_field_show_currency_selector()
{
  $s = epayco_scs_get_settings(); ?>
  <label>
    <input type="checkbox" name="<?php echo esc_attr(EPAYCO_SCS_OPT_KEY); ?>[show_currency_selector]" value="1" <?php checked((int) $s['show_currency_selector'], 1); ?> />
    Mostrar selector de divisa en el formulario
  </label>
  <p class="description">Si lo desactivas, el formulario usará la <strong>Divisa por defecto</strong> y ocultará el
    selector.</p>
<?php }

function epayco_scs_field_primary()
{
  $s = epayco_scs_get_settings(); ?>
  <input type="text" class="regular-text" name="<?php echo esc_attr(EPAYCO_SCS_OPT_KEY); ?>[primary_color]"
    value="<?php echo esc_attr($s['primary_color']); ?>" placeholder="#0d6efd" />
  <p class="description">Formato: #RRGGBB (ej: #35528C)</p>
<?php }

function epayco_scs_field_font_mode()
{
  $s = epayco_scs_get_settings();
  $key = esc_attr(EPAYCO_SCS_OPT_KEY);
  ?>
  <fieldset>
    <label style="display:block;margin:4px 0;">
      <input type="radio" name="<?php echo $key; ?>[font_mode]" value="google" <?php checked($s['font_mode'], 'google'); ?> />
      Google Fonts (selector)
    </label>

    <div style="margin:8px 0 12px; padding-left:18px;">
      <select name="<?php echo $key; ?>[google_font]">
        <?php foreach (['Poppins', 'Inter', 'Nunito', 'Roboto', 'Montserrat', 'Open Sans'] as $f): ?>
          <option value="<?php echo esc_attr($f); ?>" <?php selected($s['google_font'], $f); ?>><?php echo esc_html($f); ?>
          </option>
        <?php endforeach; ?>
      </select>
      <p class="description">Esto carga la fuente automáticamente desde Google Fonts.</p>
    </div>

    <label style="display:block;margin:4px 0;">
      <input type="radio" name="<?php echo $key; ?>[font_mode]" value="custom" <?php checked($s['font_mode'], 'custom'); ?> />
      Personalizada (tu link)
    </label>

    <div style="margin:8px 0 0; padding-left:18px;">
      <p style="margin:6px 0;">URL CSS (ej: link de Google Fonts o tu CDN):</p>
      <input type="url" class="regular-text" name="<?php echo $key; ?>[custom_font_css_url]"
        value="<?php echo esc_attr($s['custom_font_css_url']); ?>"
        placeholder="https://fonts.googleapis.com/css2?family=..." />
      <p style="margin:8px 0 6px;">Font family (ej: Poppins, Inter):</p>
      <input type="text" class="regular-text" name="<?php echo $key; ?>[custom_font_name]"
        value="<?php echo esc_attr($s['custom_font_name']); ?>" placeholder="MiFuente" />
      <p class="description">Buena práctica: pega el link oficial y el nombre exacto del font-family.</p>
    </div>
  </fieldset>
<?php }


function epayco_scs_field_auto_usd_limits()
{
  $s = epayco_scs_get_settings(); ?>
  <label>
    <input type="checkbox" name="<?php echo esc_attr(EPAYCO_SCS_OPT_KEY); ?>[auto_usd_limits]" value="1" <?php checked((int) $s['auto_usd_limits'], 1); ?> />
    Sincronizar USD automáticamente desde COP usando la TRM del día
  </label>
  <div class="epayco-sc-inline" style="margin-top:8px;">
    <span id="epayco-usd-pill" class="epayco-sc-pill"></span>
    <span id="epayco-usd-hint" class="epayco-sc-admin-small"></span>
  </div>
  <p class="description">Tip: si desactivas el automático, puedes editar USD al vuelo (sin recargar).</p>
<?php }

function epayco_scs_field_currency()
{
  $s = epayco_scs_get_settings(); ?>
  <select name="<?php echo esc_attr(EPAYCO_SCS_OPT_KEY); ?>[default_currency]">
    <option value="COP" <?php selected($s['default_currency'], 'COP'); ?>>COP</option>
    <option value="USD" <?php selected($s['default_currency'], 'USD'); ?>>USD</option>
  </select>
<?php }

function epayco_scs_field_show_currency()
{
  $s = epayco_scs_get_settings(); ?>
  <label>
    <input type="checkbox" name="<?php echo esc_attr(EPAYCO_SCS_OPT_KEY); ?>[show_currency]" value="1" <?php checked((int) $s['show_currency'], 1); ?> />
    Mostrar selector en el formulario
  </label>
<?php }

function epayco_scs_field_limits()
{
  $s = epayco_scs_get_settings();
  $key = esc_attr(EPAYCO_SCS_OPT_KEY);
  ?>
  <div class="epayco-sc-limits-container" style="max-width: 560px; background: #f8fafc; border: 1px solid #e2e8f0; padding: 15px; border-radius: 8px;">
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 12px;">
      <div>
        <span style="font-weight: 600; color: #2b3990; display: block; margin-bottom: 6px; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px;">Pesos Colombianos (COP)</span>
        <div style="margin-bottom: 8px;">
          <label style="font-size: 12px; font-weight: 500; color: #64748b;">Monto Mínimo</label>
          <input type="text" class="regular-text epayco-sc-amount-input" data-currency="COP" name="<?php echo $key; ?>[cop_min]"
            value="<?php echo number_format((float)$s['cop_min'], 0, '', '.'); ?>" style="margin-top: 4px;" />
        </div>
        <div>
          <label style="font-size: 12px; font-weight: 500; color: #64748b;">Monto Máximo</label>
          <input type="text" class="regular-text epayco-sc-amount-input" data-currency="COP" name="<?php echo $key; ?>[cop_max]"
            value="<?php echo number_format((float)$s['cop_max'], 0, '', '.'); ?>" style="margin-top: 4px;" />
        </div>
      </div>
      <div>
        <span style="font-weight: 600; color: #2b3990; display: block; margin-bottom: 6px; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px;">Dólares Americanos (USD)</span>
        <div style="margin-bottom: 8px;">
          <label style="font-size: 12px; font-weight: 500; color: #64748b;">Monto Mínimo</label>
          <?php $usdDisabled = ((int) $s['auto_usd_limits'] === 1) ? 'disabled' : ''; ?>
          <input type="text" class="regular-text epayco-sc-amount-input" data-currency="USD" name="<?php echo $key; ?>[usd_min]"
            value="<?php echo number_format((float)$s['usd_min'], 2, '.', ','); ?>" <?php echo $usdDisabled; ?> style="margin-top: 4px;" />
        </div>
        <div>
          <label style="font-size: 12px; font-weight: 500; color: #64748b;">Monto Máximo</label>
          <input type="text" class="regular-text epayco-sc-amount-input" data-currency="USD" name="<?php echo $key; ?>[usd_max]"
            value="<?php echo number_format((float)$s['usd_max'], 2, '.', ','); ?>" <?php echo $usdDisabled; ?> style="margin-top: 4px;" />
        </div>
      </div>
    </div>
    <p class="description" style="margin: 0; padding-top: 8px; border-top: 1px solid #e2e8f0;">
      COP siempre editable. USD se calcula automáticamente desde COP usando la TRM del día si la opción "USD (automático)" está activa.
    </p>
  </div>
<?php }

/** Front assets (enqueue from shortcode to work with Elementor/caches) */
function epayco_scs_enqueue_front_assets()
{
  static $done = false;
  if ($done)
    return;
  $done = true;

  $s = epayco_scs_get_settings();

  // Fonts
  if ($s['font_mode'] === 'google') {
    $map = [
      'Poppins' => 'Poppins:wght@300;400;500;600;700',
      'Inter' => 'Inter:wght@300;400;500;600;700',
      'Nunito' => 'Nunito:wght@300;400;500;600;700',
      'Roboto' => 'Roboto:wght@300;400;500;700',
      'Montserrat' => 'Montserrat:wght@300;400;500;600;700',
      'Open Sans' => 'Open+Sans:wght@300;400;500;600;700',
    ];
    $gf = $s['google_font'];
    $q = $map[$gf] ?? $map['Poppins'];
    wp_enqueue_style('epayco-sc-font', 'https://fonts.googleapis.com/css2?family=' . $q . '&display=swap', [], null);
  } else {
    if (!empty($s['custom_font_css_url'])) {
      wp_enqueue_style('epayco-sc-font-custom', $s['custom_font_css_url'], [], null);
    }
  }

  wp_enqueue_style('epayco-sc', plugins_url('assets/epayco-sc.css', __FILE__), [], '3.8.2');

  // ePayco checkout library (load early for guest caches/optimizers)
  wp_enqueue_script('epayco-checkout-v2', 'https://checkout.epayco.co/checkout-v2.js', [], null, false);

  // Our script (keeps a fallback loader, but prefers the enqueued library)
  wp_enqueue_script('epayco-sc', plugins_url('assets/epayco-sc.js', __FILE__), ['epayco-checkout-v2'], '3.8.2', true);

  $trm = epayco_scs_get_trm_today();
  wp_localize_script('epayco-sc', 'EPAYCO_SC', [
    'ajaxUrl' => admin_url('admin-ajax.php'),
    'nonce' => wp_create_nonce('epayco_scs_nonce'),
    'mode' => $s['mode'],
    'test' => ((int) $s['test'] === 1) ? '1' : '0',
    'publicKey' => $s['epayco_public_key'],
    'defaultCurrency' => $s['default_currency'],
    'showCurrencySelector' => (int) $s['show_currency'] === 1,

    // Mobile forced standard (not configurable)
    'mobileForceStandard' => true,

    'trm' => (float) (($trm['trm'] ?? 4000) > 0 ? $trm['trm'] : 4000),
    'limits' => [
      'COP' => ['min' => (float) $s['cop_min'], 'max' => (float) $s['cop_max']],
      'USD' => ['min' => (float) $s['usd_min'], 'max' => (float) $s['usd_max']],
    ],
    'autoUsdLimits' => (int) $s['auto_usd_limits'] === 1,
  ]);
}

function epayco_scs_field_payment_name()
{
  $s = epayco_scs_get_settings(); ?>
  <input type="text" class="regular-text" name="<?php echo esc_attr(EPAYCO_SCS_OPT_KEY); ?>[payment_name]"
    value="<?php echo esc_attr($s['payment_name'] ?? 'Pago en WordPress'); ?>" />
  <p class="description">Se envía a ePayco como <code>name</code>.</p>
<?php }

function epayco_scs_field_payment_description()
{
  $s = epayco_scs_get_settings(); ?>
  <input type="text" class="regular-text" name="<?php echo esc_attr(EPAYCO_SCS_OPT_KEY); ?>[payment_description]"
    value="<?php echo esc_attr($s['payment_description'] ?? 'Pago en {site_name}'); ?>" />
  <p class="description">
    Se envía a ePayco como <code>description</code>. Variables: <code>{site_name}</code>, <code>{amount}</code>,
    <code>{currency}</code>, <code>{date}</code>, <code>{page_title}</code>.
  </p>
<?php }

function epayco_scs_field_url_response()
{
  $s = epayco_scs_get_settings(); ?>
  <input type="url" class="regular-text" name="<?php echo esc_attr(EPAYCO_SCS_OPT_KEY); ?>[url_response]"
    value="<?php echo esc_attr($s['url_response'] ?? ''); ?>" placeholder="https://..." />
  <p class="description">
    Opcional. URL a la que se redirige al usuario después del pago. <br>
    Si se deja vacío, se usará la misma página donde está el botón.
  </p>
<?php }

function epayco_scs_field_auto_usd_limits_forced()
{
  $trm = epayco_scs_get_trm_today();
  $trmText = number_format(floatval(($trm['trm'] ?? 0) > 0 ? $trm['trm'] : 4000), 2, ',', '.');
  ?>
  <div class="epayco-sc-inline" style="margin-top:4px;">
    <span class="epayco-sc-pill epayco-sc-badge-ok">Siempre automático</span>
    <span class="epayco-sc-admin-small">USD se calcula desde COP usando la TRM del día
      (<?php echo esc_html($trmText); ?>).</span>
  </div>
  <p class="description" style="margin-top:8px;">COP siempre editable. USD queda bloqueado y solo se muestra como
    referencia.</p>
<?php }



/** Shortcode markup */
add_shortcode('epayco_smart_checkout', function () {
  epayco_scs_enqueue_front_assets();
  $s = epayco_scs_get_settings();

  $fontName = ($s['font_mode'] === 'google') ? $s['google_font'] : ($s['custom_font_name'] ?: 'Poppins');
  $vars = sprintf(
    '--epayco-sc-primary:%s;--epayco-sc-font:%s;',
    esc_attr($s['primary_color']),
    esc_attr("'" . $fontName . "', system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif")
  );

  ob_start(); ?>
  <div class="epayco-sc-wrap" style="<?php echo $vars; ?>">
    <form class="epayco-sc-form" data-epayco-sc="1" method="post" action="" novalidate>
      <div class="epayco-sc-center">

        <div class="epayco-sc-row">
          <label class="epayco-sc-label">Monto</label>
          <input type="text" class="epayco-sc-control" name="amount" placeholder="Monto" inputmode="decimal"
            autocomplete="off" pattern="^[0-9.,]+$" required>
          <div class="epayco-sc-range-hint"></div>
        </div>

        <?php if ((int) $s['show_currency'] === 1): ?>
          <div class="epayco-sc-row">
            <label class="epayco-sc-label">Divisa</label>
            <select class="epayco-sc-control" name="currency" required>
              <option value="COP" <?php selected($s['default_currency'], 'COP'); ?>>COP</option>
              <option value="USD" <?php selected($s['default_currency'], 'USD'); ?>>USD</option>
            </select>
          </div>
        <?php else: ?>
          <input type="hidden" name="currency" value="<?php echo esc_attr($s['default_currency']); ?>">
        <?php endif; ?>

        <button type="button" class="epayco-sc-btn"
          data-epayco-sc-pay="1"><?php echo esc_html($s['button_text']); ?></button>
        <div class="epayco-sc-msg" aria-live="polite"></div>

      </div>
    </form>
  </div>
  <?php
  return ob_get_clean();
});


/** Render description template */
function epayco_scs_render_payment_description($settings, $amount, $currency, $page_title = '')
{
  $tpl = isset($settings['payment_description']) && $settings['payment_description'] !== '' ? (string) $settings['payment_description'] : 'Pago en {site_name}';
  $repl = [
    '{site_name}' => get_bloginfo('name'),
    '{amount}' => (string) $amount,
    '{currency}' => (string) $currency,
    '{date}' => function_exists('wp_date') ? wp_date('Y-m-d') : date_i18n('Y-m-d'),
    '{page_title}' => (string) $page_title,
  ];
  return strtr($tpl, $repl);
}

/** Helper: Apify token */
function epayco_scs_get_token($public, $private)
{
  $basic = base64_encode($public . ':' . $private);

  $res = wp_remote_post('https://apify.epayco.co/login', [
    'headers' => [
      'Content-Type' => 'application/json',
      'Authorization' => 'Basic ' . $basic,
    ],
    'timeout' => 20,
  ]);

  if (is_wp_error($res))
    return $res;

  $code = wp_remote_retrieve_response_code($res);
  $body = wp_remote_retrieve_body($res);

  if ($code < 200 || $code >= 300)
    return new WP_Error('epayco_login_failed', $body ?: 'Login failed');

  $json = json_decode($body, true);
  $token = $json['token'] ?? ($json['data']['token'] ?? null);
  if (!$token)
    return new WP_Error('epayco_no_token', 'No token returned');
  return $token;
}

function epayco_scs_create_session($token, $payload)
{
  $res = wp_remote_post('https://apify.epayco.co/payment/session/create', [
    'headers' => [
      'Content-Type' => 'application/json',
      'Authorization' => 'Bearer ' . $token,
    ],
    'body' => wp_json_encode($payload),
    'timeout' => 20,
  ]);

  if (is_wp_error($res))
    return $res;

  $code = wp_remote_retrieve_response_code($res);
  $body = wp_remote_retrieve_body($res);

  if ($code < 200 || $code >= 300)
    return new WP_Error('epayco_session_failed', $body ?: 'Session create failed');

  $json = json_decode($body, true);
  $sessionId = $json['data']['sessionId'] ?? null;
  if (!$sessionId)
    return new WP_Error('epayco_no_session', 'No sessionId returned');

  return $sessionId;
}

/** AJAX endpoint */
add_action('wp_ajax_nopriv_epayco_create_session', 'epayco_scs_ajax_create_session');
add_action('wp_ajax_epayco_create_session', 'epayco_scs_ajax_create_session');

function epayco_scs_ajax_create_session()
{
  $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
  if (!wp_verify_nonce($nonce, 'epayco_scs_nonce')) {
    wp_send_json_error(['message' => 'Nonce inválido o expirado. Recarga la página e intenta de nuevo.'], 403);
  }

  $post_amount = isset($_POST['amount']) ? str_replace(',', '.', sanitize_text_field($_POST['amount'])) : '0';
  $clean_amount_str = preg_replace('/[^0-9.]/', '', $post_amount);
  $amount = floatval($clean_amount_str);
  $currency = isset($_POST['currency']) ? strtoupper(sanitize_text_field($_POST['currency'])) : 'COP';

  if ($amount <= 0)
    wp_send_json_error(['message' => 'Monto inválido'], 400);

  $s = epayco_scs_get_settings();

  $public = $s['epayco_public_key'] ?? '';
  $private = $s['epayco_private_key'] ?? '';
  if (!$public || !$private)
    wp_send_json_error(['message' => 'Faltan credenciales ePayco. Ve a Settings → ePayco Smart Checkout y configúralas.'], 500);
  $token = epayco_scs_get_token($public, $private);
  if (is_wp_error($token))
    wp_send_json_error(['message' => $token->get_error_message()], 502);

  $payload = [
    'checkout_version' => '2',
    'name' => !empty($s['payment_name']) ? $s['payment_name'] : 'Pago en WordPress',
    'description' => epayco_scs_render_payment_description($s, $amount, $currency, isset($_POST['page_title']) ? sanitize_text_field($_POST['page_title']) : ''),
    'currency' => in_array($currency, ['COP', 'USD'], true) ? $currency : 'COP',
    'amount' => $amount,
    'response' => !empty($s['url_response']) ? $s['url_response'] : (isset($_POST['url_response']) ? esc_url_raw($_POST['url_response']) : home_url()),
    'confirmation' => home_url('/?epayco_confirmation=1'), // Webhook placeholder
    'test' => (string) $s['test'] === '1' ? true : false, // EXPLICITLY set test mode
  ];

  $sessionId = epayco_scs_create_session($token, $payload);
  if (is_wp_error($sessionId))
    wp_send_json_error(['message' => $sessionId->get_error_message()], 502);

  wp_send_json_success(['sessionId' => $sessionId]);
}

/** AJAX endpoint to refresh TRM */
add_action('wp_ajax_epayco_refresh_trm', 'epayco_scs_ajax_refresh_trm');

function epayco_scs_ajax_refresh_trm()
{
  if (!current_user_can('manage_options')) {
    wp_send_json_error(['message' => 'No autorizado'], 403);
  }

  delete_transient(EPAYCO_SCS_TRM_TRANSIENT);
  $trm = epayco_scs_get_trm_today();

  wp_send_json_success([
    'trm' => $trm['trm'],
    'vigenciadesde' => $trm['vigenciadesde'],
    'fetched_at' => $trm['fetched_at']
  ]);
}
